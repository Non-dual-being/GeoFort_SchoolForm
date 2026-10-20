<?php
declare(strict_types=1);

require __DIR__ . '/Support/RosterLifecycleFixtures.php';

use GeoFort\Services\Dashboard\Roster\{RosterAutoGenerator, RosterPairedLessonSearch, RosterPlanException};
use GeoFort\Services\Sql\RosterPairSqlRepository;

$assert = RosterLifecycleFixtures::assert(...);
$db = DisposableBookingMariaDb::create('standard_lesson');
try {
    RosterLifecycleFixtures::schema($db->pdo);
    $s = RosterLifecycleFixtures::services($db->pdo);
    $generator = new RosterAutoGenerator($db->pdo, $s['bookings'], $s['plans'], $s['sessions'], $s['config'], $s['templates']);
    $make = function (array $modules, int $groups, string $date, string $program = 'dag', ?string $version = null) use ($s, $db): array {
        $p = $s['service']->createManual('Normale lesregels', 'Testplaats', $date, 'primairOnderwijs', $program, $groups * 15, $groups, 1)['plan'];
        $s['plans']->saveManualModuleKeys($p['id'], $modules);
        if ($version !== null) $db->pdo->prepare('UPDATE roster_manual_plan_context SET program_version=? WHERE roster_plan_id=?')->execute([$version, $p['id']]);
        return $s['service']->getPlan($p['id']);
    };
    $check = function (array $p, array $modules, string $program = 'dag') use ($generator, $s, $assert): array {
        $before = serialize($s['raw']->load($p['id']));
        $rounds = $s['templates']->defaultRounds($program, count($modules));
        $answer = $generator->preview($p['id'], $rounds, [], 'lesson_only');
        $assert($before === serialize($s['raw']->load($p['id'])), 'Preview changed stored data');
        $assert($answer['hardIssues'] === [] && $answer['canApply'], 'Standard candidate has hard conflicts');
        $assert(!in_array('PARALLEL_LOCATION_REVIEW', array_column($answer['warnings'], 'code'), true), 'Normal double capacity should not warn');
        $assert((new RosterPairedLessonSearch($s['config'], true))->conflicts($answer['sessions'], []) === [], 'Standard capacity exceeded');
        foreach ($p['groups'] as $group) {
            $coverage = [];
            foreach ($answer['sessions'] as $session) {
                $assert($session['assignedStaffIds'] === [], 'Lesson-only selected personnel');
                if (in_array($group['id'], $session['groupIds'], true)) $coverage[] = $session['moduleKey'];
            }
            sort($coverage); $expected = $modules; sort($expected);
            $assert($coverage === $expected, 'Every group must visit every module exactly once');
        }
        return $answer;
    };
    $day = ['Voedsel-Innovatie', 'Dynamische-Globe', 'Klimaat-Experience', 'Earth-Watch', 'Minecraft-Klimaatspeurtocht'];
    foreach ([2, 3, 4, 5] as $groups) {
        $p = $make($day, $groups, (new DateTimeImmutable('2028-11-06'))->modify('+'.($groups * 7).' days')->format('Y-m-d'));
        $answer = $check($p, $day);
        $assert($answer['diagnostics']['compressionLevel'] === 1, 'Unexpected compression');
        foreach (['Earth-Watch', 'Minecraft-Klimaatspeurtocht'] as $module) {
            $counts = [];
            foreach ($answer['sessions'] as $session) if ($session['moduleKey'] === $module) {
                $counts[$session['startTime']] = ($counts[$session['startTime']] ?? 0) + count($session['groupIds']);
            }
            $assert(max($counts) === 1, 'Separate cells bypassed normal capacity');
        }
        $generator->apply($p['id'], 1, $s['templates']->defaultRounds('dag'), false, 1, [], 'lesson_only');
        $assert(count($s['raw']->load($p['id'])['sessions']) === count($answer['sessions']), 'Apply differs from preview');
    }
    $four = ['Voedsel-Innovatie', 'Dynamische-Globe', 'Klimaat-Experience', 'Stop-de-Klimaat-Klok'];
    $check($make($four, 4, '2028-11-10', 'dag', 'day-four-2027'), $four);
    $morning = ['Zandtafel', 'Rising-Risk', 'Dynamische-Globe', 'Dynamische-Globe-Bios', 'Expedition-Earth'];
    $check($make($morning, 5, '2028-11-15', 'ochtend'), $morning, 'ochtend');

    // A shortage is a clear error, including apply; no invalid emergency draft is saved.
    $blocked = $make($day, 6, '2028-11-16');
    $before = serialize($s['raw']->load($blocked['id']));
    foreach ([false, true] as $apply) {
        try {
            if ($apply) $generator->apply($blocked['id'], 1, $s['templates']->defaultRounds('dag'), false, 1, [], 'lesson_only');
            else $generator->preview($blocked['id'], $s['templates']->defaultRounds('dag'), [], 'lesson_only');
            throw new RuntimeException('Exceptional double lessons accepted silently');
        } catch (RosterPlanException $e) {
            $assert($e->publicCode === 'GENERATION_NO_STANDARD_LESSON_SCHEDULE', 'Wrong capacity error');
        }
        $assert(!$db->pdo->inTransaction() && $before === serialize($s['raw']->load($blocked['id'])), 'Rejected generation changed data');
    }
    // Both schools together count against normal capacity, even for a partial overlap.
    $pair = [];
    foreach ([1, 2] as $index) $pair[] = $make(['Earth-Watch', 'Stop-de-Klimaat-Klok'], 1, '2028-11-20');
    $db->pdo->beginTransaction();
    (new RosterPairSqlRepository($db->pdo))->create(array_column($pair, 'id'), '2028-11-20', 'lessons_first', 1);
    $db->pdo->commit();
    $rounds = $s['templates']->defaultRounds('dag', 2);
    $generator->apply($pair[0]['id'], 1, $rounds, false, 1, [], 'lesson_only');
    $fixed = serialize($s['raw']->load($pair[0]['id']));
    $answer = $generator->preview($pair[1]['id'], $rounds, [], 'lesson_only');
    $assert((new RosterPairedLessonSearch($s['config'], true))->conflicts($answer['sessions'], $answer['companion']['occupancy']) === [], 'Pair bypassed normal capacity');
    $generator->apply($pair[1]['id'], 1, $rounds, false, 1, [], 'lesson_only');
    $assert($fixed === serialize($s['raw']->load($pair[0]['id'])), 'Companion was modified');
    $shifted = array_map(static fn(array $r): array => [...$r,
        'startTime' => (new DateTimeImmutable($r['startTime']))->modify('+10 minutes')->format('H:i'),
        'endTime' => (new DateTimeImmutable($r['endTime']))->modify('+10 minutes')->format('H:i'),
    ], $rounds);
    try {
        $generator->preview($pair[1]['id'], $shifted, [], 'lesson_only');
        throw new RuntimeException('Partial overlap used exceptional capacity');
    } catch (RosterPlanException $e) {
        $assert($e->publicCode === 'GENERATION_NO_STANDARD_LESSON_SCHEDULE', 'Wrong partial overlap error');
    }

    // Resource ranking must improve on the former first candidate: two VI groups share a guide and cook.
    $economy = $make(['Voedsel-Innovatie', 'Dynamische-Globe', 'Klimaat-Experience'], 4, '2028-11-23');
    $answer = $check($economy, ['Voedsel-Innovatie', 'Dynamische-Globe', 'Klimaat-Experience']);
    $food = array_values(array_filter($answer['sessions'], static fn(array $r): bool => $r['moduleKey'] === 'Voedsel-Innovatie'));
    $assert(count($food) === 2 && count($food[0]['groupIds']) === 2 && count($food[1]['groupIds']) === 2, 'Wasted an extra VI guide/cook round');
    // Manual/staffed validation keeps its existing exception limits.
    foreach (['Earth-Watch', 'Minecraft-Klimaatspeurtocht', 'Minecraft-Programmeren'] as $module) {
        $assert($s['config']->maxParallel($module) === 2 && $s['config']->normalMaxParallel($module) === 1, 'Manual exception policy changed');
    }
    echo "PASS: standard 2/3/4/5-group proposals, four-module and morning programs, economical VI, pair overlap, save/preview consistency and blocked fallback without mutations\n";
} finally {
    $db->drop();
}

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
    foreach ([2, 3, 4, 5, 6, 8, 10] as $groups) {
        $p = $make($day, $groups, (new DateTimeImmutable('2028-11-06'))->modify('+'.($groups * 7).' days')->format('Y-m-d'));
        $answer = $check($p, $day);
        $assert($answer['diagnostics']['compressionLevel'] === 1, 'Unexpected compression');
        foreach (['Earth-Watch', 'Minecraft-Klimaatspeurtocht'] as $module) {
            $counts = [];
            foreach ($answer['sessions'] as $session) if ($session['moduleKey'] === $module) {
                $assert(count($session['groupIds']) === 1 && $session['minimumGeoFortStaff'] === 1, 'Parallel lessons must each retain their own guide');
                $counts[$session['startTime']] = ($counts[$session['startTime']] ?? 0) + count($session['groupIds']);
            }
            $assert(max($counts) === ($groups <= 5 ? 1 : 2), 'Separate-guide lessons not spread when possible');
        }
        $generator->apply($p['id'], 1, $s['templates']->defaultRounds('dag'), false, 1, [], 'lesson_only');
        $assert(count($s['raw']->load($p['id'])['sessions']) === count($answer['sessions']), 'Apply differs from preview');
    }
    $four = ['Voedsel-Innovatie', 'Dynamische-Globe', 'Klimaat-Experience', 'Stop-de-Klimaat-Klok'];
    $check($make($four, 4, '2028-11-10', 'dag', 'day-four-2027'), $four);
    $morning = ['Zandtafel', 'Rising-Risk', 'Dynamische-Globe', 'Dynamische-Globe-Bios', 'Expedition-Earth'];
    $check($make($morning, 5, '2028-11-15', 'ochtend'), $morning, 'ochtend');

    // A shortage is a clear error, including apply; no invalid emergency draft is saved.
    $blocked = $make($morning, 6, '2028-11-22', 'ochtend');
    $before = serialize($s['raw']->load($blocked['id']));
    foreach ([false, true] as $apply) {
        try {
            if ($apply) $generator->apply($blocked['id'], 1, $s['templates']->defaultRounds('ochtend'), false, 1, [], 'lesson_only');
            else $generator->preview($blocked['id'], $s['templates']->defaultRounds('ochtend'), [], 'lesson_only');
            throw new RuntimeException('Morning station capacity exceeded');
        } catch (RosterPlanException $e) {
            $assert($e->publicCode === 'GENERATION_NO_STANDARD_LESSON_SCHEDULE', 'Wrong capacity error');
        }
        $assert(!$db->pdo->inTransaction() && $before === serialize($s['raw']->load($blocked['id'])), 'Rejected generation changed data');
    }
    // Both schools together count against normal capacity, even for a partial overlap.
    $pair = [];
    foreach ([1, 2] as $index) $pair[] = $make(['Earth-Watch', 'Stop-de-Klimaat-Klok'], 2, '2028-11-20');
    $db->pdo->beginTransaction();
    (new RosterPairSqlRepository($db->pdo))->create(array_column($pair, 'id'), '2028-11-20', 'lessons_first', 1);
    $db->pdo->commit();
    $rounds = $s['templates']->defaultRounds('dag', 2);
    foreach (['Earth-Watch', 'Stop-de-Klimaat-Klok'] as $index => $module) {
        foreach ($pair[0]['groups'] as $group) {
            $sid = $s['sessions']->insertActivity($pair[0]['id'], $module, $rounds[$index]['startTime'], $rounds[$index]['endTime'], null, 1);
            $s['sessions']->replaceGroups($sid, [$group['id']]);
        }
    }
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
        $assert($s['config']->maxParallel($module) === 2 && $s['config']->normalMaxParallel($module) === 2, 'Physical capacity changed');
        $assert($s['config']->preferredLessonParallel($module) === 1 && $s['config']->effectiveMaxGroupsForCompressionLevel($module, 1) === 1, 'Exceptional shared guide introduced');
    }
    // Avicenna-like case: 160 pupils, ten groups, five rounds, explicit GeoFort supervision.
    $large = $s['service']->createManual('Tien groepen GeoFort-begeleiding', 'Testplaats', '2026-11-30', 'voortgezetOnderbouw', 'dag', 160, 10, 1)['plan'];
    $keys = ['Voedsel-Innovatie', 'Dynamische-Globe', 'Klimaat-Experience', 'Earth-Watch', 'Stop-de-Klimaat-Klok'];
    $s['plans']->saveManualModuleKeys($large['id'], $keys);
    $large = $s['service']->getPlan($large['id']);
    $prefs = ['moduleOrder' => $keys, 'supervision' => ['Klimaat-Experience' => 'geofort']];
    $before = serialize($s['raw']->load($large['id']));
    $answer = $generator->preview($large['id'], $s['templates']->defaultRounds('dag'), [], 'lesson_only', lessonPreferences: $prefs);
    $assert($answer['hardIssues'] === [] && $answer['canApply'] && !$answer['canFinalize'], 'Valid lessons confused with finalized staffing');
    $assert($before === serialize($s['raw']->load($large['id'])), 'Large preview changed data');
    $assert(array_sum(array_map(static fn(array $r): int => count($r['groupIds']), $answer['sessions'])) === 50, 'Incomplete ten-group programme');
    foreach ($answer['sessions'] as $session) {
        $assert($session['assignedStaffIds'] === [] && !$session['schoolFallback'], 'GeoFort supervision changed');
        if (in_array($session['moduleKey'], ['Earth-Watch', 'Stop-de-Klimaat-Klok'], true)) {
            $assert(count($session['groupIds']) === 1 && $session['minimumGeoFortStaff'] === 1, 'Parallel lessons share a guide');
        }
        if ($session['moduleKey'] === 'Klimaat-Experience') $assert($session['minimumGeoFortStaff'] === 1, 'KE GeoFort role lost');
    }
    $generator->apply($large['id'], 1, $s['templates']->defaultRounds('dag'), false, 1, [], 'lesson_only', lessonPreferences: $prefs);
    $saved = $s['service']->getPlan($large['id']);
    $assert($saved['planning']['lessonPreferences'] === $prefs, 'GeoFort preference lost on save');
    $validation = $s['validator']->validate($large['id'], $saved['revision']);
    $assert(in_array('OPEN_SUPERVISION', array_column($validation->errors, 'code'), true), 'Missing guides must still block finalization');
    try {
        $s['lifecycle']->finalize($large['id'], $saved['revision'], 1);
        throw new RuntimeException('Finalized without guides');
    } catch (RosterPlanException $e) {
        $assert($s['service']->getPlan($large['id'])['status'] === 'concept', 'Failed finalization changed status');
    }
    echo "PASS: 2/3/4/5/6/8/10-group proposals, Avicenna-like GeoFort case, separate guide roles, four-module and morning programs, economical VI, pair overlap, preview immutability and finalization guard\n";
} finally {
    $db->drop();
}

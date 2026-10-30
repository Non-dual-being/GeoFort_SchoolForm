<?php
declare(strict_types=1);

require __DIR__ . '/Support/RosterLifecycleFixtures.php';

use GeoFort\Services\Dashboard\Roster\{RosterAutoGenerator, RosterLessonPreferences, RosterPairedLessonSearch, RosterPlanException};
use GeoFort\Services\Sql\{RosterLessonPreferencesSqlRepository, RosterPairSqlRepository};

$assert = RosterLifecycleFixtures::assert(...);
$db = DisposableBookingMariaDb::create('lesson_preferences');
try {
    RosterLifecycleFixtures::schema($db->pdo);
    $s = RosterLifecycleFixtures::services($db->pdo);
    $generator = new RosterAutoGenerator($db->pdo, $s['bookings'], $s['plans'], $s['sessions'], $s['config'], $s['templates'], $s['staff']);
    $repository = new RosterLessonPreferencesSqlRepository($db->pdo);
    $date = new DateTimeImmutable('2028-11-01');
    $make = function (array $modules, int $groups = 2, ?string $version = null, string $program = 'dag') use ($s, $db, &$date): array {
        $date = $date->modify('+7 days');
        $p = $s['service']->createManual('Voorkeurentest', 'Testplaats', $date->format('Y-m-d'), 'primairOnderwijs', $program, $groups * 15, $groups, 1)['plan'];
        $s['plans']->saveManualModuleKeys($p['id'], $modules);
        if ($version !== null) $db->pdo->prepare('UPDATE roster_manual_plan_context SET program_version=? WHERE roster_plan_id=?')->execute([$version, $p['id']]);
        return $s['service']->getPlan($p['id']);
    };
    $preferences = static function (?string $early, string $ke = 'geofort', string $vs = 'school'): array {
        $keys = ['Dynamische-Globe', 'Klimaat-Experience', 'Klimparcours', 'Earth-Watch'];
        return [
            'moduleOrder' => $early === null ? $keys : [$early, ...array_values(array_diff($keys, [$early]))],
            'supervision' => ['Klimaat-Experience' => $ke, 'Klimparcours' => $vs],
        ];
    };
    $preview = static fn(array $p, array $choice): array => $generator->preview(
        $p['id'], $p['planning']['generation']['defaultRounds'], [], 'lesson_only', lessonPreferences: $choice,
    );
    $apply = static fn(array $p, array $choice): array => $generator->apply(
        $p['id'], $p['revision'], $p['planning']['generation']['defaultRounds'], true, 1, [], 'lesson_only', lessonPreferences: $choice,
    );
    $sessionsFor = static fn(array $proposal, string $key): array => array_values(array_filter($proposal['sessions'], static fn(array $session): bool => $session['moduleKey'] === $key));

    foreach ([null, 'day-four-2027'] as $version) {
        $p = $make(['Dynamische-Globe', 'Klimaat-Experience', 'Klimparcours', 'Earth-Watch'], 2, $version);
        $before = serialize($s['raw']->load($p['id']));
        $choice = $preferences('Klimaat-Experience');
        $ke = $preview($p, $choice);
        $vs = $preview($p, $preferences('Klimparcours', 'school', 'geofort'));
        $first = $ke['rounds'][0]['startTime'];
        $assert($sessionsFor($ke, 'Klimaat-Experience')[0]['startTime'] === $first, 'KE preference not early');
        $assert($sessionsFor($vs, 'Klimparcours')[0]['startTime'] === $first, 'VS preference not early');
        $assert($ke['sessions'] !== $vs['sessions'], 'Preferences did not change proposal');
        $assert($before === serialize($s['raw']->load($p['id'])) && $repository->forPlan($p['id']) === null, 'Preview wrote data');
        foreach ([$ke, $vs] as $answer) {
            $assert((new RosterPairedLessonSearch($s['config'], true))->conflicts($answer['sessions'], []) === [], 'Normal capacity broken');
            foreach ($answer['sessions'] as $session) {
                $assert($session['assignedStaffIds'] === [], 'Lesson-only assigned people');
                if (!isset($answer['lessonPreferences']['supervision'][$session['moduleKey']])) continue;
                $school = $answer['lessonPreferences']['supervision'][$session['moduleKey']] === 'school';
                $assert($session['schoolFallback'] === $school, 'Explicit supervision lost in preview');
                $assert($session['minimumGeoFortStaff'] === ($school ? 0 : 1), 'Wrong guide requirement');
            }
        }
        $apply($p, $choice);
        $saved = $s['service']->getPlan($p['id']);
        $assert(array_diff_key($saved['planning']['lessonPreferences'], ['allowLateVi' => true]) === $choice, 'Choices lost on reload');
        $assert(array_diff_key($repository->forPlan($p['id']), ['allowLateVi' => true]) === $choice, 'Choices not stored');
        foreach ($saved['planning']['sessions'] as $session) {
            if (!isset($choice['supervision'][$session['moduleKey']])) continue;
            $kind = $choice['supervision'][$session['moduleKey']] === 'school' ? 'school' : 'open';
            $assert(array_unique(array_column($session['groupSupervision'], 'kind')) === [$kind], 'Saved group supervision differs from preview');
        }
        $validation = $s['validator']->validate($p['id'], $saved['revision']);
        $keIds = array_column(array_filter($saved['planning']['sessions'], static fn(array $session): bool => $session['moduleKey'] === 'Klimaat-Experience'), 'id');
        $assert(count(array_filter($validation->errors, static fn(array $e): bool => $e['code'] === 'OPEN_SUPERVISION' && in_array($e['sessionId'] ?? null, $keIds, true))) > 0, 'Unfilled GeoFort roles must block finalization');
        try {
            $s['lifecycle']->finalize($p['id'], $saved['revision'], 1);
            throw new RuntimeException('Finalized without GeoFort staff');
        } catch (RosterPlanException $e) {
            $assert($s['service']->getPlan($p['id'])['status'] === 'concept', 'Failed finalization changed status');
        }
        $stored = serialize($s['raw']->load($p['id']));
        try {
            $apply($p, $preferences(null, 'school', 'geofort'));
            throw new RuntimeException('Stale apply accepted');
        } catch (RosterPlanException $e) {
            $assert($e->publicCode === 'ROSTER_REVISION_CONFLICT', 'Wrong stale error');
        }
        $assert($stored === serialize($s['raw']->load($p['id'])) && array_diff_key($repository->forPlan($p['id']), ['allowLateVi' => true]) === $choice, 'Stale apply changed choices');
        $reverse = $preferences(null, 'school', 'geofort');
        $apply($saved, $reverse);
        $assert(array_diff_key($s['service']->getPlan($p['id'])['planning']['lessonPreferences'], ['allowLateVi' => true]) === $reverse, 'No preference/reversed supervision not persisted');
        $repeated = $generator->preview($p['id'], $p['planning']['generation']['defaultRounds'], [], 'lesson_only');
        $assert(array_diff_key($repeated['lessonPreferences'], ['allowLateVi' => true]) === $reverse, 'Old API omitted saved preferences');
    }
    echo "PASS early-module order, independent supervision, both programme versions, preview/read/apply, reload, open roles and stale-write protection\n";

    // Every rank matters, including the last two when the first ranks remain identical.
    $keys = ['Voedsel-Innovatie', 'Dynamische-Globe', 'Klimaat-Experience', 'Earth-Watch', 'Minecraft-Programmeren'];
    $one = $make($keys, 1);
    $orders = [
        $keys,
        ['Voedsel-Innovatie', 'Dynamische-Globe', 'Klimaat-Experience', 'Minecraft-Programmeren', 'Earth-Watch'],
        ['Voedsel-Innovatie', 'Earth-Watch', 'Minecraft-Programmeren', 'Dynamische-Globe', 'Klimaat-Experience'],
    ];
    foreach ($orders as $order) {
        $choice = ['moduleOrder' => $order, 'supervision' => ['Klimaat-Experience' => 'geofort']];
        $answer = $preview($one, $choice);
        $actual = [];
        foreach ($answer['rounds'] as $round) foreach ($answer['sessions'] as $session) {
            if ($session['startTime'] === $round['startTime']) $actual[] = $session['moduleKey'];
        }
        $assert($actual === $order, 'Lower-ranked modules did not affect the actual schedule');
    }
    $five = $make($keys, 3);
    foreach ($orders as $order) {
        $choice = ['moduleOrder' => $order, 'supervision' => ['Klimaat-Experience' => 'geofort']];
        $answer = $preview($five, $choice);
        $assert((new RosterPairedLessonSearch($s['config'], true))->conflicts($answer['sessions'], []) === [], 'Five-module capacity broken');
        $weighted = 0;
        foreach ($sessionsFor($answer, 'Voedsel-Innovatie') as $session) {
            $index = array_search($session['startTime'], array_column($answer['rounds'], 'startTime'), true);
            $weighted += $index * count($session['groupIds']);
        }
        $assert($weighted === 1, 'Food must retain first priority for all three groups');
    }
    foreach ([
        array_reverse($keys), array_slice($keys, 1), [...$keys, 'Absent'],
        ['Voedsel-Innovatie', 'Earth-Watch', 'Earth-Watch', 'Klimaat-Experience', 'Dynamische-Globe'],
        ['Voedsel-Innovatie', [], 'Klimaat-Experience', 'Earth-Watch', 'Minecraft-Programmeren'],
    ] as $invalidOrder) {
        try { $preview($five, ['moduleOrder' => $invalidOrder, 'supervision' => []]); throw new RuntimeException('Invalid ranking accepted'); }
        catch (RosterPlanException $e) { $assert($e->publicCode === 'INVALID_LESSON_PREFERENCES', 'Wrong ranking error'); }
    }
    // Saved single-module choices migrate, and programme changes keep surviving ranks.
    $repository->save($five['id'], ['earlyModuleKey' => 'Earth-Watch', 'supervision' => ['Klimaat-Experience' => 'school']]);
    $migrated = $s['service']->getPlan($five['id'])['planning']['lessonPreferences'];
    $assert(array_slice($migrated['moduleOrder'], 0, 2) === ['Voedsel-Innovatie', 'Earth-Watch'], 'Legacy preference migration lost food lock or chosen priority');
    $assert($migrated['supervision']['Klimaat-Experience'] === 'school', 'Migration lost supervision');
    $modified = [
        $s['config']->moduleOption('Voedsel-Innovatie'), $s['config']->moduleOption('Earth-Watch'),
        $s['config']->moduleOption('Stop-de-Klimaat-Klok'),
    ];
    $reconciled = RosterLessonPreferences::forModules($migrated, $modified);
    $assert($reconciled['moduleOrder'] === ['Voedsel-Innovatie', 'Earth-Watch', 'Stop-de-Klimaat-Klok'] && $reconciled['supervision'] === [], 'Changed modules not reconciled');
    echo "PASS full ranking, locked food, normal three-group capacity, invalid orders and saved preference migration\n";

    $morningKeys = ['Zandtafel', 'Rising-Risk', 'Dynamische-Globe', 'Dynamische-Globe-Bios', 'Expedition-Earth'];
    $morning = $make($morningKeys, 3, null, 'ochtend');
    $valid = $preview($morning, ['moduleOrder' => $morningKeys, 'supervision' => []]);
    $assert(array_filter($valid['sessions'], static fn(array $s): bool => $s['schoolFallback'] || $s['minimumGeoFortStaff'] < 1) === [], 'Morning supervision weakened');
    foreach ([
        ['moduleOrder' => $morningKeys, 'supervision' => ['Zandtafel' => 'school']],
        ['moduleOrder' => ['Absent'], 'supervision' => []],
        ['moduleOrder' => 'Zandtafel', 'supervision' => []],
        ['moduleOrder' => $morningKeys, 'supervision' => 'school'],
        ['moduleOrder' => $morningKeys, 'supervision' => [], 'unknown' => true],
        ['moduleOrder' => array_combine(range(1, 5), $morningKeys), 'supervision' => []],
    ] as $invalid) {
        try { $preview($morning, $invalid); throw new RuntimeException('Invalid preference accepted'); }
        catch (RosterPlanException $e) { $assert($e->publicCode === 'INVALID_LESSON_PREFERENCES', 'Wrong preference error'); }
    }
    $defaults = RosterLessonPreferences::defaults($morning['planning']['modules']);
    $assert($defaults['supervision'] === [] && count($defaults['moduleOrder']) === 5, 'Morning defaults invalid');
    echo "PASS morning supervision and authoritative input validation\n";

    // Capacity of a saved companion overrules the early preference and produces an explanation.
    $a = $make(['Earth-Watch', 'Stop-de-Klimaat-Klok'], 1);
    $b = $make(['Earth-Watch', 'Stop-de-Klimaat-Klok'], 1);
    $dateA = $s['raw']->load($a['id'])['plan']['visit_date'];
    $db->pdo->prepare('UPDATE roster_plans SET visit_date=? WHERE id=?')->execute([$dateA, $b['id']]);
    $db->pdo->beginTransaction();
    (new RosterPairSqlRepository($db->pdo))->create([$a['id'], $b['id']], $dateA, 'lessons_first', 1);
    $db->pdo->commit();
    $choice = ['moduleOrder' => ['Earth-Watch', 'Stop-de-Klimaat-Klok'], 'supervision' => []];
    $apply($a, $choice);
    $fixed = serialize($s['raw']->load($a['id']));
    $limited = $preview($b, $choice);
    $assert(in_array('EARLY_MODULE_PREFERENCE_LIMITED', array_column($limited['warnings'], 'code'), true), 'Missing preference deviation explanation');
    $assert((new RosterPairedLessonSearch($s['config'], true))->conflicts($limited['sessions'], $limited['companion']['occupancy']) === [], 'Early preference violated companion capacity');
    $apply($b, $choice);
    $assert($fixed === serialize($s['raw']->load($a['id'])), 'Companion changed');
    echo "PASS capacity overrides soft preference without changing companion\n";
} finally {
    $db->drop();
}

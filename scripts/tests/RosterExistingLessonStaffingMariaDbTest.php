<?php
declare(strict_types=1);

require __DIR__ . '/Support/RosterLifecycleFixtures.php';

use GeoFort\Services\Dashboard\Roster\{RosterAutoGenerator, RosterPlanException, RosterStaffOptimizer};
use GeoFort\Services\Sql\{RosterPairSqlRepository, RosterSessionCookSqlRepository};

$assert = RosterLifecycleFixtures::assert(...);
$db = DisposableBookingMariaDb::create('existing_lesson_staffing');
try {
    RosterLifecycleFixtures::schema($db->pdo);
    $s = RosterLifecycleFixtures::services($db->pdo);
    $optimizer = new RosterStaffOptimizer($s['staff']);
    $generator = new RosterAutoGenerator($db->pdo, $s['bookings'], $s['plans'], $s['sessions'], $s['config'], $s['templates'], $s['staff'], $optimizer);
    $makeMember = static function (array $keys, bool $cook = false) use ($db): int {
        static $number = 0;
        $db->pdo->prepare('INSERT INTO roster_staff_members(display_name,is_active,can_guide,can_cook) VALUES (?,1,1,?)')->execute(['Fixture guide '.++$number, (int) $cook]);
        $id = (int) $db->pdo->lastInsertId();
        foreach ($keys as $i => $key) $db->pdo->prepare('INSERT INTO roster_staff_module_preferences(staff_id,module_key,preference_rank) VALUES (?,?,?)')->execute([$id, $key, $i + 1]);
        return $id;
    };
    $date = new DateTimeImmutable('2028-11-01');
    $make = static function (array $keys, string $program = 'dag', ?string $version = null, int $groups = 2) use ($s, $db, &$date): array {
        $date = $date->modify('+7 days');
        $p = $s['service']->createManual('Staffing fixture', 'Testplaats', $date->format('Y-m-d'), 'primairOnderwijs', $program, $groups * 15, $groups, 1)['plan'];
        $s['plans']->saveManualModuleKeys($p['id'], $keys);
        if ($version !== null) $db->pdo->prepare('UPDATE roster_manual_plan_context SET program_version=? WHERE roster_plan_id=?')->execute([$version, $p['id']]);
        return $s['service']->getPlan($p['id']);
    };
    $storedLayout = static function(int $id) use ($s): string {
        $raw = $s['raw']->load($id);
        return serialize([
            $raw['sessions'], $raw['groups'], $raw['context'],
            array_map(static fn(array $l): array => array_diff_key($l, ['supervision_kind' => true]), $raw['links']),
            array_values(array_filter($raw['links'], static fn(array $l): bool => $l['supervision_kind'] === 'school')),
        ]);
    };
    $error = static function (callable $run, string $code) use ($assert): void {
        try { $run(); throw new RuntimeException("Expected $code"); }
        catch (RosterPlanException $e) { $assert($e->publicCode === $code, "Expected $code, got {$e->publicCode}"); }
    };
    $keys = ['Dynamische-Globe', 'Klimaat-Experience', 'Klimparcours', 'Earth-Watch'];
    $guides = [$makeMember($keys), $makeMember($keys)];
    foreach ([null, 'day-four-2027'] as $version) {
        $p = $make($keys, version: $version);
        $choice = ['moduleOrder' => $keys, 'supervision' => ['Klimaat-Experience' => 'geofort', 'Klimparcours' => 'school']];
        $generator->apply($p['id'], $p['revision'], $p['planning']['generation']['defaultRounds'], true, 1, staffingMode: 'lesson_only', lessonPreferences: $choice);
        $p = $s['service']->getPlan($p['id']);
        // A manual location must survive; it is not reset to the module default.
        $db->pdo->prepare('UPDATE roster_sessions SET location_label=? WHERE id=?')->execute(['Specifieke zaal', $p['planning']['sessions'][0]['id']]);
        $before = $storedLayout($p['id']);
        $rawBefore = serialize($s['raw']->load($p['id']));
        // Bogus requested rounds/preferences cannot rewrite the saved source.
        $preview = $generator->preview($p['id'], [['startTime' => '23:00', 'endTime' => '23:30']], $guides, lessonPreferences: ['moduleOrder' => []], useExistingLessons: true);
        $assert($preview['useExistingLessons'] && $preview['canApply'] && $preview['canFinalize'], 'Valid saved timetable not staffable: '.json_encode($preview['hardIssues']));
        $assert($preview['staffing']['unfilledRequiredAssignments'] === 0, 'Unexpected missing guide');
        $assert(serialize($s['raw']->load($p['id'])) === $rawBefore, 'Preview changed database');
        $assert(array_column($preview['sessions'], 'existingSessionId') === array_column($p['planning']['sessions'], 'id'), 'Preview lost saved sessions');
        foreach ($preview['sessions'] as $lesson) if ($lesson['moduleKey'] === 'Klimparcours') $assert($lesson['assignedStaffIds'] === [] && $lesson['schoolFallback'], 'School supervision was staffed');
        $error(fn() => $generator->apply($p['id'], $p['revision'], [], false, 1, $guides, useExistingLessons: true), 'EXISTING_SESSIONS_REQUIRE_REPLACE');
        $error(fn() => $generator->apply($p['id'], $p['revision'] - 1, [], true, 1, $guides, useExistingLessons: true), 'ROSTER_REVISION_CONFLICT');
        $error(fn() => $generator->apply($p['id'], $p['revision'], [], true, 1, $guides, expectedCatalogRevision: $preview['catalogRevision'] + 1, useExistingLessons: true), 'STALE_CATALOG');
        $generator->apply($p['id'], $p['revision'], [], true, 1, $guides, useExistingLessons: true);
        $p = $s['service']->getPlan($p['id']);
        $assert($storedLayout($p['id']) === $before, 'Staffing changed saved lessons, times, groups, locations, timeline or school supervision');
        $validation = $s['validator']->validate($p['id'], $p['revision']);
        $assert($validation->errors === [], 'Applied staffing did not validate: '.json_encode($validation->errors));
        $assert($p['planning']['lessonPreferences'] === $choice, 'Stored lesson preferences lost');
        // Insufficient skills must stay visibly OPEN, never turn GeoFort KE into school.
        $limited = [$makeMember(['Earth-Watch'])];
        $open = $generator->preview($p['id'], [], $limited, useExistingLessons: true);
        $assert($open['canApply'] && !$open['canFinalize'] && $open['staffing']['unfilledRequiredAssignments'] > 0, 'Incomplete preview not reported');
        foreach ($open['sessions'] as $lesson) if ($lesson['moduleKey'] === 'Klimaat-Experience') {
            $assert(!$lesson['schoolFallback'] && $lesson['assignedStaffIds'] === [], 'Missing GeoFort KE silently became school');
        }
        $generator->apply($p['id'], $p['revision'], [], true, 1, $limited, useExistingLessons: true);
        $p = $s['service']->getPlan($p['id']);
        $assert($storedLayout($p['id']) === $before, 'Incomplete apply changed layout');
        $assert($s['validator']->validate($p['id'], $p['revision'])->errors !== [], 'Missing staffing allowed finalization');
        $error(fn() => $generator->preview($p['id'], [], [], 'lesson_only', useExistingLessons: true), 'INVALID_EXISTING_LESSON_MODE');
        $db->pdo->prepare("UPDATE roster_plans SET status='published' WHERE id=?")->execute([$p['id']]);
        $error(fn() => $generator->preview($p['id'], [], $guides, useExistingLessons: true), 'PLAN_NOT_EDITABLE');
        // Restore synthetic fixture only; no production or local application data is used.
        $db->pdo->prepare("UPDATE roster_plans SET status='concept' WHERE id=?")->execute([$p['id']]);
    }
    echo "PASS existing layout and explicit supervision, preview read-only, four/five-module staffing, incomplete concepts and revision/status guards\n";

    $empty = $make($keys);
    $error(fn() => $generator->preview($empty['id'], [], $guides, useExistingLessons: true), 'EXISTING_LESSONS_REQUIRED');
    $morningKeys = ['Zandtafel', 'Rising-Risk', 'Dynamische-Globe', 'Dynamische-Globe-Bios', 'Expedition-Earth'];
    $morning = $make($morningKeys, 'ochtend', groups: 3);
    $generator->apply($morning['id'], $morning['revision'], $morning['planning']['generation']['defaultRounds'], true, 1, staffingMode: 'lesson_only');
    $morning = $s['service']->getPlan($morning['id']);
    $before = $storedLayout($morning['id']);
    $morningStaff = [$makeMember(['Klimaat-Experience', 'Dynamische-Globe']), $makeMember(['Klimaat-Experience', 'Dynamische-Globe']), $makeMember(['Klimaat-Experience', 'Dynamische-Globe'])];
    $answer = $generator->preview($morning['id'], [], $morningStaff, useExistingLessons: true);
    $assert($answer['canFinalize'], 'Morning failed: '.json_encode($answer['hardIssues']));
    foreach ($answer['sessions'] as $lesson) $assert(!$lesson['schoolFallback'] && $lesson['assignedStaffIds'] !== [], 'Unsupervised morning');
    $generator->apply($morning['id'], $morning['revision'], [], true, 1, $morningStaff, useExistingLessons: true);
    $assert($storedLayout($morning['id']) === $before, 'Morning sessions changed');
    echo "PASS morning family skills, required guidance and empty-plan guard\n";

    // Mixed explicit school/GeoFort groups in the same KE session.
    $mixed = $make(['Klimaat-Experience', 'Earth-Watch']);
    $gids = array_column($mixed['groups'], 'id');
    $rounds = $mixed['planning']['generation']['defaultRounds'];
    foreach (['Klimaat-Experience', 'Earth-Watch'] as $i => $key) {
        $sid = $s['sessions']->insertActivity($mixed['id'], $key, $rounds[$i]['startTime'], $rounds[$i]['endTime'], 'Handmatig', 1);
        $s['sessions']->replaceGroups($sid, $gids);
        foreach ($gids as $gid) $s['sessions']->setGroupSupervision($sid, $gid, $i === 0 && $gid === $gids[0] ? 'school' : 'open');
    }
    $answer = $generator->preview($mixed['id'], [], $guides, useExistingLessons: true);
    $ke = array_values(array_filter($answer['sessions'], static fn(array $s): bool => $s['moduleKey'] === 'Klimaat-Experience'))[0];
    $assert(array_column($ke['groupStaffAssignments'], 'kind') === ['school', 'staff'], 'Mixed school/GeoFort grouping lost');
    $generator->apply($mixed['id'], $mixed['revision'], [], true, 1, $guides, useExistingLessons: true);
    $mixed = $s['service']->getPlan($mixed['id']);
    $assert($s['validator']->validate($mixed['id'], $mixed['revision'])->errors === [], 'Mixed staffing failed validation');

    // Partially overlapping saved lessons and cook reservations must not double-book.
    $synthetic = [
        ['existingSessionId' => 1, 'moduleKey' => 'Earth-Watch', 'startTime' => '10:15', 'endTime' => '11:00', 'minimumGeoFortStaff' => 1, 'groupIds' => [1]],
        ['existingSessionId' => 2, 'moduleKey' => 'Dynamische-Globe', 'startTime' => '10:30', 'endTime' => '11:15', 'minimumGeoFortStaff' => 1, 'groupIds' => [2]],
    ];
    $overlap = $optimizer->optimize($synthetic, [$guides[0]]);
    $assert($overlap['unfilledRequiredAssignments'] === 1, 'Partially overlapping lessons double-booked a guide');
    $dualRole = $makeMember(['Earth-Watch', 'Voedsel-Innovatie'], true);
    $synthetic[1]['moduleKey'] = 'Voedsel-Innovatie';
    $overlap = $optimizer->optimize($synthetic, [$dualRole], cookStaffId: $dualRole);
    $assert($overlap['assignments'] === [], 'Cook assigned to overlapping guide role');
    echo "PASS mixed group supervision and irregular overlap protection, including cooks\n";

    // Existing per-session cook overrides must be replaced by the selected cook.
    $foodKeys = ['Voedsel-Innovatie', 'Dynamische-Globe', 'Earth-Watch', 'Klimaat-Experience', 'Minecraft-Programmeren'];
    $food = $make($foodKeys, groups: 1);
    $generator->apply($food['id'], $food['revision'], $food['planning']['generation']['defaultRounds'], true, 1, staffingMode: 'lesson_only');
    $food = $s['service']->getPlan($food['id']);
    $oldCook = $makeMember([], true); $newCook = $makeMember([], true);
    $foodGuide = $makeMember($foodKeys);
    $cooks = new RosterSessionCookSqlRepository($db->pdo);
    $vi = array_values(array_filter($food['planning']['sessions'], static fn(array $l): bool => $l['moduleKey'] === 'Voedsel-Innovatie'))[0];
    $cooks->save($vi['id'], $oldCook);
    $preview = $generator->preview($food['id'], [], [$foodGuide], cookStaffId: $newCook, useExistingLessons: true);
    $assert($preview['canFinalize'], 'Food staffing invalid: '.json_encode($preview['hardIssues']));
    $generator->apply($food['id'], $food['revision'], [], true, 1, [$foodGuide], cookStaffId: $newCook, useExistingLessons: true);
    $assert($cooks->forPlan($food['id']) === [] && $s['staff']->settingsForPlan($food['id'])['cookStaffId'] === $newCook, 'Old cook override survived staffing');
    echo "PASS selected cook replaces prior overrides\n";

    foreach (['published', 'paired'] as $mode) {
        $target = $make(['Stop-de-Klimaat-Klok', 'Earth-Watch'], groups: 1);
        $other = RosterLifecycleFixtures::validPlan($db->pdo, $s, 40, 2, 2, $target['visitDate']);
        $busy = $makeMember(['Earth-Watch', 'Stop-de-Klimaat-Klok']);
        $spare = $makeMember(['Earth-Watch', 'Stop-de-Klimaat-Klok']);
        foreach ($other['planning']['sessions'] as $lesson) $s['staff']->replaceSessionAssignments($lesson['id'], [$busy]);
        $s['staff']->replacePlanSelection($other['id'], [$busy]);
        if ($mode === 'published') {
            $s['lifecycle']->finalize($other['id'], $other['revision'], 1);
        } else {
            $db->pdo->beginTransaction();
            (new RosterPairSqlRepository($db->pdo))->create([$target['id'], $other['id']], $target['visitDate'], 'linked', 1);
            $db->pdo->commit();
        }
        $gid = $target['groups'][0]['id'];
        foreach (['Stop-de-Klimaat-Klok', 'Earth-Watch'] as $i => $key) {
            $round = $target['planning']['generation']['defaultRounds'][$i];
            $sid = $s['sessions']->insertActivity($target['id'], $key, $round['startTime'], $round['endTime'], null, 1);
            $s['sessions']->replaceGroups($sid, [$gid]);
        }
        $otherBefore = serialize($s['raw']->load($other['id']));
        $snapshotBefore = serialize($s['snapshots']->state($other['id']));
        $answer = $generator->preview($target['id'], [], [$busy, $spare], useExistingLessons: true);
        $assert($answer['canApply'] && $answer['canFinalize'], "$mode proposal invalid: ".json_encode($answer['hardIssues']));
        foreach ($answer['sessions'] as $lesson) $assert($lesson['assignedStaffIds'] === [$spare], "$mode occupancy ignored");
        $error(fn() => $generator->apply($target['id'], $target['revision'], [], true, 1, [$busy, $spare], useExistingLessons: true, expectedStaffingSignature: str_repeat('0', 64)), 'STAFFING_PREVIEW_CHANGED');
        $generator->apply($target['id'], $target['revision'], [], true, 1, [$busy, $spare], useExistingLessons: true, expectedStaffingSignature: $answer['staffingSignature']);
        $assert(serialize($s['raw']->load($other['id'])) === $otherBefore, 'Other school changed');
        $assert(serialize($s['snapshots']->state($other['id'])) === $snapshotBefore, 'Published snapshot changed');
    }
    echo "PASS published and linked-school occupancy, unchanged other rosters and proposal signature\n";
} finally {
    $db->drop();
}

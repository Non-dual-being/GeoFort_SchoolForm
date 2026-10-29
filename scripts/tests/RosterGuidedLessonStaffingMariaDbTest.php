<?php
declare(strict_types=1);

require __DIR__ . '/Support/RosterLifecycleFixtures.php';

use GeoFort\Services\Dashboard\Roster\{RosterAutoGenerator, RosterPlanException, RosterStaffOptimizer, RosterGuidedLessonOptimizer};

$assert = RosterLifecycleFixtures::assert(...);
$db = DisposableBookingMariaDb::create('guided_lesson_staffing');
try {
    RosterLifecycleFixtures::schema($db->pdo);
    $s = RosterLifecycleFixtures::services($db->pdo);
    $generator = new RosterAutoGenerator($db->pdo, $s['bookings'], $s['plans'], $s['sessions'], $s['config'], $s['templates'], $s['staff'], new RosterStaffOptimizer($s['staff']));
    $p = $s['service']->createManual('Demonstratie · minder tussenuren', 'Testplaats', '2028-11-15', 'primairOnderwijs', 'dag', 19, 1, 1)['plan'];
    $keys = ['Dynamische-Globe', 'Klimaat-Experience', 'Earth-Watch', 'Klimparcours'];
    $s['plans']->saveManualModuleKeys($p['id'], $keys);
    $db->pdo->prepare('UPDATE roster_manual_plan_context SET program_version=? WHERE roster_plan_id=?')->execute(['day-four-2027', $p['id']]);
    $db->pdo->exec("INSERT INTO roster_staff_members(display_name,is_active,can_guide,can_cook) VALUES ('Demo begeleider A',1,1,0)");
    $staffId = (int) $db->pdo->lastInsertId();
    foreach (['Dynamische-Globe', 'Earth-Watch'] as $i => $key) {
        $db->pdo->prepare('INSERT INTO roster_staff_module_preferences(staff_id,module_key,preference_rank) VALUES (?,?,?)')->execute([$staffId, $key, $i + 1]);
    }
    $db->pdo->exec("INSERT INTO roster_staff_members(display_name,is_active,can_guide,can_cook) VALUES ('Demo KE begeleider',1,1,0)");
    $keId=(int)$db->pdo->lastInsertId();
    $db->pdo->prepare('INSERT INTO roster_staff_module_preferences(staff_id,module_key,preference_rank) VALUES (?,?,1)')->execute([$keId,'Klimaat-Experience']);
    $team=[$staffId,$keId];
    $p = $s['service']->getPlan($p['id']);
    foreach ($keys as $i => $key) {
        $r = $p['planning']['generation']['defaultRounds'][$i];
        $sid = $s['sessions']->insertActivity($p['id'], $key, $r['startTime'], $r['endTime'], 'Bestaande locatie '.$i, 1);
        $s['sessions']->replaceGroups($sid, [$p['groups'][0]['id']]);
        $s['sessions']->setGroupSupervision($sid, $p['groups'][0]['id'], $key === 'Klimparcours' ? 'school' : 'open');
    }
    $before = $s['service']->getPlan($p['id']);
    $raw = $s['raw']->load($p['id']);
    $fixedProposal = $generator->preview($p['id'], [], $team, useExistingLessons: true);
    $assert($fixedProposal['canFinalize'], 'Baseline invalid: '.json_encode($fixedProposal['hardIssues']));
    $proposal = $generator->preview($p['id'], [], $team, useExistingLessons: true, optimizeExistingLessons: true);
    $assert($proposal['optimization']['before']['gapMinutes'] === 45, 'Expected one lesson gap, excluding lunch');
    $assert($proposal['optimization']['after']['gapMinutes'] === 0, 'Optimization did not remove the gap');
    $assert(count($proposal['optimization']['changes']) === 2, 'Expected the minimum pair of lesson shifts');
    $assert($proposal['canFinalize'] && $proposal['staffing']['unfilledRequiredAssignments'] === 0, 'Incomplete/invalid optimized proposal');
    $assert($s['raw']->load($p['id']) === $raw, 'Preview modified saved data');
    $again = $generator->preview($p['id'], [], $team, useExistingLessons: true, optimizeExistingLessons: true);
    $assert($again === $proposal, 'Search is not deterministic');
    foreach ($proposal['sessions'] as $lesson) if ($lesson['moduleKey'] === 'Klimparcours') {
        $assert($lesson['schoolFallback'] && $lesson['assignedStaffIds'] === [], 'Explicit school supervision changed');
    }
    $expect = static function(callable $run, string $code) use ($assert): void {
        try { $run(); throw new RuntimeException("Expected $code"); }
        catch (RosterPlanException $e) { $assert($e->publicCode === $code, "Expected $code, got ".$e->publicCode); }
    };
    $expect(fn() => $generator->preview($p['id'], [], $team, optimizeExistingLessons: true), 'INVALID_EXISTING_LESSON_MODE');
    $expect(fn() => $generator->apply($p['id'], $p['revision'], [], true, 1, $team, useExistingLessons: true, optimizeExistingLessons: true), 'STAFFING_PREVIEW_REQUIRED');
    $expect(fn() => $generator->apply($p['id'], $p['revision'], [], true, 1, $team, useExistingLessons: true, expectedStaffingSignature: $fixedProposal['staffingSignature'], optimizeExistingLessons: true), 'STAFFING_PREVIEW_CHANGED');
    $expect(fn() => $generator->apply($p['id'], $p['revision'], [], false, 1, $team, useExistingLessons: true, expectedStaffingSignature: $proposal['staffingSignature'], optimizeExistingLessons: true), 'EXISTING_SESSIONS_REQUIRE_REPLACE');
    $expect(fn() => $generator->apply($p['id'], $p['revision'] - 1, [], true, 1, $team, useExistingLessons: true, expectedStaffingSignature: $proposal['staffingSignature'], optimizeExistingLessons: true), 'ROSTER_REVISION_CONFLICT');
    $assert($s['raw']->load($p['id']) === $raw, 'Rejected apply modified data');
    $generator->apply($p['id'], $p['revision'], [], true, 1, $team, useExistingLessons: true, expectedStaffingSignature: $proposal['staffingSignature'], optimizeExistingLessons: true);
    $after = $s['service']->getPlan($p['id']);
    $stored = $s['raw']->load($p['id']);
    $byId = array_column($raw['sessions'], null, 'id');
    $proposedById = array_column($proposal['sessions'], null, 'existingSessionId');
    foreach ($stored['sessions'] as $lesson) {
        $id = (int) $lesson['id'];
        $assert(isset($byId[$id]), 'Session identity changed');
        foreach (['module_key', 'location_label', 'item_type'] as $key) $assert($lesson[$key] === $byId[$id][$key], "$key changed");
        $assert(substr($lesson['start_time'], 0, 5) === $proposedById[$id]['startTime'] && substr($lesson['end_time'], 0, 5) === $proposedById[$id]['endTime'], 'Applied times differ from reviewed proposal');
    }
    $assert($stored['groups'] === $raw['groups'] && $stored['context'] === $raw['context'], 'Group or day structure changed');
    $assert($s['validator']->validate($p['id'], $after['revision'])->errors === [], 'Stored optimized result failed validation');
    $expect(fn() => $generator->apply($p['id'], $p['revision'], [], true, 1, $team, useExistingLessons: true, expectedStaffingSignature: $proposal['staffingSignature'], optimizeExistingLessons: true), 'STAFFING_PREVIEW_CHANGED');
    $noChange = $generator->preview($p['id'], [], $team, useExistingLessons: true, optimizeExistingLessons: true);
    $assert($noChange['optimization']['changes'] === [], 'Timetable without gaps shifted unnecessarily');
    $assert($noChange['optimization']['before']['gapMinutes'] === 0 && $noChange['optimization']['after']['gapMinutes'] === 0, 'Incorrect no-change comparison');
    echo "PASS genuine 45-minute improvement, pauses excluded, minimum lesson shifts, identical staff, deterministic/read-only preview, explicit review and conflict guards, exact persistence and full validation\n";

    // Minute unions: overlapping schools and cook activity count once; volunteers stay separate.
    $metricFixture = ['staffing' => ['staff' => [
        ['id' => 1, 'name' => 'Paid', 'employmentType' => 'paid'],
        ['id' => 2, 'name' => 'Volunteer', 'employmentType' => 'volunteer'],
    ], 'cook' => ['id' => 1, 'name' => 'Paid', 'employmentType' => 'paid'], 'unfilledRequiredAssignments' => 0],
        'sessions' => [
            ['moduleKey'=>'Voedsel-Innovatie', 'startTime'=>'10:00', 'endTime'=>'10:45', 'assignedStaffIds'=>[2]],
            ['moduleKey'=>'Klimaat-Experience', 'startTime'=>'10:45', 'endTime'=>'11:30', 'assignedStaffIds'=>[]],
            ['moduleKey'=>'Earth-Watch', 'startTime'=>'12:00', 'endTime'=>'12:45', 'assignedStaffIds'=>[1,2]],
        ]];
    $metrics = RosterGuidedLessonOptimizer::metrics($metricFixture, [['moduleKey'=>'Earth-Watch', 'startTime'=>'10:30', 'endTime'=>'11:15', 'staffIds'=>[1]]]);
    $assert($metrics['paidGapMinutes'] === 15 && $metrics['gapMinutes'] === 60, 'Paid/volunteer/fixed-task gaps wrong');
    $assert($metrics['staff'][0]['workMinutes'] === 120, 'Overlapping cook task counted twice');
    echo "PASS paid/volunteer separation, cook and fixed other-school minute unions\n";

    // Optional synthetic UI artifact, never using the application's real database.
    $fixturePath = getenv('GUIDED_STAFF_UI_FIXTURE');
    if (is_string($fixturePath) && $fixturePath !== '') {
        foreach (['before', 'after'] as $which) {
            ${$which}['planning']['staffCatalog'] = array_values(array_filter(${$which}['planning']['staffCatalog'], static fn(array $m): bool => in_array($m['id'],$team,true)));
        }
        file_put_contents($fixturePath, json_encode(compact('before', 'fixedProposal', 'proposal', 'after'), JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
    }

    // A linked school's room/staff occupancy stays fixed during the search and apply.
    foreach ($raw['sessions'] as $lesson) {
        $s['sessions']->updateActivity((int) $lesson['id'], $p['id'], $lesson['module_key'], $lesson['start_time'], $lesson['end_time'], $lesson['location_label'], 1);
    }
    $other = RosterLifecycleFixtures::validPlan($db->pdo, $s, 19, 1, 2, $p['visitDate']);
    foreach ($other['planning']['sessions'] as $lesson) if ($lesson['moduleKey'] === 'Earth-Watch') {
        $s['staff']->replaceSessionAssignments($lesson['id'], [$staffId]);
        $s['staff']->addPlanSelection($other['id'], [$staffId]);
    }
    $db->pdo->beginTransaction();
    (new GeoFort\Services\Sql\RosterPairSqlRepository($db->pdo))->create([$p['id'], $other['id']], $p['visitDate'], 'linked', 1);
    $db->pdo->commit();
    $otherBefore = $s['raw']->load($other['id']);
    $paired = $generator->preview($p['id'], [], $team, useExistingLessons: true, optimizeExistingLessons: true);
    $assert($paired['canFinalize'], 'Guided search failed with fixed other-school occupancy: '.json_encode($paired['hardIssues']));
    $assert($paired['optimization']['before']['unfilledAssignments'] > $paired['optimization']['after']['unfilledAssignments'], 'Expected occupancy conflict to be resolved by moving target lessons');
    $generator->apply($p['id'], $after['revision'], [], true, 1, $team, useExistingLessons: true, expectedStaffingSignature: $paired['staffingSignature'], optimizeExistingLessons: true);
    $assert($s['raw']->load($other['id']) === $otherBefore, 'Guided optimization changed linked school');
    $current = $s['service']->getPlan($p['id']);
    $previewBeforeOtherEdit = $generator->preview($p['id'], [], $team, useExistingLessons: true, optimizeExistingLessons: true);
    $db->pdo->beginTransaction();
    $s['plans']->advanceRevision($other['id'], 1);
    $db->pdo->commit();
    $expect(fn() => $generator->apply($p['id'], $current['revision'], [], true, 1, $team, useExistingLessons: true, expectedStaffingSignature: $previewBeforeOtherEdit['staffingSignature'], optimizeExistingLessons: true), 'STAFFING_PREVIEW_CHANGED');
    echo "PASS guided optimization respects linked-school rooms and staff, preserves other school, rejects stale companion\n";

    foreach (['ochtend', 'dag'] as $program) {
        $moduleKeys = $program === 'ochtend'
            ? ['Zandtafel', 'Rising-Risk', 'Expedition-Earth', 'Dynamische-Globe', 'Dynamische-Globe-Bios']
            : ['Voedsel-Innovatie', 'Dynamische-Globe', 'Earth-Watch', 'Klimaat-Experience', 'Minecraft-Programmeren'];
        $check = $s['service']->createManual('Regels '.$program, 'Testplaats', $program === 'ochtend' ? '2028-11-22' : '2028-11-29', 'primairOnderwijs', $program, 30, 2, 1)['plan'];
        $s['plans']->saveManualModuleKeys($check['id'], $moduleKeys);
        $check = $s['service']->getPlan($check['id']);
        $generator->apply($check['id'], $check['revision'], $check['planning']['generation']['defaultRounds'], true, 1, staffingMode: 'lesson_only');
        $check = $s['service']->getPlan($check['id']);
        $ids = [];
        foreach ([0, 1, 2] as $i) {
            $db->pdo->prepare('INSERT INTO roster_staff_members(display_name,is_active,can_guide,can_cook) VALUES (?,1,1,1)')->execute(["Guide $program $i"]);
            $id = (int) $db->pdo->lastInsertId(); $ids[] = $id;
            foreach ($moduleKeys as $rank => $key) $db->pdo->prepare('INSERT INTO roster_staff_module_preferences(staff_id,module_key,preference_rank) VALUES (?,?,?)')->execute([$id, $key, $rank + 1]);
        }
        $cookId = $program === 'dag' ? array_pop($ids) : null;
        $originalSessions = array_column($check['planning']['sessions'], null, 'id');
        $candidate = $generator->preview($check['id'], [], $ids, cookStaffId: $cookId, useExistingLessons: true, optimizeExistingLessons: true);
        $assert($candidate['canFinalize'], "$program guided result invalid: ".json_encode($candidate['hardIssues']));
        foreach ($candidate['sessions'] as $lesson) {
            if ($program === 'ochtend') $assert(!$lesson['schoolFallback'] && count($lesson['assignedStaffIds']) >= $lesson['minimumGeoFortStaff'], 'Morning lesson unaccompanied');
            if ($lesson['moduleKey'] === 'Voedsel-Innovatie') $assert($lesson['startTime'] === $originalSessions[$lesson['existingSessionId']]['startTime'], 'Early food lesson moved');
        }
        $generator->apply($check['id'], $check['revision'], [], true, 1, $ids, cookStaffId: $cookId, useExistingLessons: true, expectedStaffingSignature: $candidate['staffingSignature'], optimizeExistingLessons: true);
        $check = $s['service']->getPlan($check['id']);
        $assert($s['validator']->validate($check['id'], $check['revision'])->errors === [], "$program persisted guided result invalid");
    }
    echo "PASS morning mandatory guides/shared resources, five-module day, cook and food-time preservation\n";
} finally {
    $db->drop();
}

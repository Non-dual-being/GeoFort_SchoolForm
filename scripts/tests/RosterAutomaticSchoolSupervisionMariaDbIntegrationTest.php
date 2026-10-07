<?php

declare(strict_types=1);

require __DIR__ . '/Support/RosterLifecycleFixtures.php';

use GeoFort\Services\Dashboard\Roster\RosterAutoGenerator;
use GeoFort\Services\Dashboard\Roster\RosterPlanException;
use GeoFort\Services\Dashboard\Roster\RosterStaffOptimizer;

$db = DisposableBookingMariaDb::create('roster_automatic_school');
$assert = RosterLifecycleFixtures::assert(...);
$evidence = [];

try {
    $pdo = $db->pdo;
    RosterLifecycleFixtures::schema($pdo);
    $s = RosterLifecycleFixtures::services($pdo);
    $optimizer = new RosterStaffOptimizer($s['staff']);
    $generator = new RosterAutoGenerator($pdo, $s['bookings'], $s['plans'], $s['sessions'], $s['config'], $s['templates'], $s['staff'], $optimizer);
    $addStaff = static function (string $name, array $skills, bool $active = true, bool $guide = true, bool $cook = false) use ($pdo): int {
        $pdo->prepare('INSERT INTO roster_staff_members(display_name,is_active,can_guide,can_cook,employment_type) VALUES (?,?,?,?,?)')
            ->execute([$name, (int) $active, (int) $guide, (int) $cook, 'paid']);
        $id = (int) $pdo->lastInsertId();
        $insert = $pdo->prepare('INSERT INTO roster_staff_module_preferences(staff_id,module_key,preference_rank) VALUES (?,?,?)');
        foreach ($skills as $key => $rank) $insert->execute([$id, $key, $rank]);
        return $id;
    };
    $session = static fn (string $module, string $start = '10:15', string $end = '11:00', int $minimum = 0): array => [
        'moduleKey' => $module, 'startTime' => $start, 'endTime' => $end, 'groupIds' => [1],
        'minimumGeoFortStaff' => $minimum, 'schoolSupervisionAllowed' => $s['config']->schoolSupervisionAllowed($module),
    ];
    $assertSchool = static function (array $result, int $index, string $message) use ($assert): void {
        $assert(($result['assignments'][$index] ?? []) === [], $message . ': unexpected staff.');
        $assert(in_array($index, $result['schoolFallbackSessionIndexes'], true), $message . ': missing explicit School fallback.');
    };
    $ke = 'Klimaat-Experience';
    $vs = 'Klimparcours';
    $preferred = $addStaff('School-policy preferred', [$ke => 1, $vs => 2]);
    $alternative = $addStaff('School-policy alternative', [$ke => 10, $vs => 11]);
    $inactive = $addStaff('School-policy inactive', [$ke => 1, $vs => 2], false);
    $nonGuide = $addStaff('School-policy non-guide', [$ke => 1, $vs => 2], true, false);
    $unskilled = $addStaff('School-policy unskilled', ['Earth-Watch' => 1]);

    foreach ([$ke, $vs] as $module) {
        $input = [$session($module)];
        $result = $optimizer->optimize($input, [$alternative, $preferred], false);
        $assert(($result['assignments'][0] ?? []) === [$preferred], $module . ': multiple candidates must use existing rank scoring.');
        $assert($result['schoolFallbackSessionIndexes'] === [], $module . ': available staff became School.');
        $assert($result === $optimizer->optimize($input, [$alternative, $preferred], true), 'Deprecated preferGeoFortKe changed optimization.');
        // A planner day preference still chooses between valid candidates.
        $dayPreference = $optimizer->optimize($input, [$alternative, $preferred], false, null, [
            ['staffId' => $preferred, 'moduleKeys' => [$module === $ke ? $vs : $ke]],
            ['staffId' => $alternative, 'moduleKeys' => [$module]],
        ]);
        $assert($dayPreference['assignments'][0] === [$alternative], 'Existing planner preference was ignored.');
        $assertSchool($optimizer->optimize($input, [], false), 0, $module . ' without selected staff');
        $assertSchool($optimizer->optimize($input, [$inactive, $nonGuide, $unskilled], false), 0, $module . ' without eligible staff');
        $evidence[$module] = ['multipleCandidates' => 'staff', 'legacyPreferenceFalse' => 'staff', 'unselectedOrIneligible' => 'school'];
    }

    // Soft costs (including rank 255 and a paid staff gap) must never displace valid GeoFort coverage.
    $expensive = $addStaff('School-policy rank 255', [$ke => 255]);
    $gapped = $optimizer->optimize([
        $session($ke), $session('Earth-Watch', '11:15', '12:00', 1), $session($ke, '12:15', '13:00'),
    ], [$expensive, $unskilled], false);
    $assert($gapped['assignments'][0] === [$expensive] && $gapped['assignments'][2] === [$expensive], 'Soft rank/gap costs incorrectly selected School.');

    $sharedGuide = $addStaff('School-policy busy', ['Earth-Watch' => 1, $ke => 2, $vs => 3]);
    foreach ([$ke, $vs] as $module) {
        $busy = $optimizer->optimize([$session($module), $session('Earth-Watch', minimum: 1)], [$sharedGuide], false);
        $assert($busy['assignments'][1] === [$sharedGuide] && $busy['unfilledRequiredAssignments'] === 0, 'Optional task displaced required staff.');
        $assertSchool($busy, 0, $module . ' guide occupied in same time slot');
    }
    $cook = $addStaff('School-policy guide and cook', [$ke => 1, $vs => 2], true, true, true);
    $viGuide = $addStaff('School-policy VI guide', ['Voedsel-Innovatie' => 1]);
    foreach ([$ke, $vs] as $module) {
        $cooking = $optimizer->optimize([$session($module), $session('Voedsel-Innovatie', minimum: 1)], [$cook, $viGuide], false, $cook);
        $assertSchool($cooking, 0, $module . ' guide occupied as cook');
    }
    $limited = $addStaff('School-policy three modules', ['Earth-Watch' => 1, 'Stop-de-Klimaat-Klok' => 2, 'Dynamische-Globe' => 3, $ke => 4, $vs => 5]);
    foreach ([$ke, $vs] as $module) {
        $limit = $optimizer->optimize([
            $session('Earth-Watch', minimum: 1), $session('Stop-de-Klimaat-Klok', '11:15', '12:00', 1),
            $session('Dynamische-Globe', '12:15', '13:00', 1), $session($module, '13:15', '14:00'),
        ], [$limited], false);
        $assertSchool($limit, 3, $module . ' fourth distinct module');
    }
    $other = $optimizer->optimize([$session('Earth-Watch', minimum: 1)], [], false);
    $assert($other['unfilledRequiredAssignments'] === 1 && $other['schoolFallbackSessionIndexes'] === [], 'Other module received forbidden School fallback.');
    $evidence['hardConstraints'] = ['sameSlot' => 'school', 'cook' => 'school', 'fourthDistinctModule' => 'school', 'otherModule' => 'unfilled'];

    $createPlan = static function (array $modules, string $date) use ($s): array {
        $p = $s['service']->createManual('Automatic School test', 'Testplaats', $date, 'primairOnderwijs', 'dag', 28, 2, 1)['plan'];
        $s['plans']->saveManualModuleKeys($p['id'], $modules);
        return $s['service']->getPlan($p['id']);
    };
    $groupRows = static function (int $id) use ($pdo): array {
        $q = $pdo->prepare('SELECT s.id AS session_id,s.module_key,g.roster_group_id AS group_id,g.supervision_kind FROM roster_sessions s JOIN roster_session_groups g ON g.roster_session_id=s.id WHERE s.roster_plan_id=? ORDER BY s.id,g.roster_group_id');
        $q->execute([$id]);
        return $q->fetchAll(PDO::FETCH_ASSOC);
    };
    $catalogState = static function () use ($pdo): array {
        $values = [];
        foreach (['roster_staff_members', 'roster_staff_module_preferences', 'roster_staff_catalog_state', 'roster_staff_change_history'] as $table) {
            $rows = $pdo->query('SELECT * FROM ' . $table)->fetchAll(PDO::FETCH_ASSOC);
            $encoded = array_map(static fn (array $row): string => json_encode($row, JSON_THROW_ON_ERROR), $rows);
            sort($encoded);
            $values[$table] = hash('sha256', implode("\n", $encoded));
        }
        return $values;
    };
    $beforeCatalog = $catalogState();
    $p = $createPlan([$ke, $vs], '2028-10-09');
    $rounds = $s['templates']->defaultRounds('dag', 2);
    $proposal = $generator->preview($p['id'], $rounds, [$preferred, $alternative], 'with_staff', false);
    $assert($proposal['canFinalize'], 'Eligible two-module preview is invalid.');
    foreach ($proposal['sessions'] as $item) $assert($item['assignedStaffIds'] !== [] && !$item['schoolFallback'], 'Eligible preview did not prioritize staff.');
    $applied = $generator->apply($p['id'], 1, $rounds, false, 1, [$preferred, $alternative], 'with_staff', false);
    $assert($applied['revision'] === 2, 'Generator must advance revision exactly once.');
    foreach ($groupRows($p['id']) as $row) {
        $assert($row['supervision_kind'] === 'staff', 'Staff supervision was not explicitly stored per group.');
        $byGroup = $s['staff']->groupAssignmentIdsForSession((int) $row['session_id']);
        $assert(($byGroup[(int) $row['group_id']] ?? []) !== [], 'Explicit staff group lost its staff IDs.');
    }
    $assert($s['staff']->settingsForPlan($p['id'])['preferGeoFortKe'] === false, 'Legacy preference must remain backward compatible.');
    $assert($s['validator']->validate($p['id'], 2)->isValid(), 'Persisted staff proposal is invalid.');
    $assert($beforeCatalog === $catalogState(), 'Generator changed central staff/catalog/audit.');
    $assert($s['lifecycle']->finalize($p['id'], 2, 1)['finalization']['trusted'], 'Staff proposal did not finalize.');

    // The same central policy must work in generator, manual assignments and finalization at every supported module count.
    foreach ([2, 3, 4, 5] as $count) {
        $modules = array_slice([$ke, $vs, 'Earth-Watch', 'Stop-de-Klimaat-Klok', 'Dynamische-Globe'], 0, $count);
        $p = $createPlan($modules, '2028-10-' . (8 + $count));
        $selected = [];
        foreach (array_slice($modules, 2) as $module) {
            for ($i = 0; $i < 2; $i++) $selected[] = $addStaff("Required {$count} {$module} {$i}", [$module => 1]);
        }
        $rounds = $s['templates']->defaultRounds('dag', $count);
        $proposal = $generator->preview($p['id'], $rounds, $selected, 'with_staff', false);
        $assert($proposal['canFinalize'], "School preview with {$count} modules is invalid: " . json_encode($proposal['hardIssues']));
        $generator->apply($p['id'], 1, $rounds, false, 1, $selected, 'with_staff', false);
        $revision = 2;
        $rows = $groupRows($p['id']);
        $targetRows = array_values(array_filter($rows, static fn (array $row): bool => in_array($row['module_key'], [$ke, $vs], true)));
        $assert(count($targetRows) === 4, 'Every target module/group must have stored supervision.');
        foreach ($targetRows as $row) $assert($row['supervision_kind'] === 'school', 'Ordinary staff shortage became OPEN.');
        $valid = $s['validator']->validate($p['id'], $revision);
        $assert($valid->isValid(), "Stored {$count}-module School proposal is invalid: " . json_encode($valid->toArray()));
        if ($count === 2 && ($output = getenv('ROSTER_ROUND_C_OUTPUT'))) {
            file_put_contents($output . '/automatic-school-plan.json', json_encode($s['service']->getPlan($p['id']), JSON_PRETTY_PRINT));
            file_put_contents($output . '/automatic-school-proposal.json', json_encode($proposal, JSON_PRETTY_PRINT));
        }
        foreach ([$ke, $vs] as $module) {
            $row = array_values(array_filter($targetRows, static fn (array $row): bool => $row['module_key'] === $module))[0];
            $s['sessionService']->saveGroupStaffAssignment($p['id'], (int) $row['session_id'], $revision++, (int) $row['group_id'], [], 1, 'school');
            $assert($s['validator']->validate($p['id'], $revision)->isValid(), 'Manual School rejected for ' . $module);
            if ($count !== 2) continue;
            $s['sessionService']->saveGroupStaffAssignment($p['id'], (int) $row['session_id'], $revision++, (int) $row['group_id'], [$preferred], 1, 'staff');
            $assert($s['validator']->validate($p['id'], $revision)->isValid(), 'Manual staff correction rejected for ' . $module);
            $s['sessionService']->saveGroupStaffAssignment($p['id'], (int) $row['session_id'], $revision++, (int) $row['group_id'], [], 1, 'open');
            $invalid = $s['validator']->validate($p['id'], $revision);
            $assert(!$invalid->isValid() && in_array('OPEN_SUPERVISION', array_column($invalid->toArray()['errors'], 'code'), true), 'OPEN no longer blocks validation.');
            try {
                $s['lifecycle']->finalize($p['id'], $revision, 1);
                throw new RuntimeException('OPEN was finalized.');
            } catch (RosterPlanException $e) {
                $assert($e->publicCode === 'ROSTER_INVALID', 'Wrong OPEN finalization error.');
            }
            $assert($s['service']->getPlan($p['id'])['revision'] === $revision, 'Rejected finalization changed revision.');
            $s['sessionService']->saveGroupStaffAssignment($p['id'], (int) $row['session_id'], $revision++, (int) $row['group_id'], [], 1, 'school');
        }
        $assert($s['lifecycle']->finalize($p['id'], $revision, 1)['finalization']['trusted'], "School proposal with {$count} modules did not finalize.");
        $evidence['moduleCounts'][$count] = ['KE' => 'school', 'VS' => 'school', 'manual' => 'valid', 'finalized' => true];
    }

    // Lesson-only keeps other modules OPEN; it does not introduce a global fallback.
    $p = $createPlan([$ke, 'Earth-Watch'], '2028-10-20');
    $generator->apply($p['id'], 1, $s['templates']->defaultRounds('dag', 2), false, 1, [], 'lesson_only', false);
    $view = $s['service']->getPlan($p['id']);
    foreach ($view['planning']['sessions'] as $item) {
        $expected = $item['moduleKey'] === $ke ? 'school' : 'open';
        $assert(array_unique(array_column($item['groupSupervision'], 'kind')) === [$expected], 'Lesson-only changed another module policy.');
        if ($item['moduleKey'] !== 'Earth-Watch') continue;
        try {
            $s['sessionService']->saveGroupStaffAssignment($p['id'], $item['id'], 2, $item['groupIds'][0], [], 1, 'school');
            throw new RuntimeException('Other module accepted manual School.');
        } catch (RosterPlanException $e) {
            $assert($e->publicCode === 'SCHOOL_NOT_ALLOWED', 'Unexpected other-module error.');
        }
    }
    $assert(!$s['validator']->validate($p['id'], 2)->isValid(), 'Other module without required staff was valid.');

    // Inject a failure at the new explicit supervision write: all generation writes must roll back.
    $beforeRows = $groupRows($p['id']);
    $pdo->exec("CREATE TRIGGER test_school_write_failure BEFORE UPDATE ON roster_session_groups FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='forced supervision write failure'");
    try {
        $generator->apply($p['id'], 2, $s['templates']->defaultRounds('dag', 2), true, 1, [], 'lesson_only', false);
        throw new LogicException('Forced write failure did not trigger.');
    } catch (RuntimeException $e) {
        $assert($e->getPrevious() instanceof PDOException && str_contains($e->getPrevious()->getMessage(), 'forced supervision write failure'), 'Unexpected rollback failure.');
    } finally {
        $pdo->exec('DROP TRIGGER test_school_write_failure');
    }
    $assert($beforeRows === $groupRows($p['id']) && $view === $s['service']->getPlan($p['id']), 'Failed supervision write left partial generator changes.');
    $evidence['transaction'] = 'forced supervision-write failure rolled back completely';
    if ($output = getenv('ROSTER_ROUND_C_OUTPUT')) file_put_contents($output . '/automatic-school-evidence.json', json_encode($evidence, JSON_PRETTY_PRINT));
    echo "OK: KE/VS GeoFort first, explicit staff/School, ignored legacy preference, hard constraints, manual OPEN, finalization 2-5, other modules unchanged, transaction rollback.\n";
} finally {
    $db->drop();
}

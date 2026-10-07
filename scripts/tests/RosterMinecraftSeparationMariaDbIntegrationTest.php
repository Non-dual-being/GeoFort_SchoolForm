<?php
declare(strict_types=1);
require __DIR__ . '/Support/RosterLifecycleFixtures.php';

use GeoFort\Services\Dashboard\Roster\{RosterAutoGenerator, RosterStaffOptimizer, RosterStaffChange, RosterStaffManagementService, RosterPlanException};
use GeoFort\Services\Sql\RosterStaffManagementSqlRepository;

$db = DisposableBookingMariaDb::create('minecraft_separation');
$pdo = $db->pdo; $assert = RosterLifecycleFixtures::assert(...);
$keys = ['Minecraft-Klimaatspeurtocht', 'Minecraft-Programmeren'];
try {
    RosterLifecycleFixtures::schema($pdo); $s = RosterLifecycleFixtures::services($pdo);
    $management = new RosterStaffManagementService($pdo, $s['staff'], new RosterStaffManagementSqlRepository($pdo));
    $ids = [];
    foreach ($keys as $key) {
        $revision = (int) $pdo->query('SELECT revision FROM roster_staff_catalog_state')->fetchColumn();
        $created = $management->save(RosterStaffChange::fromArray(['name' => 'Alleen ' . $key, 'isActive' => true, 'employmentType' => 'paid',
            'canGuide' => true, 'canCook' => false, 'expectedCatalogRevision' => $revision,
            'preferences' => [['moduleKey' => $key, 'rank' => 1]]], true), 1, str_repeat('test-secret', 4));
        $ids[$key] = $created['member']['id'];
        $assert(array_column($created['member']['preferences'], 'moduleKey') === [$key], 'Create silently coupled skills');
        $assert(array_column($s['staff']->memberById($ids[$key])['preferences'], 'moduleKey') === [$key], 'Repository coupled skills');
    }
    $plan = $s['service']->createManual('Minecraft gescheiden', 'Testplaats', '2028-10-10', 'primairOnderwijs', 'dag', 28, 2, 1)['plan'];
    $planId = $plan['id']; $revision = $plan['revision'];
    $s['plans']->saveManualModuleKeys($planId, $keys);
    $s['staff']->addPlanSelection($planId, array_values($ids));
    $s['staff']->savePlanSettings($planId, 'with_staff', true, null);
    $rounds = $s['templates']->defaultRounds('dag', 2);
    $optimizer = new RosterStaffOptimizer($s['staff']);
    $generator = new RosterAutoGenerator($pdo, $s['bookings'], $s['plans'], $s['sessions'], $s['config'], $s['templates'], $s['staff'], $optimizer);
    $preview = $generator->preview($planId, $rounds, array_values($ids));
    foreach ($preview['sessions'] as $session) {
        $assert($session['assignedStaffIds'] === [$ids[$session['moduleKey']]], 'Generator substituted the other Minecraft skill');
    }
    foreach ($keys as $i => $key) {
        $other = $keys[1 - $i];
        $task = [['moduleKey' => $key, 'startTime' => '10:15', 'endTime' => '11:00', 'minimumGeoFortStaff' => 1, 'schoolSupervisionAllowed' => false]];
        $wrong = $optimizer->optimize($task, [$ids[$other]], true, null);
        $assert($wrong['unfilledRequiredAssignments'] === 1, 'Optimizer used the other Minecraft skill');
        $correct = $optimizer->optimize($task, [$ids[$key]], true, null);
        $assert($correct['unfilledRequiredAssignments'] === 0, 'Optimizer rejected correct Minecraft skill');
        try {
            $s['sessionService']->saveActivity($planId, null, $revision, $key, '10:15', '11:00', null, [$plan['groups'][$i]['id']], 1, [$ids[$other]]);
            throw new LogicException('Manual staffing accepted the other Minecraft skill');
        } catch (RosterPlanException $e) { $assert($e->publicCode === 'STAFF_MODULE_NOT_ALLOWED', 'Unexpected manual rejection: ' . $e->publicCode); }
        $assert((int) $pdo->query('SELECT revision FROM roster_plans WHERE id=' . $planId)->fetchColumn() === $revision, 'Rejected assignment mutated revision');
    }
    $sessions = [];
    foreach ($rounds as $r => $round) foreach ($plan['groups'] as $g => $group) {
        $key = $keys[($r + $g) % 2];
        $saved = $s['sessionService']->saveActivity($planId, null, $revision, $key, $round['startTime'], $round['endTime'], null, [$group['id']], 1, [$ids[$key]]);
        $revision = $saved['revision']; $sessions[$key][] = $saved['sessionId'];
    }
    $valid = $s['validator']->validate($planId, $revision);
    $assert($valid->isValid(), 'Valid asymmetric plan rejected: ' . json_encode($valid->errors));
    // Simulate corrupted/manual stored assignments and test the authoritative finalization validator in BOTH directions.
    foreach ($keys as $i => $key) {
        $sessionId = $sessions[$key][0];
        $s['staff']->replaceSessionAssignments($sessionId, [$ids[$keys[1 - $i]]], 'manual');
        $invalid = $s['validator']->validate($planId, $revision);
        $assert(in_array('STAFF_NOT_QUALIFIED', array_column($invalid->errors, 'code'), true), 'Final validation substitutes Minecraft skills');
        $s['staff']->replaceSessionAssignments($sessionId, [$ids[$key]], 'manual');
    }
    $s['lifecycle']->finalize($planId, $revision, 1);
    $snapshotId = (int) $pdo->query('SELECT published_snapshot_id FROM roster_plans WHERE id=' . $planId)->fetchColumn();
    $frozen = $s['snapshots']->find($planId, $snapshotId);
    $finalView = $s['service']->getPlan($planId);
    $finalValidation = $s['validator']->validate($planId, $revision + 1)->toArray();
    // Later catalog changes never rewrite a published snapshot or revalidate its frozen staff.
    $pdo->prepare('DELETE FROM roster_staff_module_preferences WHERE staff_id=?')->execute([$ids[$keys[0]]]);
    $assert($s['snapshots']->find($planId, $snapshotId) === $frozen, 'Minecraft skill change rewrote final snapshot');
    $assert($s['service']->getPlan($planId)['planning'] === $finalView['planning'], 'Published presentation used changed current skills');
    $assert($s['validator']->validate($planId, $revision + 1)->toArray() === $finalValidation, 'Final snapshot was revalidated against current skills');
    echo "PASS: separate create/storage, both asymmetric optimizer/generator/manual/final-validation directions, immutable final snapshot.\n";
} finally { $db->drop(); }

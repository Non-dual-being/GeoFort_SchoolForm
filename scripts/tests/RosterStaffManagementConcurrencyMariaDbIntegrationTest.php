<?php

declare(strict_types=1);
require __DIR__ . '/Support/RosterLifecycleFixtures.php';

use GeoFort\Services\Dashboard\Roster\{RosterStaffChange, RosterStaffManagementService, RosterStaffCatalogGuard};
use GeoFort\Services\Sql\RosterStaffManagementSqlRepository;

if (getenv('STATUS_TEST_DB_CONFIRM') !== 'YES_DISPOSABLE') { echo "SKIP disposable consent required\n"; exit(0); }
$db = DisposableBookingMariaDb::create('staff_concurrency'); $pdo = $db->pdo; $workers = [];
$assert = RosterLifecycleFixtures::assert(...); $secret = str_repeat('private-concurrency', 3);
try {
    RosterLifecycleFixtures::schema($pdo); $s = RosterLifecycleFixtures::services($pdo);
    $management = new RosterStaffManagementService($pdo, $s['staff'], new RosterStaffManagementSqlRepository($pdo));
    $payload = static function (int $id, array $changes) use ($management, $secret): array {
        $current = $management->show($id); $m = $current['member'];
        $body = array_replace(['staffId' => $id, 'name' => $m['name'], 'isActive' => $m['isActive'], 'employmentType' => $m['employmentType'], 'canGuide' => $m['canGuide'], 'canCook' => $m['canCook'],
            'preferences' => array_map(static fn (array $p): array => ['moduleKey' => $p['moduleKey'], 'rank' => $p['rank']], $m['preferences']),
            'expectedVersion' => $m['version'], 'expectedCatalogRevision' => $current['catalogRevision']], $changes);
        $body['impactToken'] = $management->preview(RosterStaffChange::fromArray($body), 1, $secret)['impactToken'];
        return ['action' => 'save', 'payload' => $body, 'secret' => $secret];
    };
    $start = static function (array $input) use ($db, &$workers, $assert): int {
        $process = proc_open([PHP_BINARY, __DIR__ . '/Support/RosterStaffManagementWorker.php', $db->database], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, null, ['bypass_shell' => true]);
        if (!is_resource($process)) throw new RuntimeException('Worker start failed.');
        stream_set_timeout($pipes[1], 20);
        $assert(trim((string) fgets($pipes[1])) === 'READY', 'Independent worker ready');
        fwrite($pipes[0], json_encode($input, JSON_THROW_ON_ERROR) . "\n"); fclose($pipes[0]);
        $workers[] = [$process, $pipes]; return array_key_last($workers);
    };
    $finish = static function (int $id) use (&$workers, $assert): string {
        [$process, $pipes] = $workers[$id];
        $out = stream_get_contents($pipes[1]); $error = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
        $exit = proc_close($process); unset($workers[$id]); $assert($exit === 0, 'Worker failed ' . $out . $error);
        return json_decode(trim($out), true, 32, JSON_THROW_ON_ERROR)['code'];
    };

    $first = $payload(20, ['name' => 'Cook race A']); $second = $payload(20, ['name' => 'Cook race B']);
    $pdo->beginTransaction(); RosterStaffCatalogGuard::lock($pdo);
    $a = $start($first); $b = $start($second); usleep(180000);
    foreach ($workers as [$process]) $assert(proc_get_status($process)['running'], 'Personnel mutation waits on shared catalog lock');
    $pdo->commit();
    $codes = [$finish($a), $finish($b)]; sort($codes);
    $assert($codes === ['STALE_CATALOG', 'SUCCESS'], 'Exactly one concurrent catalog edit commits: ' . json_encode($codes));
    $assert((int) $pdo->query('SELECT COUNT(*) FROM roster_staff_change_history')->fetchColumn() === 1, 'Exactly one audit record');

    $plan = RosterLifecycleFixtures::validPlan($pdo, $s);
    $staffId = $plan['planning']['sessions'][0]['staffAssignments'][0]['id'];
    $change = $payload($staffId, ['isActive' => false]);
    $change['action'] = 'deactivate';
    $pdo->beginTransaction(); RosterStaffCatalogGuard::lock($pdo);
    $a = $start($change); $b = $start(['action' => 'finalize', 'planId' => $plan['id'], 'revision' => $plan['revision']]);
    usleep(180000);
    foreach ($workers as [$process]) $assert(proc_get_status($process)['running'], 'Finalizer and catalog update share first lock');
    $pdo->commit(); $editCode = $finish($a); $finalCode = $finish($b);
    $assert(($editCode === 'SUCCESS' && $finalCode === 'ROSTER_INVALID') || ($editCode === 'STALE_STAFF_IMPACT' && $finalCode === 'SUCCESS'), 'No stale confirmation/finalization wins race: ' . $editCode . '/' . $finalCode);

    // Preview sees an unreferenced staff member; a new concept selection must invalidate it.
    $plan2 = RosterLifecycleFixtures::validPlan($pdo, $s, 28, 2, 2, '2028-10-12');
    $change = $payload(1, ['isActive' => false]);
    $change['action'] = 'deactivate';
    $worker = $start(['action' => 'selection', 'planId' => $plan2['id'], 'revision' => $plan2['revision'], 'catalogRevision' => RosterStaffCatalogGuard::revision($pdo), 'staffIds' => [...$s['staff']->selectedIdsForPlan($plan2['id']), 1]]);
    $assert($finish($worker) === 'SUCCESS', 'Independent new day selection commits');
    $worker = $start($change); $assert($finish($worker) === 'STALE_STAFF_IMPACT', 'New assignment/selection invalidates previous impact');

    $plan3 = RosterLifecycleFixtures::validPlan($pdo, $s, 28, 2, 2, '2028-10-13');
    $final = $s['lifecycle']->finalize($plan3['id'], $plan3['revision'], 1);
    $used = $plan3['planning']['sessions'][0]['staffAssignments'][0]['id'];
    $change = $payload($used, ['canGuide' => false]);
    $worker = $start(['action' => 'reopen', 'planId' => $final['id'], 'revision' => $final['revision']]);
    $assert($finish($worker) === 'SUCCESS', 'Independent reopen succeeds');
    $worker = $start($change); $assert($finish($worker) === 'STALE_STAFF_IMPACT', 'Reopening a previously excluded final invalidates impact confirmation');
    $change = $payload(20, ['isActive' => false]); $change['action'] = 'deactivate';
    $auditBefore = (int) $pdo->query('SELECT COUNT(*) FROM roster_staff_change_history')->fetchColumn();
    $pdo->beginTransaction(); RosterStaffCatalogGuard::lock($pdo);
    $a = $start($change); $b = $start($change); usleep(180000); $pdo->commit();
    $codes = [$finish($a), $finish($b)]; sort($codes);
    $assert($codes === ['STALE_CATALOG', 'SUCCESS'], 'Exactly one dedicated deactivation commits');
    $assert((int) $pdo->query('SELECT COUNT(*) FROM roster_staff_change_history')->fetchColumn() === $auditBefore + 1, 'One deactivation audit under concurrent requests');
    echo "PASS independent MariaDB connections: catalog CAS, shared locks, finalization race, new selection and reopen invalidate impact\n";
} finally {
    if ($pdo->inTransaction()) $pdo->rollBack();
    foreach ($workers as [$process, $pipes]) { proc_terminate($process); foreach ($pipes as $pipe) if (is_resource($pipe)) fclose($pipe); proc_close($process); }
    $db->drop();
}

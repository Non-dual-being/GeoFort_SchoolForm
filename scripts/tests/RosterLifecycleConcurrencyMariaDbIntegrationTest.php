<?php

declare(strict_types=1);
require __DIR__ . '/Support/RosterLifecycleFixtures.php';

$db = DisposableBookingMariaDb::create('roster_v1_concurrency'); $workers = [];
try {
    RosterLifecycleFixtures::schema($db->pdo); $s = RosterLifecycleFixtures::services($db->pdo); $assert = RosterLifecycleFixtures::assert(...);
    $a = RosterLifecycleFixtures::validPlan($db->pdo, $s);
    $b = RosterLifecycleFixtures::validPlan($db->pdo, $s);
    foreach ($b['planning']['sessions'] as $index => $session) {
        $s['staff']->replaceSessionAssignments($session['id'], array_column($a['planning']['sessions'][$index]['staffAssignments'], 'id'), 'manual');
    }
    $check = $s['lifecycle']->validate($a['id'], 1);
    $assert($check['valid'] && in_array('OTHER_PLAN_STAFF_CONFLICT', array_column($check['warnings'], 'code'), true), 'Other concept causes a hard block or misses warning.');
    $db->pdo->beginTransaction();
    (new GeoFort\Services\Sql\BookingDaySettingsSqlRepository($db->pdo))->lockDate($a['visitDate']);
    foreach ([$a, $b] as $plan) {
        $process = proc_open([PHP_BINARY, __DIR__ . '/Support/RosterLifecycleWorker.php', $db->database, (string) $plan['id'], '1'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) throw new RuntimeException('Could not start concurrency worker.');
        fclose($pipes[0]); stream_set_timeout($pipes[1], 15);
        $workers[] = [$process, $pipes, $plan['id']];
        $assert(trim((string) fgets($pipes[1])) === 'READY', 'Worker did not reach independent connection.');
    }
    usleep(150000);
    foreach ($workers as [$process]) $assert(proc_get_status($process)['running'], 'Finalizer did not wait for date lock.');
    $assert((int) $db->pdo->query('SELECT COUNT(*) FROM roster_plan_snapshots')->fetchColumn() === 0, 'Finalization escaped date lock.');
    $db->pdo->commit();
    $results = [];
    foreach ($workers as [$process, $pipes, $id]) {
        $output = stream_get_contents($pipes[1]); $error = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
        $exit = proc_close($process); $assert($exit === 0, 'Worker failed: ' . $output . $error);
        $results[$id] = json_decode(trim($output), true, 64, JSON_THROW_ON_ERROR);
    }
    $workers = [];
    $codes = array_column($results, 'code'); sort($codes);
    $assert($codes === ['ROSTER_INVALID', 'SUCCESS'], 'Conflicting plans both finalized: ' . json_encode($results));
    $winner = array_keys(array_filter($results, static fn (array $r): bool => $r['code'] === 'SUCCESS'))[0];
    $loser = $winner === $a['id'] ? $b['id'] : $a['id'];
    $assert((int) $db->pdo->query('SELECT COUNT(*) FROM roster_plan_snapshots')->fetchColumn() === 1, 'Race appended unexpected history.');
    $hard = $s['lifecycle']->validate($loser, 1);
    $assert(in_array('OTHER_PLAN_STAFF_CONFLICT', array_column($hard['errors'], 'code'), true), 'Current final staff conflict missing.');
    $s['lifecycle']->reopen($winner, 2, 1);
    $assert($s['lifecycle']->validate($loser, 1)['valid'], 'Historical reopened snapshot still blocks current planning.');
    echo "OK: two independent MariaDB connections serialize on date; one final wins, draft warnings remain soft, reopened history is not current.\n";
} finally {
    if ($db->pdo->inTransaction()) $db->pdo->rollBack();
    foreach ($workers as [$process, $pipes]) { if (is_resource($process)) proc_terminate($process); foreach ($pipes as $pipe) if (is_resource($pipe)) fclose($pipe); if (is_resource($process)) proc_close($process); }
    $db->drop();
}

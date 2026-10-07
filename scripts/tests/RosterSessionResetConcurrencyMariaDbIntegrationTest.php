<?php

declare(strict_types=1);
require __DIR__ . '/Support/RosterSessionResetFixtures.php';

use GeoFort\Services\Dashboard\Roster\{RosterStaffCatalogGuard, RosterStaffManagementService, RosterStaffChange};
use GeoFort\Services\Sql\RosterStaffManagementSqlRepository;

if (getenv('STATUS_TEST_DB_CONFIRM') !== 'YES_DISPOSABLE') { echo "SKIP disposable consent required\n"; exit(0); }
$db = DisposableBookingMariaDb::create('reset_concurrency'); $pdo = $db->pdo; $workers = [];
$assert = RosterLifecycleFixtures::assert(...);
try {
    RosterLifecycleFixtures::schema($pdo); $s = RosterSessionResetFixtures::services($pdo);
    $start = static function (array $input) use ($db, &$workers, $assert): int {
        $process = proc_open([PHP_BINARY, '-d', 'xdebug.mode=off', '-d', 'zend.exception_ignore_args=1', __DIR__ . '/Support/RosterSessionResetWorker.php', $db->database],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, null, ['bypass_shell' => true]);
        if (!is_resource($process)) throw new RuntimeException('Worker failed to start.');
        stream_set_timeout($pipes[1], 20);
        $assert(trim((string) fgets($pipes[1])) === 'READY', 'Independent worker ready');
        fwrite($pipes[0], json_encode($input, JSON_THROW_ON_ERROR) . "\n"); fclose($pipes[0]);
        $workers[] = [$process, $pipes]; return array_key_last($workers);
    };
    $finish = static function (int $id) use (&$workers, $assert): array {
        [$process, $pipes] = $workers[$id];
        $out = stream_get_contents($pipes[1]); $error = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
        $exit = proc_close($process); unset($workers[$id]);
        $assert($exit === 0, 'Worker failure: ' . substr($out . $error, 0, 2000));
        return json_decode(trim($out), true, 16, JSON_THROW_ON_ERROR);
    };
    $race = static function (array $first, array $second) use ($pdo, &$workers, $start, $finish, $assert): array {
        $pdo->beginTransaction(); RosterStaffCatalogGuard::lock($pdo);
        $a = $start($first); $b = $start($second); usleep(180000);
        foreach ($workers as [$process]) $assert(proc_get_status($process)['running'], 'Both actions wait for the shared catalog lock');
        $pdo->commit();
        return [$finish($a), $finish($b)];
    };

    $proof = [];
    foreach (['reset', 'finalize', 'session', 'generate'] as $index => $action) {
        $date = ['2028-11-06', '2028-11-13', '2028-11-20', '2028-11-27'][$index];
        $plan = RosterLifecycleFixtures::validPlan($pdo, $s, 28, 2, 2, $date);
        $first = ['action' => 'reset', 'planId' => $plan['id'], 'revision' => $plan['revision']];
        $second = [...$first, 'action' => $action, 'session' => $plan['planning']['sessions'][0],
            'rounds' => $plan['planning']['generation']['defaultRounds'], 'staffIds' => $plan['planning']['selectedStaffIds'],
            'catalogRevision' => $plan['planning']['catalogRevision']];
        $catalogBefore = RosterStaffCatalogGuard::revision($pdo);
        [$a, $b] = $race($first, $second);
        $codes = [$a['code'], $b['code']];
        $assert(count(array_filter($codes, static fn (string $code): bool => $code === 'SUCCESS')) === 1, 'Exactly one reset/' . $action . ' action succeeds: ' . implode('/', $codes));
        foreach ($codes as $code) $assert(in_array($code, ['SUCCESS', 'ROSTER_REVISION_CONFLICT', 'STALE_REVISION', 'PLAN_NOT_EDITABLE'], true), 'Expected conflict, no deadlock or partial failure');
        $current = $s['service']->getPlan($plan['id']);
        $assert($current['revision'] === $plan['revision'] + 1, 'One revision step for competing operations');
        $assert(RosterStaffCatalogGuard::revision($pdo) === $catalogBefore, 'Plan mutation does not advance catalog revision');
        if ($a['code'] === 'SUCCESS') {
            $assert($current['status'] === 'concept' && $current['planning']['sessions'] === [], 'Winning reset fully empties concept');
        } else {
            $assert(count($current['planning']['sessions']) > 0, 'Losing reset deletes no sessions');
            if ($action === 'finalize') $assert($current['status'] === 'published' && $current['finalization']['trusted'], 'Winning finalization retains snapshot and sessions');
        }
        $proof[$action] = $codes;
    }

    // Two current-revision requests on an empty concept are both harmless no-ops.
    $empty = $s['service']->createManual('Empty concurrency', 'Plaats', '2028-12-04', 'primairOnderwijs', 'dag', 28, 2, 1)['plan'];
    $before = RosterSessionResetFixtures::state($pdo);
    $input = ['action' => 'reset', 'planId' => $empty['id'], 'revision' => $empty['revision']];
    [$a, $b] = $race($input, $input);
    $assert($a === ['code' => 'SUCCESS', 'alreadyEmpty' => true] && $b === $a, 'Concurrent empty resets both return no-op');
    $assert(RosterSessionResetFixtures::state($pdo) === $before, 'Concurrent no-ops change no database fields');

    // Ronde C impact confirmation must observe removal of session references.
    $plan = RosterLifecycleFixtures::validPlan($pdo, $s, 28, 2, 2, '2028-12-05');
    $memberId = $plan['planning']['sessions'][0]['staffAssignments'][0]['id'];
    $management = new RosterStaffManagementService($pdo, $s['staff'], new RosterStaffManagementSqlRepository($pdo));
    $detail = $management->show($memberId); $member = $detail['member']; $secret = str_repeat('reset-concurrency-fixture', 3);
    $payload = ['staffId' => $memberId, 'expectedVersion' => $member['version'], 'expectedCatalogRevision' => $detail['catalogRevision'],
        'name' => $member['name'], 'isActive' => $member['isActive'], 'employmentType' => $member['employmentType'],
        'canGuide' => false, 'canCook' => $member['canCook'],
        'preferences' => array_map(static fn (array $p): array => ['moduleKey' => $p['moduleKey'], 'rank' => $p['rank']], $member['preferences'])];
    $payload['impactToken'] = $management->preview(RosterStaffChange::fromArray($payload), 1, $secret)['impactToken'];
    [$reset, $staff] = $race(['action' => 'reset', 'planId' => $plan['id'], 'revision' => $plan['revision']],
        ['action' => 'staff', 'payload' => $payload, 'secret' => $secret]);
    $assert($reset['code'] === 'SUCCESS' && in_array($staff['code'], ['SUCCESS', 'STALE_STAFF_IMPACT'], true), 'Reset/personnel race respects shared lock and impact revision');
    $assert($s['service']->getPlan($plan['id'])['planning']['sessions'] === []
        && in_array($memberId, $s['staff']->selectedIdsForPlan($plan['id']), true), 'Reset preserves explicit day selection during personnel race');
    $assert(RosterStaffCatalogGuard::revision($pdo) === $detail['catalogRevision'] + ($staff['code'] === 'SUCCESS' ? 1 : 0), 'Only actual personnel change may advance catalog revision');
    $proof['staff'] = [$reset['code'], $staff['code']];
    if ($output = getenv('ROSTER_ROUND_D_OUTPUT')) file_put_contents($output . '/reset-concurrency-proof.json', json_encode($proof, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    echo "PASS independent connections: reset/reset, empty no-ops, finalization, session edit, generation, personnel impact; no deadlocks\n";
} finally {
    if ($pdo->inTransaction()) $pdo->rollBack();
    foreach ($workers as [$process, $pipes]) { proc_terminate($process); foreach ($pipes as $pipe) if (is_resource($pipe)) fclose($pipe); proc_close($process); }
    $db->drop();
}

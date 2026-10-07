<?php

declare(strict_types=1);
require __DIR__ . '/RosterSessionResetFixtures.php';
$database = $argv[1] ?? '';
if (!str_starts_with($database, 'geofort_reset_concurrency_disposable_test_')) throw new RuntimeException('Invalid worker database.');
$pdo = RosterSessionResetFixtures::connection($database);
$s = RosterSessionResetFixtures::services($pdo);
echo "READY\n"; flush();
$input = json_decode(trim(fgets(STDIN)), true, 32, JSON_THROW_ON_ERROR);
try {
    $result = match ($input['action']) {
        'reset' => $s['reset']->reset($input['planId'], $input['revision'], 1),
        'finalize' => $s['lifecycle']->finalize($input['planId'], $input['revision'], 1),
        'session' => $s['sessionService']->saveActivity($input['planId'], $input['session']['id'], $input['revision'],
            $input['session']['moduleKey'], $input['session']['startTime'], $input['session']['endTime'], 'Concurrent location',
            $input['session']['groupIds'], 1, array_column($input['session']['staffAssignments'], 'id')),
        'generate' => $s['generator']->apply($input['planId'], $input['revision'], $input['rounds'], true, 1, $input['staffIds'], 'with_staff', true, null, [], $input['catalogRevision']),
        'staff' => (new GeoFort\Services\Dashboard\Roster\RosterStaffManagementService($pdo, $s['staff'], new GeoFort\Services\Sql\RosterStaffManagementSqlRepository($pdo)))
            ->save(GeoFort\Services\Dashboard\Roster\RosterStaffChange::fromArray($input['payload']), 1, $input['secret']),
        default => throw new RuntimeException('Unknown worker action.'),
    };
    echo json_encode(['code' => 'SUCCESS', 'alreadyEmpty' => $result['alreadyEmpty'] ?? null], JSON_THROW_ON_ERROR), "\n";
} catch (GeoFort\Services\Dashboard\Roster\RosterPlanException $e) {
    echo json_encode(['code' => $e->publicCode], JSON_THROW_ON_ERROR), "\n";
}


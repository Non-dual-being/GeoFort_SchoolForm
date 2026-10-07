<?php

declare(strict_types=1);
require __DIR__ . '/RosterLifecycleFixtures.php';

$database = $argv[1] ?? '';
if (getenv('STATUS_TEST_DB_CONFIRM') !== 'YES_DISPOSABLE' || preg_match('/^geofort_staff_concurrency_disposable_test_[a-f0-9]{8}$/D', $database) !== 1) throw new RuntimeException('Disposable worker guard.');
$host = (string) (getenv('STATUS_TEST_DB_HOST') ?: '127.0.0.1');
if (!in_array($host, ['127.0.0.1', 'localhost'], true)) throw new RuntimeException('Local worker only.');
$port = (string) (getenv('STATUS_TEST_DB_PORT') ?: '3307');
$pdo = new PDO("mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4", getenv('STATUS_TEST_DB_USER') ?: 'root', getenv('STATUS_TEST_DB_PASSWORD') ?: '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]);
$s = RosterLifecycleFixtures::services($pdo);
echo "READY\n"; flush();
$input = json_decode(trim(fgets(STDIN)), true, 32, JSON_THROW_ON_ERROR);
try {
    if ($input['action'] === 'finalize') $result = $s['lifecycle']->finalize($input['planId'], $input['revision'], 1);
    elseif ($input['action'] === 'reopen') $result = $s['lifecycle']->reopen($input['planId'], $input['revision'], 1);
    elseif ($input['action'] === 'selection') $result = (new GeoFort\Services\Dashboard\Roster\RosterPlanStaffSelectionService($pdo, $s['plans'], $s['staff']))->save($input['planId'], $input['revision'], $input['catalogRevision'], $input['staffIds'], 1);
    elseif ($input['action'] === 'save') $result = (new GeoFort\Services\Dashboard\Roster\RosterStaffManagementService($pdo, $s['staff'], new GeoFort\Services\Sql\RosterStaffManagementSqlRepository($pdo)))
        ->save(GeoFort\Services\Dashboard\Roster\RosterStaffChange::fromArray($input['payload']), 1, $input['secret']);
    elseif ($input['action'] === 'deactivate') $result = (new GeoFort\Services\Dashboard\Roster\RosterStaffManagementService($pdo, $s['staff'], new GeoFort\Services\Sql\RosterStaffManagementSqlRepository($pdo)))
        ->deactivate($input['payload']['staffId'], $input['payload']['expectedVersion'], $input['payload']['expectedCatalogRevision'], $input['payload']['impactToken'], 1, $input['secret']);
    else throw new RuntimeException('Unknown test action');
    echo json_encode(['code' => 'SUCCESS'], JSON_THROW_ON_ERROR) . "\n";
} catch (GeoFort\Services\Dashboard\Roster\RosterPlanException $e) {
    echo json_encode(['code' => $e->publicCode], JSON_THROW_ON_ERROR) . "\n";
}

<?php

declare(strict_types=1);
require __DIR__ . '/RosterLifecycleFixtures.php';

$database = $argv[1] ?? '';
if (getenv('STATUS_TEST_DB_CONFIRM') !== 'YES_DISPOSABLE' || preg_match('/^geofort_roster_v1_concurrency_disposable_test_[a-f0-9]{8}$/D', $database) !== 1) throw new RuntimeException('Disposable worker guard.');
$host = (string) (getenv('STATUS_TEST_DB_HOST') ?: '127.0.0.1');
if (!in_array($host, ['127.0.0.1', 'localhost'], true)) throw new RuntimeException('Local worker only.');
$port = (string) (getenv('STATUS_TEST_DB_PORT') ?: '3307');
$pdo = new PDO("mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4", getenv('STATUS_TEST_DB_USER') ?: 'root', getenv('STATUS_TEST_DB_PASSWORD') ?: '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]);
$s = RosterLifecycleFixtures::services($pdo);
echo "READY\n"; flush();
try {
    $plan = $s['lifecycle']->finalize((int) $argv[2], (int) $argv[3], 1);
    echo json_encode(['code' => 'SUCCESS', 'revision' => $plan['revision']], JSON_THROW_ON_ERROR) . "\n";
} catch (GeoFort\Services\Dashboard\Roster\RosterPlanException $e) {
    echo json_encode(['code' => $e->publicCode, 'validation' => $e->validation], JSON_THROW_ON_ERROR) . "\n";
}

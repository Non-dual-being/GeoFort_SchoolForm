<?php
declare(strict_types=1);
require __DIR__ . '/RosterLifecycleFixtures.php';
if (getenv('STATUS_TEST_DB_CONFIRM') !== 'YES_DISPOSABLE') throw new RuntimeException('Disposable confirmation required');
$action = $argv[1] ?? '';
if ($action === 'create') {
    $db = DisposableBookingMariaDb::create('four_public');
    try {
        RosterLifecycleFixtures::schema($db->pdo);
        $db->pdo->exec('CREATE TABLE roosters(id INT PRIMARY KEY AUTO_INCREMENT,schooltype VARCHAR(30),keuzemodule VARCHAR(120),leerlingen_min INT,leerlingen_max INT,programmaduur VARCHAR(20),afbeelding VARCHAR(255),pdf VARCHAR(255))');
        // Keep a real, existing legacy asset in the legacy lookup table.
        $db->pdo->exec("INSERT INTO roosters(schooltype,keuzemodule,leerlingen_min,leerlingen_max,programmaduur,afbeelding,pdf) VALUES ('bovenbouw','Stop-de-Klimaat-Klok',51,65,'dag','images/vo_boven_sdk_4.png','pdf/vo_boven_sdk_4.pdf')");
        echo json_encode(['database'=>$db->database]);
    } catch (Throwable $e) { $db->drop(); throw $e; }
    exit;
}
$database = $argv[2] ?? '';
if (!preg_match('/^geofort_four_public_disposable_test_[a-f0-9]{8}$/', $database)) throw new RuntimeException('Unsafe database name');
$pdo = new PDO('mysql:host='.(getenv('STATUS_TEST_DB_HOST')?:'127.0.0.1').';port='.(getenv('STATUS_TEST_DB_PORT')?:'3307').';dbname='.$database.';charset=utf8mb4',getenv('STATUS_TEST_DB_USER')?:'root',getenv('STATUS_TEST_DB_PASSWORD')?:'',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
if ($action === 'drop') { $pdo->exec("DROP DATABASE `{$database}`"); echo '{}'; }
elseif ($action === 'close' || $action === 'open') {
    $pdo->exec('UPDATE booking_program_access SET five_module_open='.($action === 'open' ? 1 : 0).',revision=revision+1 WHERE id=1'); echo '{}';
} elseif ($action === 'report') {
    echo json_encode(['bookings'=>$pdo->query('SELECT id,schoolnaam,program_version,programma,keuzemodule_key,aantal_leerlingen,aantal_begeleiders FROM aanvragen ORDER BY id')->fetchAll(),
        'snapshots'=>$pdo->query('SELECT booking_id,calculation_details_json,total_amount_incl_vat_cents FROM booking_price_snapshots ORDER BY booking_id')->fetchAll()]);
} else throw new RuntimeException('Unknown fixture command');

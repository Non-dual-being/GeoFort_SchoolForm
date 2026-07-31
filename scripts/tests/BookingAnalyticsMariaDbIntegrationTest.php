<?php
declare(strict_types=1);

use GeoFort\Services\Dashboard\Booking\Analytics\BookingAnalyticsCriteriaFactory;
use GeoFort\Services\Dashboard\Booking\Analytics\BookingAnalyticsService;
use GeoFort\Services\Sql\BookingAnalyticsRepository;
use GeoFort\Services\Sql\BookingExportSqlRepository;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$env = [];
foreach (['HOST', 'PORT', 'NAME', 'USER'] as $key) {
    $value = getenv('STATUS_TEST_DB_' . $key);
    if ($value === false) {
        fwrite(STDERR, "SKIP: STATUS_TEST_DB_{$key} ontbreekt.\n");
        exit(0);
    }
    $env[$key] = (string) $value;
}
if (getenv('STATUS_TEST_DB_CONFIRM') !== 'YES_DISPOSABLE'
    || preg_match('/(test|tmp|scratch|disposable)/i', $env['NAME']) !== 1
    || strtolower((string) getenv('APP_ENV')) === 'production') {
    fwrite(STDERR, "FAIL: integratietest weigert niet-aantoonbaar wegwerpbare database.\n");
    exit(2);
}
$pdo = new PDO(
    sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $env['HOST'], $env['PORT'], $env['NAME']),
    $env['USER'],
    (string) (getenv('STATUS_TEST_DB_PASSWORD') ?: ''),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false],
);
$tables = ['booking_rule_overrides','booking_status_history','booking_change_history','aanvraag_onderwijs_selecties','aanvragen'];
$cleanup = static function () use ($pdo, $tables): void {
    $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
    try {
        foreach ($tables as $table) $pdo->exec("DROP TABLE IF EXISTS {$table}");
    } finally {
        $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
    }
};
$cleanup();
try {
    $pdo->exec('CREATE TABLE aanvragen (
        id INT AUTO_INCREMENT PRIMARY KEY, status VARCHAR(20), schoolnaam VARCHAR(255), postcode VARCHAR(16) DEFAULT "", land VARCHAR(40) DEFAULT "",
        bezoekdatum DATE, onderwijs_sector VARCHAR(40), programma VARCHAR(20),
        keuzemodule_key VARCHAR(120), aantal_leerlingen INT,
        remise_break INT DEFAULT 0, kazerne_break INT DEFAULT 0, fortgracht_break INT DEFAULT 0,
        glas_limonade INT DEFAULT 0, waterijsje INT DEFAULT 0, remise_lunch INT DEFAULT 0, eigen_picknick TINYINT DEFAULT 0
    ) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE aanvraag_onderwijs_selecties (
        id INT AUTO_INCREMENT PRIMARY KEY, aanvraag_id INT, level_key VARCHAR(80), group_key VARCHAR(80)
    ) ENGINE=InnoDB');
    foreach (['booking_change_history','booking_status_history','booking_rule_overrides'] as $table) {
        $pdo->exec("CREATE TABLE {$table} (id INT AUTO_INCREMENT PRIMARY KEY, booking_id INT, payload TEXT) ENGINE=InnoDB");
    }
    $insert = $pdo->prepare('INSERT INTO aanvragen(status,schoolnaam,postcode,land,bezoekdatum,onderwijs_sector,programma,keuzemodule_key,aantal_leerlingen,remise_break,remise_lunch,eigen_picknick) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)');
    foreach ([
        ['Definitief','A','1234 AB','Nederland','2025-12-15','primairOnderwijs','dag','Earth-Watch',50,50,0,0],
        ['In optie','B','2345 BC','Nederland','2026-01-12','voortgezetOnderbouw','dag','Klimaat-Mysterie',40,0,40,0],
        ['In optie','C','3456 CD','Nederland','2026-01-12','voortgezetBovenbouw','dag','Klimaat-Mysterie',30,30,30,0],
        ['In optie','D','4567 DE','Nederland','2026-01-14','primairOnderwijs','ochtend',null,20,0,0,1],
        ['Afgewezen','E','5678 EF','Nederland','2026-01-14','primairOnderwijs','dag','Earth-Watch',100,0,0,0],
        ['Definitief','  A ','1234ab',' nederland ','2027-03-01','primairOnderwijs','dag','Earth-Watch',80,0,0,0],
        ['In optie','F','6789 FG','Nederland','2027-03-01','primairOnderwijs','ochtend',null,81,0,0,0],
    ] as $row) $insert->execute($row);
    $selection = $pdo->prepare('INSERT INTO aanvraag_onderwijs_selecties(aanvraag_id,level_key,group_key) VALUES(?,?,?)');
    foreach ([[1,'regulier','groep7'],[1,'regulier','groep8'],[2,'havo','havo1'],[2,'vwo','atheneum1'],[3,'havo','havo4'],[4,'regulier','groep6']] as $row) {
        $selection->execute($row);
    }
    foreach (['booking_change_history','booking_status_history','booking_rule_overrides'] as $table) {
        for ($booking = 1; $booking <= 7; $booking++) {
            for ($child = 0; $child < 3; $child++) {
                $pdo->prepare("INSERT INTO {$table}(booking_id,payload) VALUES(?,?)")->execute([$booking, "child {$child}"]);
            }
        }
    }
    $boundsRepository = new BookingExportSqlRepository($pdo);
    $criteria = (new BookingAnalyticsCriteriaFactory())->create(
        ['startDate' => '2025-12-15', 'endDate' => '2027-03-01'],
        $boundsRepository->findDateBounds(),
    );
    $result = (new BookingAnalyticsService(
        new BookingAnalyticsRepository($pdo),
        $boundsRepository,
    ))->analyze($criteria)->toArray();
    if ($result['summary']['activeBookings'] !== 6 || $result['summary']['plannedStudents'] !== 301) {
        throw new RuntimeException('Booking- of leerlingtotalen zijn door childrecords vermenigvuldigd.');
    }
    if ($result['summary']['rejectedBookings'] !== 1 || count($result['monthlyTrend']) !== 3) {
        throw new RuntimeException('Status- of maandaggregatie is onjuist.');
    }
    if ($result['busiestVisitDates'][0]['date'] !== '2027-03-01'
        || $result['busiestVisitDates'][0]['plannedStudents'] !== 161) {
        throw new RuntimeException('Drukste bezoekdag is onjuist.');
    }
    if ($result['cateringAnalysis']['denominators']['schools'] !== 5
        || $result['seasonalityAnalysis']['totals']['students'] !== 301
        || array_sum(array_column($result['studentCountAnalysis']['programs'][0]['studentBins'], 'bookings')) !== 4) {
        throw new RuntimeException('Schooldeduplicatie, heatmap- of bandtotalen sluiten niet aan.');
    }
    fwrite(STDOUT, "Booking analytics MariaDB integration tests passed.\n");
} finally {
    $cleanup();
}

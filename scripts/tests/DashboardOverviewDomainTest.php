<?php
declare(strict_types=1);

use GeoFort\Services\Dashboard\Overview\DashboardOverviewService;
use GeoFort\Services\Sql\DashboardOverviewSqlRepository;

require dirname(__DIR__, 2) . '/vendor/autoload.php';
$failures = 0;
$assert = static function (bool $condition, string $message) use (&$failures): void { if (!$condition) { fwrite(STDERR, "FAIL: {$message}\n"); $failures++; } };
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE aanvragen (id INTEGER PRIMARY KEY, status TEXT, bezoekdatum TEXT, schoolnaam TEXT, programma TEXT, onderwijs_sector TEXT, aantal_leerlingen INTEGER)');
$insert = $pdo->prepare('INSERT INTO aanvragen VALUES (?, ?, ?, ?, ?, ?, ?)');
$rows = [
    [1,'In optie','2025-12-31','Verlopen','dag','primairOnderwijs',10],
    [2,'In optie','2026-01-05','School A','dag','primairOnderwijs',20],
    [3,'In optie','2026-01-05','School B','ochtend','voortgezetOnderbouw',30],
    [4,'In optie','2026-01-20','School C','dag','voortgezetBovenbouw',40],
    [5,'Definitief','2026-01-31','School D','dag','primairOnderwijs',50],
    [6,'Afgewezen','2026-01-01','School E','dag','voortgezetOnderbouw',null],
    [7,'Definitief','2026-02-01','Volgende maand','dag','primairOnderwijs',999],
    [8,'In optie',null,'Datumloos','dag','primairOnderwijs',5],
];
foreach ($rows as $row) $insert->execute($row);
$service = new DashboardOverviewService(new DashboardOverviewSqlRepository($pdo));
$data = $service->get(new DateTimeImmutable('2026-01-02 23:30:00', new DateTimeZone('Europe/Amsterdam')))->toArray();
$assert($data['timezone'] === 'Europe/Amsterdam' && $data['generatedForDate'] === '2026-01-02', 'Applicatiedatum of timezone klopt niet.');
$assert($data['options']['total'] === 5 && count($data['options']['items']) === 5, 'Optietotaal of limiet klopt niet.');
$assert($data['options']['items'][0]['expired'] === true, 'Verlopen optie ontbreekt of is niet gemarkeerd.');
$assert($data['options']['items'][4]['visitDate'] === null, 'Datumloze optie wordt niet veilig achteraan getoond.');
$assert($data['nextOption']['visitDate'] === '2026-01-05' && count($data['nextOption']['items']) === 2, 'Gelijke eerstvolgende bezoekdatums zijn niet volledig geselecteerd.');
$month = $data['currentMonth'];
$assert($month['year'] === 2026 && $month['month'] === 1 && $month['total'] === 5, 'Maandgrens rond jaarwisseling klopt niet.');
$assert($month['confirmed'] === 1 && $month['option'] === 3 && $month['rejected'] === 1, 'Statustotalen kloppen niet.');
$assert($month['students'] === 140 && $month['studentsPrimary'] === 70 && $month['studentsSecondary'] === 70, 'Leerling- of sectortotalen kloppen niet.');
$pdo->exec('DELETE FROM aanvragen');
$empty = $service->get(new DateTimeImmutable('2026-12-31', new DateTimeZone('Europe/Amsterdam')))->toArray();
$assert($empty['options']['total'] === 0 && $empty['nextOption']['visitDate'] === null && $empty['nextOption']['items'] === [], 'Lege toestand klopt niet.');
$assert($empty['currentMonth']['total'] === 0 && $empty['currentMonth']['students'] === 0, 'Lege maandtotalen kloppen niet.');
exit($failures === 0 ? 0 : 1);

<?php

declare(strict_types=1);

use GeoFort\Services\Dashboard\Calendar\DashboardCalendarOverviewService;
use GeoFort\Services\Http\Api\Admin\DashboardCalendarOverviewRequest;
use GeoFort\Services\Sql\DashboardCalendarOverviewSqlRepository;
use GeoFort\Services\Sql\DisabledDatesSqlService;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$failures = 0;
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) { fwrite(STDERR, "FAIL: {$message}\n"); $failures++; }
};

$assert(DashboardCalendarOverviewRequest::fromQuery(['year' => '2026', 'month' => '8'])?->month === 8, 'Geldige maand geweigerd.');
foreach ([
    ['year' => '2026', 'month' => '0'], ['year' => '2026', 'month' => '13'],
    ['year' => '1999', 'month' => '8'], ['year' => '2026', 'month' => '08'],
    ['year' => '2026', 'month' => '8', 'status' => 'Definitief'],
] as $invalid) $assert(DashboardCalendarOverviewRequest::fromQuery($invalid) === null, 'Ongeldige query geaccepteerd.');

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE aanvragen (id INTEGER PRIMARY KEY, bezoekdatum TEXT, status TEXT, programma TEXT, aantal_leerlingen INTEGER)');
$pdo->exec('CREATE TABLE disabled_dates (datum TEXT PRIMARY KEY, type TEXT, reden TEXT, source TEXT)');
$rows = [
    [1,'2026-08-03','In optie','dag',40], [2,'2026-08-03','In optie','ochtend',40],
    [3,'2026-08-03','Definitief','dag',null], [4,'2026-08-03','Afgewezen','dag',-10],
    [5,'2026-08-04','Definitief','dag',0], [6,'2026-08-05','Onbekend','dag',50],
    [7,'2026-08-05','In optie','legacy',50], [8,'2026-08-08','In optie','ochtend',20],
];
$insert = $pdo->prepare('INSERT INTO aanvragen VALUES (?, ?, ?, ?, ?)');
foreach ($rows as $row) $insert->execute($row);
$pdo->exec("INSERT INTO disabled_dates VALUES ('2026-08-03','manual','Privéreden','planner')");

$service = new DashboardCalendarOverviewService(new DashboardCalendarOverviewSqlRepository($pdo), new DisabledDatesSqlService($pdo));
$calendar = $service->get(2026, 8, new DateTimeImmutable('2026-08-04 12:00:00', new DateTimeZone('Europe/Amsterdam')))->toArray();
$assert($calendar['timezone'] === 'Europe/Amsterdam', 'Timezone wijkt af.');
$assert($calendar['period']['gridStart'] === '2026-07-27' && $calendar['period']['gridEnd'] === '2026-09-06', '42-daagse maandag-grid klopt niet.');
$assert(count($calendar['days']) === 42, 'Grid bevat niet exact 42 dagen.');
$day = array_values(array_filter($calendar['days'], static fn (array $item): bool => $item['date'] === '2026-08-03'))[0];
$assert($day['hasExcludedBookingsOnBlockedDate'] === true, 'Blocked planningboolean ontbreekt.');
$assert(!str_contains(json_encode($calendar, JSON_THROW_ON_ERROR), 'Privéreden'), 'Blokkadereden lekt naar response.');
$option = array_values(array_filter($day['aggregates'], static fn (array $item): bool => $item['status'] === 'In optie'));
$assert(count($option) === 2 && array_sum(array_column($option, 'studentCount')) === 80, 'Programmacube of leerlingensom klopt niet.');
$confirmed = array_values(array_filter($day['aggregates'], static fn (array $item): bool => $item['status'] === 'Definitief'))[0];
$assert($confirmed['studentCount'] === 0 && $confirmed['unknownStudentCount'] === 1, 'NULL-normalisatie klopt niet.');
$rejected = array_values(array_filter($day['aggregates'], static fn (array $item): bool => $item['status'] === 'Afgewezen'))[0];
$assert($rejected['studentCount'] === 0 && $rejected['invalidStudentCount'] === 1, 'Negatieve normalisatie klopt niet.');
$json = json_encode($calendar, JSON_THROW_ON_ERROR);
foreach (['schoolName','bookingId','email','phone','address','comments','reason'] as $privateField) $assert(!str_contains($json, $privateField), "Privacyveld {$privateField} aanwezig.");

exit($failures === 0 ? 0 : 1);

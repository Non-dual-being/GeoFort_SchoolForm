<?php
declare(strict_types=1);

use GeoFort\Services\Dashboard\Booking\Analytics\BookingAdvancedAnalyticsCalculator;
use GeoFort\Services\Dashboard\Booking\Analytics\BookingAnalyticsCriteria;
use GeoFort\Services\Dashboard\Booking\Export\BookingExportDateBounds;
use GeoFort\Services\Sql\BookingDaySettingsSqlRepository;
use GeoFort\Services\Sql\DisabledDatesSqlService;

require dirname(__DIR__, 2) . '/vendor/autoload.php';
$assert = static fn (bool $condition, string $message) => $condition ?: throw new RuntimeException($message);
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE disabled_dates (datum TEXT PRIMARY KEY, type TEXT, reden TEXT, source TEXT)');
$pdo->exec('CREATE TABLE booking_day_settings (visit_date TEXT PRIMARY KEY, max_schools_override INTEGER, max_students_override INTEGER)');
$pdo->exec("INSERT INTO disabled_dates VALUES ('2024-02-14','school_vacation','Vakantie','generated')");
$pdo->exec("INSERT INTO booking_day_settings VALUES ('2024-02-15',1,100)");
$criteria = new BookingAnalyticsCriteria('2024-02-10', '2024-03-10', '2024-02-10', '2024-03-10', new BookingExportDateBounds('2024-02-10', '2024-03-10'));
$booking = static fn (string $date, string $school, string $address, string $postcode, int $students = 40): array => ['visit_date'=>$date,'school'=>$school,'address'=>$address,'postal_code'=>$postcode,'city'=>'Leerdam','country'=>'Nederland','students'=>$students];
$rows = [
    $booking('2024-02-12',' School  A ','Dijk 1','4141 AA'),
    $booking('2024-02-13','school a','Dijk 1','4141AA'),
    $booking('2024-03-01','SCHOOL A','Dijk 1','4141 AA'),
    $booking('2024-03-04','School A','Dijk 2','4141 AA'),
    $booking('2024-03-05','School B','Dijk 1','4141 AA'),
];
$result = (new BookingAdvancedAnalyticsCalculator(new DisabledDatesSqlService($pdo), new BookingDaySettingsSqlRepository($pdo)))->calculate($rows, $criteria);
$assert(array_column($result['newSchoolsByMonth'], 'count') === [1, 2], 'Normalisatie, cumulatieve deduplicatie of adresidentiteit is onjuist.');
$february = $result['capacityByMonth'][0];
$assert($february['availableDays'] === 13, 'Weekenden, gedeeltelijke maand of disabled date wordt onjuist geteld.');
$assert($february['students']['actual'] === 80 && $february['bookingSlots']['actual'] === 2, 'Tellers gebruiken niet alle afzonderlijke bezoeken.');
$assert($february['students']['capacity'] === 2020 && $february['bookingSlots']['capacity'] === 25, 'Dagoverride of centrale dagcapaciteit is onjuist.');
$assert($february['students']['percentage'] > 0 && $february['students']['percentage'] < 100, 'Percentage is voortijdig afgerond.');

$morning = new BookingAnalyticsCriteria('2024-02-12', '2024-02-14', '2024-02-12', '2024-02-14', new BookingExportDateBounds('2024-02-12', '2024-02-14'), program: 'ochtend');
$morningResult = (new BookingAdvancedAnalyticsCalculator(new DisabledDatesSqlService($pdo), new BookingDaySettingsSqlRepository($pdo)))->calculate([], $morning)['capacityByMonth'][0];
$assert($morningResult['availableDays'] === 0 && $morningResult['students']['percentage'] === null, 'Programmaweekdag of nulnoemer is niet veilig.');

$over = new BookingAnalyticsCriteria('2024-03-06', '2024-03-06', '2024-03-06', '2024-03-06', new BookingExportDateBounds('2024-03-06', '2024-03-06'), program: 'ochtend');
$overRows = [$booking('2024-03-06','Een','A 1','1',100), $booking('2024-03-06','Twee','B 2','2',20)];
$overResult = (new BookingAdvancedAnalyticsCalculator(new DisabledDatesSqlService($pdo), new BookingDaySettingsSqlRepository($pdo)))->calculate($overRows, $over)['capacityByMonth'][0];
$assert($overResult['students']['percentage'] === 150.0 && $overResult['bookingSlots']['percentage'] === 100.0, 'Exact 100% of overbezetting wordt gecapt of verkeerd berekend.');
echo "Booking advanced analytics domain tests passed.\n";

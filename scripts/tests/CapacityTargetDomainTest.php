<?php
declare(strict_types=1);

use GeoFort\Dashboard\CapacityTarget\CapacityTarget;
use GeoFort\Dashboard\CapacityTarget\CapacityTargetPolicy;
use GeoFort\Services\Dashboard\Booking\Analytics\BookingAdvancedAnalyticsCalculator;
use GeoFort\Services\Dashboard\Booking\Analytics\BookingAnalyticsCriteria;
use GeoFort\Services\Dashboard\Booking\Analytics\CapacityTargetAnalyticsCalculator;
use GeoFort\Services\Dashboard\Booking\Export\BookingExportDateBounds;
use GeoFort\Services\Http\Api\Admin\CapacityTargetUpdateRequest;
use GeoFort\Services\Http\Api\Admin\CapacityTargetUpdateRequestException;
use GeoFort\Services\Sql\BookingDaySettingsSqlRepository;
use GeoFort\Services\Sql\CapacityTargetSqlRepository;
use GeoFort\Services\Sql\DisabledDatesSqlService;

require dirname(__DIR__, 2) . '/vendor/autoload.php';
$assert = static fn (bool $condition, string $message) => $condition ?: throw new RuntimeException($message);

$valid = CapacityTargetPolicy::validate('2026-08-07', 0, 0.0, '2026-08-07');
$assert($valid === [], 'Minimumwaarden of boekingtarget nul worden geweigerd.');
$assert(CapacityTargetPolicy::validate('2026-08-07', 160, 2.0, '2026-08-07') === [], 'Maximumwaarden worden geweigerd.');
$assert(isset(CapacityTargetPolicy::validate('2026-08-07', 161, 2.0, '2026-08-07')['studentsPerAvailableDay']), 'Leerlingtarget boven 160 wordt geaccepteerd.');
$assert(isset(CapacityTargetPolicy::validate('2026-08-07', 120, 2.1, '2026-08-07')['bookingsPerAvailableDay']), 'Boekingtarget boven 2 wordt geaccepteerd.');
$assert(isset(CapacityTargetPolicy::validate('2026-08-07', 120, 1.55, '2026-08-07')['bookingsPerAvailableDay']), 'Meer dan één decimaal wordt geaccepteerd.');
$assert(isset(CapacityTargetPolicy::validate('2026-08-07', null, null, '2026-08-07')['studentsPerAvailableDay']), 'Ontbrekende invoer wordt geaccepteerd.');
$assert(isset(CapacityTargetPolicy::validate('2026-08-06', 120, 1.5, '2026-08-07')['effectiveDate']), 'Target kan met terugwerkende kracht worden gewijzigd.');
$assert(CapacityTargetPolicy::canManage('admin') && CapacityTargetPolicy::canManage('planner') && !CapacityTargetPolicy::canManage('viewer'), 'Targetautorisatie is niet expliciet begrensd.');

$zero = new CapacityTarget(1, '2026-08-07', 120, 0.0, 1, 1, '2026-08-07 10:00:00.000000', '2026-08-07 10:00:00.000000');
$assert($zero->toArray()['derivedAverageStudentsPerBooking'] === null, 'Boekingtarget nul leidt tot delen door nul.');

$request = CapacityTargetUpdateRequest::fromJson('{"effectiveDate":"2026-08-07","studentsPerAvailableDay":120,"bookingsPerAvailableDay":1.5,"expectedUpdatedAt":null}');
$assert($request->studentsPerAvailableDay === 120 && $request->bookingsPerAvailableDay === 1.5, 'Geldige decimale request wordt niet behouden.');
try {
    CapacityTargetUpdateRequest::fromJson('{"effectiveDate":"2026-08-07","studentsPerAvailableDay":120,"bookingsPerAvailableDay":1.5,"expectedUpdatedAt":null,"extra":true}');
    throw new RuntimeException('Onbegrensde payload wordt geaccepteerd.');
} catch (CapacityTargetUpdateRequestException) {}

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE disabled_dates (datum TEXT PRIMARY KEY, type TEXT, reden TEXT, source TEXT)');
$pdo->exec('CREATE TABLE booking_day_settings (visit_date TEXT PRIMARY KEY, max_schools_override INTEGER, max_students_override INTEGER)');
$pdo->exec('CREATE TABLE capacity_targets (id INTEGER PRIMARY KEY, effective_date TEXT, students_per_available_day INTEGER, bookings_per_available_day NUMERIC, created_by_admin_id INTEGER, updated_by_admin_id INTEGER, created_at TEXT, updated_at TEXT)');
$pdo->exec("INSERT INTO disabled_dates VALUES ('2026-08-12','school_vacation','Vakantie','generated')");
foreach (['2026-08-10','2026-08-11','2026-08-13','2026-08-14'] as $date) $pdo->exec("INSERT INTO booking_day_settings VALUES ('{$date}',1,100)");
$pdo->exec("INSERT INTO capacity_targets VALUES
    (1,'2026-07-01',100,1.0,1,1,'2026-06-01 10:00:00.000000','2026-06-01 10:00:00.000000'),
    (2,'2026-08-10',120,1.5,1,2,'2026-08-01 10:00:00.000000','2026-08-02 10:00:00.000000')");
$booking = static fn (string $date, int $students): array => ['visit_date' => $date, 'school' => $date, 'address' => '', 'postal_code' => '', 'city' => '', 'country' => '', 'students' => $students];
$bookings = [$booking('2026-07-02', 50), $booking('2026-08-10', 80), $booking('2026-08-11', 40), $booking('2026-08-20', 100), $booking('2026-09-01', 30)];
$criteria = new BookingAnalyticsCriteria('2026-07-01', '2026-09-04', '2026-07-01', '2026-09-04', new BookingExportDateBounds('2026-07-01', '2026-09-04'));
$calculator = new BookingAdvancedAnalyticsCalculator(
    new DisabledDatesSqlService($pdo), new BookingDaySettingsSqlRepository($pdo),
    clock: new DateTimeImmutable('2026-08-15 12:00:00', new DateTimeZone('Europe/Amsterdam')),
);
$technical = $calculator->calculate($bookings, $criteria);
$result = (new CapacityTargetAnalyticsCalculator(new CapacityTargetSqlRepository($pdo)))->calculate(
    $technical['capacityByMonth'], $technical['capacityDaySnapshots'], $technical['analyticsToday'],
);
$july = $result['capacityTargetByMonth'][0];
$august = $result['capacityTargetByMonth'][1];
$september = $result['capacityTargetByMonth'][2];
$assert($july['periodState'] === 'closed' && $july['evaluatedAvailableDays'] === $july['availableDays'], 'Afgesloten maand gebruikt niet het volledige target.');
$assert($july['studentsTargetComparison']['target'] === $july['targetAvailableDays'] * 100, 'Latere targetwijziging verandert een eerdere rapportageperiode.');
$assert($august['periodState'] === 'current' && $august['evaluatedAvailableDays'] < $august['availableDays'], 'Lopende maand gebruikt niet uitsluitend dagen tot en met vandaag.');
$assert($august['targetAvailableDays'] === 9 && $august['studentsTargetComparison']['target'] === 980 && $august['bookingsTargetComparison']['target'] === 11.0, 'Effectief target wordt bij tussentijdse wijziging niet per beschikbare dag gewogen.');
$assert($august['studentsTargetComparison']['actual'] === 120 && $august['averageBookingSizeComparison']['actual'] === 60.0 && $august['averageBookingSizeComparison']['target'] === 980 / 11, 'Werkelijk of afgeleid gemiddeld aantal leerlingen per boeking klopt niet.');
$assert($september['periodState'] === 'future' && $september['assessmentAvailable'] === false && $september['studentsTargetComparison']['assessment'] === 'future', 'Toekomstige maand krijgt een misleidend onder-targetoordeel.');
$assert($result['capacityTargetContext']['status'] === 'available', 'Beschikbare targetopslag wordt als onbeschikbaar gemarkeerd.');
$assert(count($result['capacityTargetContext']['history']) === 2 && $result['capacityTargetContext']['currentOfficialTarget']['id'] === 2, 'Historische of huidige targetselectie klopt niet.');
$assert(!in_array('2026-08-12', array_column(array_filter($result['capacityTargetContext']['daySnapshots'], static fn (array $row): bool => $row['available']), 'date'), true), 'Gesloten datum telt als beschikbare targetdag.');
$morningCriteria = new BookingAnalyticsCriteria('2026-09-02', '2026-09-02', '2026-09-02', '2026-09-02', new BookingExportDateBounds('2026-09-02', '2026-09-02'), program: 'ochtend');
$morningTechnical = $calculator->calculate([], $morningCriteria);
$morning = (new CapacityTargetAnalyticsCalculator(new CapacityTargetSqlRepository($pdo)))->calculate(
    $morningTechnical['capacityByMonth'], $morningTechnical['capacityDaySnapshots'], $morningTechnical['analyticsToday'],
)['capacityTargetByMonth'][0];
$assert($morning['technicalCapacity']['students'] === 80 && $morning['studentsTargetComparison']['target'] === 120 && $morning['targetAboveTechnicalCapacity']['students'] === true, 'Target boven programmacapaciteit wordt niet apart gemarkeerd.');
$pdo->exec("INSERT INTO capacity_targets VALUES (3,'2026-09-03',100,0.0,1,1,'2026-08-02 10:00:00.000000','2026-08-02 10:00:00.000000')");
$zeroCriteria = new BookingAnalyticsCriteria('2026-09-03', '2026-09-03', '2026-09-03', '2026-09-03', new BookingExportDateBounds('2026-09-03', '2026-09-03'));
$zeroTechnical = $calculator->calculate([$booking('2026-09-03', 30)], $zeroCriteria);
$zeroAverage = (new CapacityTargetAnalyticsCalculator(new CapacityTargetSqlRepository($pdo)))->calculate(
    $zeroTechnical['capacityByMonth'], $zeroTechnical['capacityDaySnapshots'], $zeroTechnical['analyticsToday'],
)['capacityTargetByMonth'][0]['averageBookingSizeComparison'];
$assert($zeroAverage['target'] === null && $zeroAverage['assessment'] === 'unavailable', 'Afgeleid periodetarget deelt bij boekingtarget nul toch of wordt als ontbrekend target behandeld.');

$emptyPdo = new PDO('sqlite::memory:');
$emptyPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$emptyPdo->exec('CREATE TABLE disabled_dates (datum TEXT PRIMARY KEY, type TEXT, reden TEXT, source TEXT)');
$emptyPdo->exec('CREATE TABLE booking_day_settings (visit_date TEXT PRIMARY KEY, max_schools_override INTEGER, max_students_override INTEGER)');
$emptyCalculator = new BookingAdvancedAnalyticsCalculator(new DisabledDatesSqlService($emptyPdo), new BookingDaySettingsSqlRepository($emptyPdo), clock: new DateTimeImmutable('2026-08-15'));
$capacityWithoutTargetTable = $emptyCalculator->calculate([], $criteria);
$assert(count($capacityWithoutTargetTable['capacityByMonth']) === 3 && $capacityWithoutTargetTable['capacityByMonth'][1]['students']['capacity'] > 0, 'Technische capaciteit vereist ten onrechte de targettabel.');
$failedTargetRead = (new CapacityTargetAnalyticsCalculator(new CapacityTargetSqlRepository($emptyPdo)))->calculate(
    $capacityWithoutTargetTable['capacityByMonth'], $capacityWithoutTargetTable['capacityDaySnapshots'], $capacityWithoutTargetTable['analyticsToday'],
);
$assert($failedTargetRead['capacityTargetContext']['status'] === 'unavailable' && $failedTargetRead['capacityTargetByMonth'] === [], 'Een targetqueryfout wordt niet geïsoleerd.');

$emptyPdo->exec('CREATE TABLE capacity_targets (id INTEGER PRIMARY KEY, effective_date TEXT, students_per_available_day INTEGER, bookings_per_available_day NUMERIC, created_by_admin_id INTEGER, updated_by_admin_id INTEGER, created_at TEXT, updated_at TEXT)');
$emptyTargets = (new CapacityTargetAnalyticsCalculator(new CapacityTargetSqlRepository($emptyPdo)))->calculate(
    $capacityWithoutTargetTable['capacityByMonth'], $capacityWithoutTargetTable['capacityDaySnapshots'], $capacityWithoutTargetTable['analyticsToday'],
);
$assert($emptyTargets['capacityTargetContext']['status'] === 'available' && $emptyTargets['capacityTargetContext']['currentOfficialTarget'] === null, 'Een lege targettabel is geen geldige toestand.');
$assert($emptyTargets['capacityTargetByMonth'][1]['studentsTargetComparison']['target'] === null, 'Ontbrekend target wordt als nul of verzonnen target behandeld.');
$assert($technical['capacityByMonth'][1]['students']['capacity'] === $calculator->calculate($bookings, $criteria)['capacityByMonth'][1]['students']['capacity'], 'Targetanalytics wijzigen de bestaande capaciteitsberekening.');

echo "Capacity target domain tests passed.\n";

<?php
declare(strict_types=1);

use GeoFort\Services\Dashboard\Booking\Analytics\BookingAnalyticsCriteria;
use GeoFort\Services\Dashboard\Booking\Analytics\BookingAnalyticsDeepAnalyzer;
use GeoFort\Services\Dashboard\Booking\Export\BookingExportDateBounds;

require dirname(__DIR__, 2) . '/vendor/autoload.php';
$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$row = static function (
    int $id,
    ?int $students,
    string $program = 'dag',
    string $date = '2025-06-02',
    string $school = 'De School',
    string $postal = '1234 AB',
    array $food = [],
): array {
    return [
        'id' => $id, 'status' => 'Definitief', 'school' => $school, 'postal_code' => $postal,
        'country' => 'Nederland', 'visit_date' => $date, 'sector' => 'primairOnderwijs',
        'program' => $program, 'module' => null, 'students' => $students,
        'remise_break' => $food['snack'] ?? 0, 'kazerne_break' => 0, 'fortgracht_break' => 0,
        'lemonade' => 0, 'water_ice' => 0, 'remise_lunch' => $food['lunch'] ?? 0,
        'own_picnic' => $food['picnic'] ?? false,
    ];
};
$criteria = new BookingAnalyticsCriteria(
    '2024-07-01', '2027-12-31', '2024-07-01', '2027-12-31',
    new BookingExportDateBounds('2024-01-01', '2028-12-31'),
);
$values = [null, 0, 1, 20, 21, 40, 41, 80, 81, 120, 121, 160, 161];
$bookings = [];
foreach ($values as $index => $value) $bookings[] = $row($index + 1, $value);
$bookings[] = $row(20, 20, 'ochtend');
$bookings[] = $row(21, 40, 'ochtend');
$bookings[] = $row(22, 80, 'ochtend');
$bookings[] = $row(23, 81, 'ochtend');
$result = (new BookingAnalyticsDeepAnalyzer())->analyze($bookings, $criteria)->toArray();
$students = $result['studentCountAnalysis'];
$day = $students['programs'][0];
$morning = $students['programs'][1];
$assert(array_column($day['studentBins'], 'bookings') === [2,2,2,2,2], 'Leerlingbandgrenzen zijn onjuist.');
$assert($students['invalidRecordCount'] === 3, 'NULL, nul en boven 160 zijn niet als dataquality geteld.');
$assert($day['denominator'] === 10 && abs(array_sum(array_column($day['studentBins'], 'percentage')) - 100.0) < 0.001, 'Dagpercentages/noemer zijn onjuist.');
$assert($morning['denominator'] === 4 && abs(array_sum(array_column($morning['studentBins'], 'percentage')) - 100.0) < 0.001, 'Ochtendpercentages/noemer zijn onjuist.');
$assert(array_column($morning['capacityBins'], 'bookings') === [1,1,0,1,1], 'Ochtendcapaciteitsgrenzen of boven-capaciteit zijn onjuist.');
$assert(array_column($day['capacityBins'], 'bookings') === [4,2,2,2,1], 'Dagcapaciteitsgrenzen zijn onjuist.');

$cateringRows = [
    $row(30, 40, food: []),
    $row(31, 40, school: '  DE   SCHOOL ', postal: '1234ab', food: ['snack' => 40]),
    $row(32, 40, school: 'De School', postal: '1234 AB', food: ['lunch' => 40]),
    $row(33, 40, school: 'Andere', postal: '9999 ZZ', food: ['snack' => 40, 'lunch' => 40]),
    $row(34, 40, school: 'Picknick', postal: '8888 AA', food: ['picnic' => true]),
];
$catering = (new BookingAnalyticsDeepAnalyzer())->analyze($cateringRows, $criteria)->toArray()['cateringAnalysis'];
$assert(array_column($catering['bookingProfiles'], 'count') === [1,1,1,1,1,0], 'Cateringprofielen zijn niet exclusief of onjuist.');
$assert($catering['denominators']['schools'] === 3, 'Genormaliseerde schoolnaam/postcode/land dedupliceert niet stabiel.');
$assert(array_column($catering['schoolProfiles'], 'count') === [1,1,1], 'Altijd/soms/nooit per school is onjuist.');
$assert($catering['insights']['bookingPercentage'] === 60.0, 'Eigen picknick telt onterecht als GeoFort-catering.');

$yearRows = [
    $row(40, 50, date: '2024-07-01'), $row(41, 60, date: '2025-02-03'),
    $row(42, 70, date: date('Y') . '-03-03'), $row(43, 80, date: ((int) date('Y') + 1) . '-04-05'),
];
$yearly = (new BookingAnalyticsDeepAnalyzer())->analyze($yearRows, $criteria)->toArray()['yearlyAnalysis'];
$statuses = array_column($yearly['years'], 'comparisonStatus');
$assert(in_array('partialSelection', $statuses, true), 'Gedeeltelijk jaar wordt niet gemarkeerd.');
$assert(in_array('currentBookingStand', $statuses, true), 'Lopend jaar wordt niet als boekingsstand gemarkeerd.');
$assert(in_array('futureBookingStand', $statuses, true), 'Toekomstig jaar wordt niet als boekingsstand gemarkeerd.');

$heatRows = [$row(50, 40, date: '2025-01-06'), $row(51, 30, date: '2026-01-05'), $row(52, 20, date: '2026-01-05')];
$season = (new BookingAnalyticsDeepAnalyzer())->analyze($heatRows, $criteria)->toArray()['seasonalityAnalysis'];
$assert(array_column($season['monthlyBuckets'], 'label') === ['januari 2025', 'januari 2026'], 'Kalendermaanden uit verschillende jaren zijn samengevoegd.');
$assert(array_column($season['monthlyBuckets'], 'bookings') === [1, 2], 'Maandbuckets tellen aanvragen onjuist.');
$assert(count($season['visitDateBuckets']) === 2 && $season['visitDateBuckets'][1]['bookings'] === 2 && $season['visitDateBuckets'][1]['students'] === 50, 'Bezoekdatumbuckets groeperen concrete datums onjuist.');
$assert(array_sum(array_column($season['monthlyBuckets'], 'bookings')) === 3 && array_sum(array_column($season['visitDateBuckets'], 'students')) === 90, 'Seizoensinvarianten sluiten niet aan.');
$assert(count($season['weekdayBuckets']) === 1 && $season['weekdayBuckets'][0]['weekdayLabel'] === 'Maandag' && $season['weekdayBuckets'][0]['uniqueVisitDates'] === 2, 'Meerdere maandagen zijn niet samengevoegd.');
$assert($season['topDays'][0]['date'] === '2026-01-05' && $season['topDays'][0]['rank'] === 1, 'Topdagen zijn niet correct gerangschikt.');

echo "Booking analytics deep domain tests passed.\n";

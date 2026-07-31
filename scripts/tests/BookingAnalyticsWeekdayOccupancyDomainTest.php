<?php
declare(strict_types=1);

use GeoFort\Services\Dashboard\Booking\Analytics\BookingAnalyticsCriteria;
use GeoFort\Services\Dashboard\Booking\Analytics\BookingAnalyticsDeepAnalyzer;
use GeoFort\Services\Dashboard\Booking\Export\BookingExportDateBounds;

require dirname(__DIR__, 2) . '/vendor/autoload.php';
$assert = static function (bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); };
$row = static fn (int $id, string $date, string $school, string $postal, int $students, string $program = 'dag'): array => [
    'id' => $id, 'status' => 'Definitief', 'school' => $school, 'postal_code' => $postal, 'country' => 'Nederland',
    'visit_date' => $date, 'sector' => 'primairOnderwijs', 'program' => $program, 'module' => null, 'students' => $students,
    'remise_break' => 0, 'kazerne_break' => 0, 'fortgracht_break' => 0, 'lemonade' => 0, 'water_ice' => 0, 'remise_lunch' => 0, 'own_picnic' => false,
];
$bookings = [
    $row(1, '2025-06-02', 'School A', '1111 AA', 40),
    $row(2, '2025-06-02', ' school   a ', '1111AA', 30, 'ochtend'),
    $row(3, '2025-06-09', 'School A', '2222 BB', 50),
    $row(4, '2025-06-09', 'School B', '3333 CC', 20),
    $row(5, '2025-06-10', 'School C', '4444 DD', 25),
    $row(6, '2025-06-10', 'School D', '5555 EE', 25),
    $row(7, '2025-06-10', 'School E', '6666 FF', 25, 'ochtend'),
    $row(8, '2025-06-15', '', '', 10),
];
$criteria = new BookingAnalyticsCriteria('2025-06-01', '2025-06-30', '2025-06-01', '2025-06-30', new BookingExportDateBounds('2025-06-01', '2025-06-30'));
$season = (new BookingAnalyticsDeepAnalyzer())->analyze($bookings, $criteria)->toArray()['seasonalityAnalysis'];
$visits = $season['visitDateBuckets'];
$weekdays = $season['weekdayBuckets'];
$occupancy = $season['schoolOccupancy'];

$assert(array_sum(array_column($season['monthlyBuckets'], 'bookings')) === 8 && array_sum(array_column($season['monthlyBuckets'], 'students')) === 225, 'Maandinvariant faalt.');
$assert(count(array_unique(array_column($visits, 'date'))) === count($visits), 'Concrete datum komt meer dan één keer voor.');
$assert(array_sum(array_column($visits, 'bookings')) === 8 && array_sum(array_column($visits, 'students')) === 225, 'Bezoekdatuminvariant faalt.');
$assert(array_column($weekdays, 'weekdayNumber') === [1, 2, 7], 'ISO-weekdagvolgorde of weekendselectie klopt niet.');
$assert(array_sum(array_column($weekdays, 'bookings')) === 8 && array_sum(array_column($weekdays, 'students')) === 225, 'Weekdagtotalen sluiten niet aan.');
$assert(array_sum(array_column($weekdays, 'uniqueVisitDates')) === 4, 'Weekdag-bezoekdatums sluiten niet aan.');
$monday = $weekdays[0];
$assert($monday['bookings'] === 4 && $monday['uniqueVisitDates'] === 2 && $monday['students'] === 140, 'Maandagtotalen zijn onjuist.');
$assert($monday['averageStudentsPerVisitDate'] === 70.0 && $monday['averageBookingsPerVisitDate'] === 2.0, 'Maandaggemiddelden gebruiken niet concrete bezoekdatums.');
$assert($monday['averageSchoolsPerVisitDate'] === 1.5, 'Gemiddelde scholen telt dezelfde school over datums verkeerd.');
$assert($monday['dayProgramBookings'] === 3 && $monday['morningProgramBookings'] === 1 && $monday['programLabel'] === 'Dag- en ochtendprogramma', 'Programma-aggregatie per weekdag klopt niet.');

$categories = array_column($occupancy['categories'], null, 'key');
$assert($occupancy['totalBookedVisitDates'] === 4 && array_sum(array_column($occupancy['categories'], 'visitDateCount')) === 4, 'Bezettingscategorieën zijn niet exclusief of volledig.');
$assert($categories['one']['visitDateCount'] === 1, 'Dezelfde genormaliseerde school met twee aanvragen telt niet als één school.');
$assert($categories['two']['visitDateCount'] === 1, 'Twee verschillende scholen tellen niet als twee scholen.');
$assert($categories['threePlus']['visitDateCount'] === 1 && $occupancy['overCapacityVisitDateCount'] === 1, 'Historische 3+-dag ontbreekt.');
$assert($categories['unknown']['visitDateCount'] === 1, 'Ongeldige schoolidentiteit wordt niet apart geteld.');
$assert(abs(array_sum(array_column($occupancy['categories'], 'percentageOfBookedVisitDates')) - 100.0) < 0.001, 'Percentages gebruiken niet alle geboekte bezoekdatums als noemer.');
$assert(array_sum(array_column($occupancy['categories'], 'bookingCount')) === 8 && array_sum(array_column($occupancy['categories'], 'studentCount')) === 225, 'Bezettingstotalen sluiten niet aan.');
$assert($occupancy['averageSchoolsPerVisitDate'] === 1.5, 'Gemiddeld scholen per dag is niet eerst per datum berekend.');

$empty = (new BookingAnalyticsDeepAnalyzer())->analyze([], $criteria)->toArray()['seasonalityAnalysis'];
$assert($empty['weekdayBuckets'] === [] && $empty['schoolOccupancy']['totalBookedVisitDates'] === 0, 'Lege weekdag- of bezettingsanalyse is niet expliciet leeg.');

echo "Booking analytics weekday/occupancy domain tests passed.\n";

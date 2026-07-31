<?php
declare(strict_types=1);

use GeoFort\Services\Dashboard\Booking\Analytics\BookingAnalyticsCriteriaFactory;
use GeoFort\Services\Dashboard\Booking\Analytics\BookingAnalyticsService;
use GeoFort\Services\Dashboard\Booking\Export\BookingExportDateBounds;
use GeoFort\Services\Sql\BookingAnalyticsRepository;
use GeoFort\Services\Sql\BookingExportSqlRepository;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE aanvragen (
    id INTEGER PRIMARY KEY, status TEXT, schoolnaam TEXT, postcode TEXT DEFAULT "", land TEXT DEFAULT "",
    bezoekdatum TEXT, onderwijs_sector TEXT, programma TEXT, keuzemodule_key TEXT, aantal_leerlingen INTEGER,
    remise_break INTEGER DEFAULT 0, kazerne_break INTEGER DEFAULT 0, fortgracht_break INTEGER DEFAULT 0,
    glas_limonade INTEGER DEFAULT 0, waterijsje INTEGER DEFAULT 0, remise_lunch INTEGER DEFAULT 0, eigen_picknick INTEGER DEFAULT 0
)');
$pdo->exec('CREATE TABLE aanvraag_onderwijs_selecties (
    aanvraag_id INTEGER, level_key TEXT, group_key TEXT
)');
$insert = $pdo->prepare('INSERT INTO aanvragen(id,status,schoolnaam,bezoekdatum,onderwijs_sector,programma,keuzemodule_key,aantal_leerlingen) VALUES (?,?,?,?,?,?,?,?)');
foreach ([
    [1,'Definitief','School A','2025-12-31','primairOnderwijs','dag','Earth-Watch',40],
    [2,'In optie','School B','2026-01-01','voortgezetOnderbouw','dag','Klimaat-Mysterie',30],
    [3,'Afgewezen','School C','2026-01-01','voortgezetBovenbouw','dag',null,80],
    [4,'In optie','School A','2026-01-02','primairOnderwijs','ochtend',null,20],
] as $row) $insert->execute($row);
$selection = $pdo->prepare('INSERT INTO aanvraag_onderwijs_selecties VALUES (?,?,?)');
foreach ([
    [1,'regulier','groep7'], [1,'regulier','groep7'], [1,'regulier','groep8'],
    [2,'havo','havo1'], [2,'vwo','atheneum1'], [4,'regulier','groep6'],
] as $row) $selection->execute($row);

$bounds = new BookingExportDateBounds('2025-12-31', '2026-01-02');
$criteria = (new BookingAnalyticsCriteriaFactory())->create(
    ['startDate' => '2025-12-31', 'endDate' => '2026-01-02'],
    $bounds,
);
$filteredCriteria = (new BookingAnalyticsCriteriaFactory())->create(
    ['startDate' => '2025-12-31', 'endDate' => '2026-01-02', 'sector' => 'primairOnderwijs', 'population' => 'confirmed', 'program' => 'dag'],
    $bounds,
);
$assert($filteredCriteria->sector === 'primairOnderwijs' && $filteredCriteria->population === 'confirmed' && $filteredCriteria->program === 'dag', 'Geldige globale filters worden niet vastgelegd.');
try {
    (new BookingAnalyticsCriteriaFactory())->create(
        ['startDate' => '2025-12-31', 'endDate' => '2026-01-02', 'sector' => 'interneKey', 'population' => 'planning'],
        $bounds,
    );
    throw new RuntimeException('Ongeldige sectorfilter is niet geweigerd.');
} catch (\GeoFort\Validation\FieldValidationException $exception) {
    $assert($exception->getField() === 'sector', 'Sectorvalidatie is niet veldgericht.');
}
$service = new BookingAnalyticsService(
    new BookingAnalyticsRepository($pdo),
    new BookingExportSqlRepository($pdo),
);
$result = $service->analyze($criteria)->toArray();
$filteredResult = $service->analyze($filteredCriteria)->toArray();
$assert($filteredResult['summary']['activeBookings'] === 1 && $filteredResult['summary']['plannedStudents'] === 40, 'Sector-, status- en programmafilter gebruiken niet één bookingset.');
$assert(array_sum(array_column($filteredResult['seasonalityAnalysis']['monthlyBuckets'], 'bookings')) === 1
    && array_sum(array_column($filteredResult['seasonalityAnalysis']['visitDateBuckets'], 'students')) === 40
    && array_sum(array_column($filteredResult['seasonalityAnalysis']['weekdayBuckets'], 'bookings')) === 1, 'Seizoen wijkt af van de centrale bookingset.');
$summary = $result['summary'];
$assert($summary['activeBookings'] === 3, 'Actieve populatie is niet optie plus definitief.');
$assert($summary['confirmedBookings'] === 1 && $summary['optionBookings'] === 2 && $summary['rejectedBookings'] === 0, 'Statuspopulatie is niet centraal toegepast.');
$assert($summary['plannedStudents'] === 90, 'Geplande leerlingen bevatten afwijzingen of zijn vermenigvuldigd.');
$assert($summary['uniqueSchools'] === 2 && $summary['uniqueVisitDays'] === 3, 'Actieve scholen/bezoekdagen zijn onjuist.');
$assert($summary['rejectionPercentage'] === 0, 'Afwijzingspercentage gebruikt niet de geselecteerde populatie.');
$assert(count($result['monthlyTrend']) === 2 && $result['monthlyTrend'][0]['month'] === 'december 2025'
    && $result['monthlyTrend'][1]['month'] === 'januari 2026', 'Maandgroepering over jaargrens is onjuist.');
$assert($result['programDistribution'][0]['activeBookings'] === 2
    && $result['programDistribution'][1]['activeBookings'] === 1, 'Dag/ochtendverdeling is onjuist.');
$assert($result['choiceModuleDistribution'][0]['eligibleBookings'] === 2
    && $result['choiceModuleDistribution'][0]['populationShare'] === 50, 'Keuzemodule-noemer is niet de actieve dagpopulatie.');
$composition = $result['compositionDistribution'];
$assert($composition['multipleLevels'] === 1 && $composition['oneLevel'] === 0, 'VO-niveauverdeling is onjuist.');
$assert($composition['oneGroup'] === 1 && $composition['twoGroups'] === 2, 'Groepsverdeling telt duplicaten of mist bookings.');
$assert($composition['averageGroups'] === 1.7, 'Gemiddeld aantal groepen gebruikt niet alle actieve aanvragen als noemer.');
$assert(array_column($result['weekdayDistribution'], 'weekday') === ['Maandag','Dinsdag','Woensdag','Donderdag','Vrijdag'], 'Weekdagen staan niet in kalenderorde.');
$assert($result['busiestVisitDates'][0]['date'] === '2025-12-31'
    && $result['busiestVisitDates'][0]['plannedStudents'] === 40, 'Drukste actieve bezoekdag is onjuist.');

$empty = (new BookingAnalyticsCriteriaFactory())->create(
    ['startDate' => '2024-01-01', 'endDate' => '2024-12-31'],
    $bounds,
);
$emptyResult = $service->analyze($empty)->toArray();
$assert($emptyResult['summary']['activeBookings'] === 0 && $emptyResult['monthlyTrend'] === [], 'Lege periode is niet veilig.');

echo "Booking analytics domain tests passed.\n";

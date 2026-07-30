<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use GeoFort\Services\Dashboard\Booking\Export\BookingCsvWriter;
use GeoFort\Services\Dashboard\Booking\Export\BookingExportCriteriaFactory;
use GeoFort\Services\Dashboard\Booking\Export\BookingExportDateBounds;
use GeoFort\Services\Dashboard\Booking\Export\BookingExportRowFactory;
use GeoFort\Services\Dashboard\Booking\Export\SpreadsheetFormulaEscaper;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};

$escaper = new SpreadsheetFormulaEscaper();
foreach (['=1+1', '+cmd', '-2+3', '@SUM(A1)', "\tformula", "\rformula", '  =HYPERLINK("x")'] as $unsafe) {
    $assert($escaper->escape($unsafe) === "'" . $unsafe, "CSV-injectie niet geneutraliseerd: {$unsafe}");
}
foreach (['School - locatie', ' Gewone school', '123', ''] as $safe) {
    $assert($escaper->escape($safe) === $safe, "Veilige tekst onnodig gewijzigd: {$safe}");
}

$factory = new BookingExportRowFactory($escaper);
$row = $factory->create([
    'id' => '42', 'status' => 'Definitief', 'bezoekdatum' => '2026-09-02',
    'programma' => 'dag', 'onderwijs_sector' => 'primairOnderwijs',
    'aantal_leerlingen' => '50', 'aantal_begeleiders' => null,
    'level_labels' => '=Kwaad niveau | Speciaal',
    'group_labels' => '+Kwaad groep | Groep 6',
    'groups_per_level' => '=Kwaad niveau: +Kwaad groep, Groep 6 | Speciaal: Groep 7',
    'keuzemodule_key' => null, 'schoolnaam' => '=HYPERLINK("https://example.test")',
    'adres' => 'Dijk 1; achter', 'postcode' => '1234 AB', 'plaats' => 'Acme, stad',
    'land' => 'Nederland', 'school_telefoonnummer' => '+31 10',
    'contactpersoon_voornaam' => 'Ada', 'contactpersoon_achternaam' => 'van "Dijk"',
    'email' => 'ada@example.test', 'contactpersoon_telefoonnummer' => '0612345678',
    'remise_break' => '1', 'kazerne_break' => 2, 'fortgracht_break' => 0,
    'waterijsje' => 3, 'glas_limonade' => 4, 'remise_lunch' => 5,
    'eigen_picknick' => 1, 'cjpPasGebruik' => 'nee', 'cjpContactpersoonNaam' => null,
    'cjpPasnummer' => null, 'hoe_kent_u_geofort' => null,
    'opmerkingen' => "Eerste regel\r\nTweede; regel met \"quotes\"",
]);

$stream = fopen('php://memory', 'w+b');
if ($stream === false) throw new RuntimeException('Teststream kon niet worden geopend.');
(new BookingCsvWriter())->write($stream, [$row]);
rewind($stream);
$csv = stream_get_contents($stream);
fclose($stream);
$assert(is_string($csv), 'CSV kon niet worden gelezen.');
$assert(str_starts_with($csv, "\xEF\xBB\xBF"), 'UTF-8 BOM ontbreekt.');
$assert(substr_count($csv, "\r\n") >= 2, 'CRLF-regelafbreking ontbreekt.');
$assert(str_contains($csv, 'Boeking-ID;Status;Bezoekdatum'), 'Nederlandse headers ontbreken.');
$assert(str_contains($csv, '"Dijk 1; achter"'), 'Puntkomma wordt niet gequote.');
$assert(str_contains($csv, '"Acme, stad"'), 'Veld met komma wordt niet veilig gequote.');
$assert(str_contains($csv, '"Ada van ""Dijk"""'), 'Quotes worden niet correct escaped.');
$assert(str_contains($csv, "'=HYPERLINK"), 'Schoolnaam is niet tegen CSV-injectie beschermd.');
$assert(str_contains($csv, "\"Eerste regel\r\nTweede; regel met \"\"quotes\"\"\""), 'Multilineveld wordt niet veilig gequote.');
$assert(count($row->values) === count(BookingExportRowFactory::HEADERS), 'Aantal waarden en headers verschilt.');
$assert($row->values[6] === '', 'Nullable aantal begeleiders is niet leeg.');
$assert($row->values[7] === "'=Kwaad niveau | Speciaal", 'Niveaulabel is niet compleet of injectieveilig.');
$assert($row->values[8] === "'+Kwaad groep | Groep 6", 'Groepslabel is niet compleet of injectieveilig.');
$assert($row->values[9] === "'=Kwaad niveau: +Kwaad groep, Groep 6 | Speciaal: Groep 7", 'Groepen-per-niveau ontbreken of zijn niet injectieveilig.');
$assert($row->values[10] === 'Geen keuzemodule', 'Leeg modulelabel is niet leesbaar.');
$assert(in_array('Groepen per niveau', BookingExportRowFactory::HEADERS, true), 'Groepen-per-niveauheader ontbreekt.');
foreach ([
    'Boeking-ID', 'Status', 'Bezoekdatum', 'Programma', 'Sector',
    'Aantal leerlingen', 'Aantal begeleiders', 'Schoolnaam',
    'E-mailadres contactpersoon', 'Pauze Remise', 'Remiselunches',
    'CJP-pas gebruikt', 'Opmerkingen',
] as $requiredHeader) {
    $assert(in_array($requiredHeader, BookingExportRowFactory::HEADERS, true), "Bestaande exportkolom ontbreekt: {$requiredHeader}.");
}
$parseStream = fopen('php://memory', 'w+b');
if ($parseStream === false) throw new RuntimeException('Parse-teststream kon niet worden geopend.');
(new BookingCsvWriter())->write($parseStream, [$row]);
rewind($parseStream);
fread($parseStream, 3);
$parsedHeaders = fgetcsv($parseStream, separator: ';', enclosure: '"', escape: '');
$parsedRow = fgetcsv($parseStream, separator: ';', enclosure: '"', escape: '');
$extraRow = fgetcsv($parseStream, separator: ';', enclosure: '"', escape: '');
fclose($parseStream);
$assert(is_array($parsedHeaders) && is_array($parsedRow) && $extraRow === false, 'Eén booking levert niet exact één CSV-datarij.');
$assert(count($parsedHeaders) === count($parsedRow), 'CSV-header en datarij hebben een ander kolomaantal.');
$assert(!str_contains($row->values[9], '{') && !str_contains($row->values[9], 'regulier'), 'Groepen-per-niveau bevat JSON of interne keys.');

$criteriaFactory = new BookingExportCriteriaFactory();
$bounds = new BookingExportDateBounds('2025-09-09', '2028-12-29');
$normalized = $criteriaFactory->create(
    ['startDate' => '2020-01-01', 'endDate' => '2030-01-01'],
    $bounds,
);
$assert($normalized->effectiveStartDate === '2025-09-09', 'Start vóór minimum wordt niet genormaliseerd.');
$assert($normalized->effectiveEndDate === '2028-12-29', 'Einde na maximum wordt niet genormaliseerd.');
$assert($normalized->wasNormalized(), 'Normalisatie wordt niet gerapporteerd.');
$exact = $criteriaFactory->create(
    ['startDate' => '2025-09-09', 'endDate' => '2028-12-29'],
    $bounds,
);
$assert($exact->hasOverlap() && !$exact->wasNormalized(), 'Inclusieve exacte databasegrenzen veranderen.');
$noOverlap = $criteriaFactory->create(
    ['startDate' => '2020-01-01', 'endDate' => '2020-12-31'],
    $bounds,
);
$assert(!$noOverlap->hasOverlap(), 'Bereik zonder overlap wordt niet als leeg geselecteerd.');
try {
    $criteriaFactory->create(
        ['startDate' => '2027-01-02', 'endDate' => '2027-01-01'],
        $bounds,
    );
    throw new RuntimeException('Start na einde is niet geweigerd.');
} catch (\GeoFort\Validation\FieldValidationException $exception) {
    $assert($exception->getField() === 'endDate', 'Periodefout is niet veldgericht.');
}
$emptyDatabase = $criteriaFactory->create(
    ['startDate' => '2026-01-01', 'endDate' => '2026-12-31'],
    new BookingExportDateBounds(null, null),
);
$assert(!$emptyDatabase->hasOverlap(), 'Lege database levert geen lege selectie.');

echo "Booking CSV export domain tests passed.\n";

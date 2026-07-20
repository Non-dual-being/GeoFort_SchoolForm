<?php
declare(strict_types=1);

use GeoFort\Booking\BookingPolicy;
use GeoFort\Booking\Capacity\BookingCapacityValidator;
use GeoFort\Booking\Capacity\BookingDaySettings;
use GeoFort\Booking\Capacity\CapacityValidationCode;
use GeoFort\Booking\Capacity\DayCapacityTotals;
use GeoFort\Booking\Capacity\PolicyCapacityLimitProvider;
use GeoFort\Booking\Status\BookingStatusTransitionPolicy;
use GeoFort\Booking\Status\BookingStatusChangeCode;
use GeoFort\Booking\Status\BookingStatusChangeCommand;
use GeoFort\Booking\Status\BookingStatusChangeResult;
use GeoFort\Booking\Status\BookingStatusMailMode;
use GeoFort\Booking\Status\BookingTransitionCode;
use GeoFort\Booking\Stored\StoredBookingAssembler;
use GeoFort\Booking\Validation\StoredBookingIssueCategory;
use GeoFort\Booking\Validation\StoredBookingValidator;
use GeoFort\Services\Booking\Pricing\BookingPriceCalculator;
use GeoFort\Services\Booking\Pricing\StoredBookingPricingInputFactory;
use GeoFort\Services\Sql\DisabledDatesSqlService;
use GeoFort\Services\Sql\StoredBookingSqlRepository;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void { if (!$condition) $failures[] = $message; };
$row = [
    'id'=>'7','status'=>'In optie','schoolnaam'=>'Testschool','land'=>'Nederland','adres'=>'Dijk 1','postcode'=>'1234 AB','plaats'=>'Teststad',
    'school_telefoonnummer'=>'0123456789','contactpersoon_telefoonnummer'=>'0612345678','contactpersoon_voornaam'=>'Jan','contactpersoon_achternaam'=>'Jansen','email'=>'jan@example.test',
    'bezoekdatum'=>'2026-09-02','hoe_kent_u_geofort'=>null,'opmerkingen'=>"Regel 1\nRegel 2",'cjpPasGebruik'=>'nee','cjpContactpersoonNaam'=>null,'cjpPasnummer'=>null,
    'onderwijs_sector'=>'primairOnderwijs','programma'=>'dag','keuzemodule_key'=>'Earth-Watch','aantal_leerlingen'=>'40','aantal_begeleiders'=>'5',
    'remise_break'=>'0','kazerne_break'=>'0','fortgracht_break'=>'0','glas_limonade'=>'0','waterijsje'=>'0','remise_lunch'=>'0','eigen_picknick'=>'1',
    'voorwaarden_akkoord'=>'1','voorwaarden_akkoord_op'=>'2026-07-17 10:00:00','source_system'=>null,'source_record_id'=>null,'source_record_checksum'=>null,'source_import_run_id'=>null,
];
$selections = [
    ['sector_key'=>'primairOnderwijs','level_key'=>'regulier','group_key'=>'groep6'],
    ['sector_key'=>'primairOnderwijs','level_key'=>'regulier','group_key'=>'groep5'],
];
$assembler = new StoredBookingAssembler();
$native = $assembler->assemble($row, $selections);
$assert($native->id === 7 && !$native->source->isLegacy(), 'Native aanvraag is niet correct opgebouwd.');
$assert($native->educationSelection->selectedLevels === ['regulier'] && $native->educationSelection->selectedGroupsByLevel['regulier'] === ['groep6','groep5'], 'Genormaliseerde selecties zijn niet deterministisch gegroepeerd.');
$legacy = $assembler->assemble([...$row, 'source_system'=>'legacy_geoform', 'source_record_id'=>'42'], $selections);
$assert($legacy->source->isLegacy() && $legacy->source->sourceRecordId === 42, 'Legacybronmetadata ontbreekt.');
$missingSelection = $assembler->assemble([...$row, 'source_system'=>'legacy_geoform'], []);
$assert(!$missingSelection->source->hasNormalizedEducationSelection, 'Ontbrekende selectie is niet expliciet gemarkeerd.');

$today = new DateTimeImmutable('2026-07-17', new DateTimeZone('Europe/Amsterdam'));
$transition = new BookingStatusTransitionPolicy();
$command = new BookingStatusChangeCommand(7, BookingPolicy::STATUS_OPTION, BookingPolicy::STATUS_CONFIRMED, 3, BookingStatusMailMode::None);
$assert($command->bookingId === 7 && $command->actingAdminId === 3, 'Statuscommand bevat niet het expliciete domeincontract.');
$typedResult = new BookingStatusChangeResult(BookingStatusChangeCode::StatusConflict, false, 7, BookingPolicy::STATUS_REJECTED, BookingPolicy::STATUS_REJECTED);
$assert(!$typedResult->success && $typedResult->code === BookingStatusChangeCode::StatusConflict, 'Statusresultaat is niet expliciet getypeerd.');
$statuses = BookingPolicy::ALLOWED_STATUSES;
$allowed = 0;
foreach ($statuses as $from) foreach ($statuses as $to) {
    $decision = $transition->decide($from, $to);
    if ($from === $to) $assert($decision->code === BookingTransitionCode::NoStatusChange, 'Zelfde status is niet afgewezen.');
    else { $allowed++; $assert($decision->allowed, "Overgang {$from} -> {$to} ontbreekt."); $assert($decision->requiresCapacityCheck === ($to === BookingPolicy::STATUS_CONFIRMED), 'Capaciteitsvereiste klopt niet.'); }
}
$assert($allowed === 6, 'Niet exact zes overgangen gemodelleerd.');

$limits = (new PolicyCapacityLimitProvider())->forDate('2026-09-02');
$assert($limits->overrideMaxSchools === null && $limits->effectiveMaxSchools === BookingPolicy::MAX_SCHOOLS_PER_DAY, 'Effectieve standaardcapaciteit klopt niet.');
$capacity = new BookingCapacityValidator();
$assert($capacity->validate(new DayCapacityTotals(1,120),40,$limits)->allowed, 'Exacte limieten moeten toegestaan zijn.');
$assert($capacity->validate(new DayCapacityTotals(2,100),40,$limits)->code === CapacityValidationCode::SchoolLimitExceeded, 'Eén school boven limiet niet gedetecteerd.');
$assert($capacity->validate(new DayCapacityTotals(1,121),40,$limits)->code === CapacityValidationCode::StudentLimitExceeded, 'Eén leerling boven limiet niet gedetecteerd.');
$assert($capacity->validate(new DayCapacityTotals(0,0),null,$limits)->code === CapacityValidationCode::InvalidStudentCount, 'Null leerlingenaantal niet gedetecteerd.');
try {
    new BookingDaySettings('2026-09-02', 0, null);
    $assert(false, 'Niet-positieve schooloverride is toegestaan.');
} catch (InvalidArgumentException) {}

$pricingFactory = new StoredBookingPricingInputFactory();
$pricing = $pricingFactory->fromStoredBooking($native);
$assert($pricing->studentCount === 40, 'Standaard prijsinput gebruikt niet StoredBooking::studentCount.');
$previewPricing = $pricingFactory->forStudentCountPreview($native, 55);
$assert($previewPricing->studentCount === 55, 'Expliciete prijs-preview gebruikt niet het previewaantal.');
$quote = $pricingFactory->calculate($previewPricing, new BookingPriceCalculator());
$assert($quote->studentCount === 55, 'Prijsservice ontvangt niet het expliciete previewaantal.');

if (in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $pdo->exec('CREATE TABLE disabled_dates (datum TEXT PRIMARY KEY, type TEXT, reden TEXT)');
    $pdo->exec('CREATE TABLE aanvragen (id INTEGER, status, schoolnaam, land, adres, postcode, plaats, school_telefoonnummer, contactpersoon_telefoonnummer, contactpersoon_voornaam, contactpersoon_achternaam, email, bezoekdatum, hoe_kent_u_geofort, opmerkingen, cjpPasGebruik, cjpContactpersoonNaam, cjpPasnummer, onderwijs_sector, programma, keuzemodule_key, aantal_leerlingen, aantal_begeleiders, remise_break, kazerne_break, fortgracht_break, glas_limonade, waterijsje, remise_lunch, eigen_picknick, voorwaarden_akkoord, voorwaarden_akkoord_op, source_system, source_record_id, source_record_checksum, source_import_run_id)');
    $pdo->exec('CREATE TABLE aanvraag_onderwijs_selecties (id INTEGER, aanvraag_id INTEGER, sector_key, level_key, group_key, level_position INTEGER, group_position INTEGER)');
    $storedRepository = new StoredBookingSqlRepository($pdo, $assembler);
    $assert($storedRepository->findById(999) === null, 'Ontbrekende aanvraag wordt niet als null onderscheiden.');
    try {
        $storedRepository->findByIdForUpdate(999);
        $assert(false, 'Aanvraaglock buiten transactie is toegestaan.');
    } catch (RuntimeException $exception) {
        $assert(str_contains($exception->getMessage(), 'transactie'), 'Aanvraaglock buiten transactie geeft de verkeerde fout.');
    }
    $validator = new StoredBookingValidator(new DisabledDatesSqlService($pdo));
    $validResult = $validator->validateForTargetStatus($native, BookingPolicy::STATUS_CONFIRMED, $today);
    $assert($validResult->isValid(), 'Native aanvraag hoort onder huidige configuratie geldig te zijn.');
    $legacyResult = $validator->validateForTargetStatus($missingSelection, BookingPolicy::STATUS_CONFIRMED, $today);
    $historical = array_filter($legacyResult->issues, static fn($issue) => $issue->category === StoredBookingIssueCategory::HistoricalConfiguration);
    $assert($historical !== [] && !$legacyResult->isValid(), 'Legacyconfiguratieafwijking blokkeert bevestiging niet.');
    $assert($validator->validateForTargetStatus($missingSelection, BookingPolicy::STATUS_REJECTED, $today)->isValid(), 'Legacyconfiguratieafwijking blokkeert afwijzen.');
    $invalidConfig = $assembler->assemble([...$row, 'programma'=>'verdwenen'], $selections);
    $assert($validator->validateForTargetStatus($invalidConfig, BookingPolicy::STATUS_CONFIRMED, $today)->hasCode('CURRENT_CONFIGURATION_MISMATCH'), 'Ongeldige configuratiesleutel niet gedetecteerd.');
    $invalidDate = $assembler->assemble([...$row, 'bezoekdatum'=>'2026-02-31'], $selections);
    $assert($validator->validateForTargetStatus($invalidDate, BookingPolicy::STATUS_CONFIRMED, $today)->hasCode('INVALID_VISIT_DATE'), 'Ongeldige datum wordt bij bevestiging geaccepteerd.');
    $pdo->exec("INSERT INTO disabled_dates VALUES ('2026-09-02','manual','test')");
    $assert($validator->validateForTargetStatus($native, BookingPolicy::STATUS_CONFIRMED, $today)->hasCode('DISABLED_VISIT_DATE'), 'Disabled datum blokkeert In optie -> Definitief niet.');
    $assert($validator->validateForTargetStatus($native, BookingPolicy::STATUS_REJECTED, $today)->isValid(), 'Disabled datum blokkeert Definitief -> Afgewezen.');
    $historicalBooking = $assembler->assemble([...$row, 'status'=>BookingPolicy::STATUS_REJECTED, 'bezoekdatum'=>'2026-07-16'], $selections);
    $assert($validator->validateForTargetStatus($historicalBooking, BookingPolicy::STATUS_CONFIRMED, $today)->hasCode('HISTORICAL_VISIT_DATE'), 'Historische datum blokkeert Afgewezen -> Definitief niet.');
    $assert($validator->validateForTargetStatus($historicalBooking, BookingPolicy::STATUS_OPTION, $today)->isValid(), 'Historische datum blokkeert Definitief -> In optie.');
} else {
    fwrite(STDOUT, "SKIP: SQLite-driver ontbreekt; validator-SQL-fixtures niet uitgevoerd.\n");
}

if ($failures !== []) { foreach ($failures as $failure) fwrite(STDERR, "FAIL: {$failure}\n"); exit(1); }
fwrite(STDOUT, "OK: statusdomein-foundationchecks geslaagd.\n");

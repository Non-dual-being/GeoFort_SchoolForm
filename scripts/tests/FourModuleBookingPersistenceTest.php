<?php

declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use GeoFort\Booking\FourModuleProgram;
use GeoFort\Booking\Stored\StoredBookingAssembler;
use GeoFort\Booking\Validation\StoredBookingProgramValidator;
use GeoFort\Services\Booking\Data\{BookingRequestData, EducationSelectionData, FoodAndDrinkSelectionData, StoredBookingMailDataFactory};
use GeoFort\Services\Booking\Pricing\{BookingPricingInput, BookingPricingInputChecksum, BookingPriceCalculator, StoredBookingPricingInputFactory};
use GeoFort\Services\Sql\{RequestService, StoredBookingSqlRepository};

$checks = 0;
$assert = static function (bool $ok, string $message) use (&$checks): void {
    if (!$ok) throw new RuntimeException($message);
    $checks++;
};
$pdo = new PDO('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec("CREATE TABLE aanvragen (
    id INTEGER PRIMARY KEY AUTOINCREMENT, status TEXT DEFAULT 'In optie', schoolnaam TEXT, land TEXT,
    adres TEXT, postcode TEXT, plaats TEXT, school_telefoonnummer TEXT, contactpersoon_telefoonnummer TEXT,
    contactpersoon_voornaam TEXT, contactpersoon_achternaam TEXT, email TEXT, bezoekdatum TEXT,
    hoe_kent_u_geofort TEXT, opmerkingen TEXT, cjpPasGebruik TEXT, cjpContactpersoonNaam TEXT, cjpPasnummer TEXT,
    onderwijs_sector TEXT, programma TEXT, program_version TEXT DEFAULT NULL, keuzemodule_key TEXT,
    aantal_leerlingen INTEGER, aantal_begeleiders INTEGER, remise_break INTEGER, kazerne_break INTEGER,
    fortgracht_break INTEGER, glas_limonade INTEGER, waterijsje INTEGER, remise_lunch INTEGER,
    eigen_picknick INTEGER, voorwaarden_akkoord INTEGER, voorwaarden_akkoord_op TEXT,
    source_system TEXT, source_record_id INTEGER, source_record_checksum TEXT, source_import_run_id INTEGER
)");
$pdo->exec('CREATE TABLE aanvraag_onderwijs_selecties (id INTEGER PRIMARY KEY, aanvraag_id INTEGER,
    sector_key TEXT, level_key TEXT, group_key TEXT, level_position INTEGER, group_position INTEGER,
    sector_label TEXT, level_label TEXT, group_label TEXT)');
$food = FoodAndDrinkSelectionData::fromStoredValues(0, 0, 0, 0, 0, 0, true);
$selection = new EducationSelectionData('primairOnderwijs', ['regulier'], ['regulier' => ['groep7']]);
$repository = new StoredBookingSqlRepository($pdo, new StoredBookingAssembler());
$validator = new StoredBookingProgramValidator();
$priceFactory = new StoredBookingPricingInputFactory();
$calculator = new BookingPriceCalculator();
$checksums = new BookingPricingInputChecksum();

foreach ([null, FourModuleProgram::VERSION] as $version) {
    $request = new BookingRequestData(
        schoolnaam: 'Test school', land: 'Nederland', adres: 'Test 1', postcode: '1234 AB', plaats: 'Test',
        schoolTelefoonnummer: '0612345678', contactpersoonTelefoonnummer: '0612345678',
        contactpersoonVoornaam: 'Test', contactpersoonAchternaam: 'Test', email: 'test@example.test',
        bezoekdatum: '2027-01-04', bezoekdatumLabel: 'maandag 4 januari 2027', hoeKentUGeoFort: '',
        cjpPasGebruik: 'nee', cjpContactpersoonNaam: null, cjpPasnummer: null, schoolSector: 'primairOnderwijs',
        programma: 'dag', keuzemoduleKey: $version === null ? 'Earth-Watch' : 'Klimaat-Experience',
        aantalLeerlingen: 40, aantalBegeleiders: 10, educationSelection: $selection,
        foodAndDrinkSelection: $food, opmerkingen: null, voorwaardenAkkoord: true, programVersion: $version,
    );
    $id = (new RequestService($pdo))->insert($request);
    $pdo->prepare("INSERT INTO aanvraag_onderwijs_selecties (aanvraag_id,sector_key,level_key,group_key,level_position,group_position)
        VALUES (?, 'primairOnderwijs', 'regulier', 'groep7', 0, 0)")->execute([$id]);
    $stored = $repository->findById($id);
    $assert($stored !== null && $stored->programVersion === $version, 'Version survives SQL round trip');
    $assert($validator->validate($stored)->isValid(), 'Version-specific choice is valid');
    $detail = (new \GeoFort\Services\Dashboard\Booking\DashboardBookingDetailService(
        new \GeoFort\Services\Sql\DashboardBookingDetailSqlService($pdo), $repository,
    ))->getBooking($id);
    $config = \GeoFort\Booking\BookingProgramVersionConfig::forFrontend($version);
    $assert($detail['education']['programVersion'] === $version, 'Dashboard detail retains version');
    $assert($detail['education']['configuration']['modules'] === $config['modules'], 'Dashboard editor offers correct version modules');
    $assert($detail['education']['configuration']['moduleFilters'] === $config['moduleFilters'], 'Dashboard editor filters correct version choices');
    $assert($detail['education']['programLabel'] === ($version === null ? 'Dagprogramma · vijf modules' : 'Dagprogramma · vier modules'), 'Dashboard labels four-module agreement');
    $assert(count($detail['education']['programOptions']) === ($version === null ? 2 : 1), 'Four-module agreement cannot silently switch to morning');
    $mail = (new StoredBookingMailDataFactory())->fromStoredBooking($stored);
    $assert($mail->programVersion === $version, 'Mail retains version');
    $assert($checksums->checksum(BookingPricingInput::fromRequest($request)) === $checksums->checksum(BookingPricingInput::fromStoredBooking($stored)), 'Stored and submitted pricing identical');
    $assert($checksums->checksum(BookingPricingInput::fromRequest($mail)) === $checksums->checksum(BookingPricingInput::fromRequest($request)), 'Mail pricing identical');
    foreach ([$stored->withAttendance(45, 12), $stored->withVisitDate('2027-02-01'),
        $stored->withFoodAndDrink($food), $stored->withProgram('dag'),
        $stored->withProgramConfiguration('dag', 45, $selection, $stored->choiceModuleKey)] as $changed) {
        $assert($changed->programVersion === $version, 'Editing retains program version');
        $assert($validator->validate($changed)->isValid(), 'Edited version-specific booking valid');
    }
    $quote = $priceFactory->calculate($priceFactory->forStudentCountPreview($stored, 45), $calculator);
    $assert($quote->paidSupervisors === ($version === null ? 4 : 0), 'Preview retains supervisor policy');
    $assert($calculator->calculateInput(BookingPricingInput::fromRequest($mail))->paidSupervisors === ($version === null ? 5 : 0), 'Mail retains supervisor policy');
    if ($version !== null) {
        $assert(!$validator->validate($stored->withVisitDate('2026-12-31'))->isValid(), 'Cannot move new variant before start date');
        $assert(!$validator->validate($stored->withProgram('ochtend'))->isValid(), 'Cannot silently reinterpret version as morning');
        $mixed = new EducationSelectionData('primairOnderwijs', ['regulier'], ['regulier' => ['groep6', 'groep7']]);
        $assert(!$validator->validate($stored->withProgramConfiguration('dag', 40, $mixed, 'Earth-Watch'))->isValid(), 'Choice must fit all groups');
    }
}
$assert((int) $pdo->query('SELECT COUNT(*) FROM aanvragen WHERE program_version IS NULL')->fetchColumn() === 1, 'Future old booking stays unversioned');
$links = new \GeoFort\Services\Mail\Templates\MailLinks('https://example.test', 'https://example.test/terms', 'test@example.test');
$template = new \GeoFort\Services\Mail\Templates\BookingRequestMailTemplate(
    new \GeoFort\Services\Mail\Templates\MailLayout($links), $links, new \GeoFort\Validation\Validator(),
    new \GeoFort\Services\Booking\Presentation\EducationSelectionSummaryFactory(),
);
$mailQuote = $calculator->calculateInput(BookingPricingInput::fromRequest($mail));
$html = $template->html($mail, null, $mailQuote);
$assert(str_contains($html, '17,50') && str_contains($html, '700,00'), 'Mail contains actual four-module unit and total prices');
$assert(str_contains($html, 'Dagprogramma met vier modules (10:00–14:00)'), 'Mail shows four-module program and times');
$assert(str_contains($html, 'Vleermuizen Speurtuin') && str_contains($html, 'Dynamische Globe'), 'Mail shows correct fixed modules');
$resolver = new \GeoFort\Services\Booking\Roster\BookingRosterResolver(
    new \GeoFort\Services\Sql\RosterSqlService($pdo), new \GeoFort\Services\Booking\Roster\RosterGroupCountResolver(),
);
$preview = $resolver->resolve($mail->schoolSector, $mail->programma, $mail->educationSelection,
    $mail->keuzemoduleKey, $mail->aantalLeerlingen, $mail->aantalBegeleiders, $mail->programVersion);
$assert($preview->available && str_contains((string) $preview->pdfUrl, '/four-2027-po-klimaat-experience-3.pdf'), 'Correct four-module PDF attached to four-module request');
$assert($preview->groupCount === 3 && count($preview->standardModules) === 3, 'Preview uses 19-student norm and three fixed modules');
fwrite(STDOUT, "PASS: {$checks} booking persistence, modification and mail pricing checks (isolated SQLite)\n");

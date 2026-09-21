<?php

declare(strict_types=1);

use GeoFort\Booking\BookingPolicy;
use GeoFort\Booking\BookingProgramConfig;
use GeoFort\Booking\Stored\StoredBookingAssembler;
use GeoFort\Booking\Validation\StoredBookingProgramValidator;
use GeoFort\Booking\Validation\StoredBookingValidator;
use GeoFort\Services\Booking\Data\EducationSelectionData;
use GeoFort\Services\Booking\Data\StoredBookingMailDataFactory;
use GeoFort\Services\Booking\Presentation\EducationSelectionSummaryFactory;
use GeoFort\Services\Booking\Pricing\BookingPriceCalculator;
use GeoFort\Services\Booking\Pricing\BookingPriceQuote;
use GeoFort\Services\Booking\Pricing\BookingPriceSnapshot;
use GeoFort\Services\Booking\Pricing\BookingPriceSnapshotFactory;
use GeoFort\Services\Booking\Pricing\BookingPricingInput;
use GeoFort\Services\Booking\Pricing\BookingPricingInputChecksum;
use GeoFort\Services\Booking\Roster\BookingRosterResolver;
use GeoFort\Services\Booking\Roster\RosterAttachmentResolver;
use GeoFort\Services\Booking\Roster\RosterGroupCountResolver;
use GeoFort\Services\Mail\Attachments\PublicDocumentAttachmentResolver;
use GeoFort\Services\Mail\BookingMailService;
use GeoFort\Services\Mail\BookingStatusMailSender;
use GeoFort\Services\Mail\MailConfig;
use GeoFort\Services\Mail\MailInterface;
use GeoFort\Services\Mail\Templates\BookingRejectionMailTemplate;
use GeoFort\Services\Mail\Templates\BookingRequestMailTemplate;
use GeoFort\Services\Mail\Templates\MailContentBlocks;
use GeoFort\Services\Mail\Templates\MailLayout;
use GeoFort\Services\Mail\Templates\MailLinks;
use GeoFort\Services\Sql\DisabledDatesSqlService;
use GeoFort\Services\Sql\RosterSqlService;
use GeoFort\Validation\ChoiceModuleSelectionValidator;
use GeoFort\Validation\EducationSelectionValidator;
use GeoFort\Validation\FieldValidationException;
use GeoFort\Validation\ProgramSelectionValidator;
use GeoFort\Validation\StudentCountValidator;
use GeoFort\Validation\SupervisorCountValidator;
use GeoFort\Validation\Validator;

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$reject = static function (callable $operation): void {
    try {
        $operation();
    } catch (FieldValidationException) {
        return;
    }
    throw new RuntimeException('Invalid public selection was accepted.');
};

$standards = ['Klimaat-Experience', 'Voedsel-Innovatie', 'Dynamische-Globe', 'Earth-Watch'];
$choices = ['Crisismanagement', 'Minecraft-Programmeren', 'Stop-de-Klimaat-Klok'];
$config = BookingProgramConfig::forFrontend();
$assert($config['modules']['voortgezetBovenbouw']['dag'] === ['standaard' => $standards, 'keuze' => $choices], 'Upper school must expose four standards and exactly three choices.');
$assert($config['modules']['voortgezetOnderbouw']['dag'] === [
    'standaard' => $standards,
    'keuze' => ['Minecraft-Windenergiespeurtocht', 'Stop-de-Klimaat-Klok', 'Minecraft-Programmeren', 'Klimparcours'],
], 'Lower school modules changed.');
$assert($config['modules']['primairOnderwijs'] === [
    'ochtend' => ['standaard' => ['Zandtafel', 'Rising-Risk', 'Dynamische-Globe', 'Dynamische-Globe-Bios', 'Expedition-Earth'], 'keuze' => []],
    'dag' => ['standaard' => ['Klimaat-Experience', 'Klimparcours', 'Voedsel-Innovatie', 'Dynamische-Globe'], 'keuze' => ['Minecraft-Klimaatspeurtocht', 'Earth-Watch', 'Stop-de-Klimaat-Klok', 'Minecraft-Programmeren']],
], 'Primary school modules changed.');

// This test never loads .env. Before any writes, prove this is a fresh disposable
// SQLite :memory: database: no network host, no port and no database file.
$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$databases = $pdo->query('PRAGMA database_list')->fetchAll(PDO::FETCH_ASSOC);
$assert($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite' && count($databases) === 1
    && $databases[0]['name'] === 'main' && $databases[0]['file'] === '', 'Disposable database guard failed.');
$pdo->exec('CREATE TABLE roosters (id INTEGER PRIMARY KEY, schooltype TEXT, keuzemodule TEXT, leerlingen_min INTEGER, leerlingen_max INTEGER, programmaduur TEXT, afbeelding TEXT, pdf TEXT)');
$pdo->exec('CREATE TABLE disabled_dates (datum TEXT)');
$insert = $pdo->prepare('INSERT INTO roosters (schooltype, keuzemodule, leerlingen_min, leerlingen_max, programmaduur, afbeelding, pdf) VALUES (?, ?, ?, ?, ?, ?, ?)');
$ranges = [[40, 50, 3], [51, 65, 4], [66, 80, 5], [81, 100, 6], [101, 120, 7], [121, 130, 8], [131, 150, 9], [151, 160, 10]];
$morningRanges = [[40, 40, 2], [41, 50, 3], [51, 65, 4], [66, 80, 5]];
$prefixes = ['Crisismanagement' => 'vo_boven_cmg', 'Minecraft-Programmeren' => 'vo_boven_mp', 'Stop-de-Klimaat-Klok' => 'vo_boven_sdk'];
$addRoster = static function (string $sector, string $module, string $program, array $range, string $prefix) use ($insert): void {
    [$min, $max, $groups] = $range;
    $insert->execute([$sector, $module, $min, $max, $program, "images/{$prefix}_{$groups}.png", "pdf/{$prefix}_{$groups}.pdf"]);
};
foreach ($prefixes as $module => $prefix) foreach ($ranges as $range) $addRoster('bovenbouw', $module, 'dag', $range, $prefix);
foreach ($ranges as $range) {
    $addRoster('primair', 'Earth-Watch', 'dag', $range, 'po_ew');
    $addRoster('onderbouw', 'Minecraft-Programmeren', 'dag', $range, 'vo_onder_mp');
}
foreach ($morningRanges as $range) $addRoster('primair', 'Standaard-Ochtend-Programma-PO', 'ochtend', $range, 'po_ochtend_standaard');
// A wrong duration must never win an otherwise matching selection.
$addRoster('bovenbouw', 'Stop-de-Klimaat-Klok', 'ochtend', [40, 160, 99], 'wrong-duration');
$pdo->exec('PRAGMA query_only = ON');
$writesBefore = $pdo->query('SELECT total_changes()')->fetchColumn();

$rosters = new BookingRosterResolver(new RosterSqlService($pdo), new RosterGroupCountResolver());
$education = (new EducationSelectionValidator())->validate(json_encode([
    'sector' => 'voortgezetBovenbouw', 'selectedLevels' => ['havo'], 'selectedGroupsByLevel' => ['havo' => ['havo4']],
], JSON_THROW_ON_ERROR), 'voortgezetBovenbouw');
$moduleValidator = new ChoiceModuleSelectionValidator();
$programValidator = new ProgramSelectionValidator();
$studentValidator = new StudentCountValidator();
$supervisorValidator = new SupervisorCountValidator();
$programValidator->validate('dag', 'voortgezetBovenbouw', new DateTimeImmutable('2027-02-08'));
foreach ($choices as $module) {
    $assert($moduleValidator->validate($module, 'voortgezetBovenbouw', 'dag', $education) === $module, 'Public validator changed the chosen key.');
    foreach ($ranges as [$min, $max, $groups]) for ($students = $min; $students <= $max; $students++) {
        $result = $rosters->resolve('voortgezetBovenbouw', 'dag', $education, $module, $students, 10);
        $assert($result->available && $result->groupCount === $groups && $result->choiceModule['key'] === $module, 'Wrong upper school roster selection.');
        $assert(array_column($result->standardModules, 'key') === $standards, 'Roster has incorrect standards or six activities.');
        $assert($result->pdfUrl === "/assets/booking/roosters/pdf/{$prefixes[$module]}_{$groups}.pdf"
            && $result->imageUrl === "/assets/booking/roosters/images/{$prefixes[$module]}_{$groups}.png", 'Wrong upper school roster paths.');
    }
}
foreach ([null, '', 'Unknown', 'Earth-Watch', ['Crisismanagement', 'Stop-de-Klimaat-Klok'], 'Crisismanagement,Stop-de-Klimaat-Klok'] as $invalid) {
    $reject(static fn () => $moduleValidator->validate($invalid, 'voortgezetBovenbouw', 'dag', $education));
}
foreach ([22, 39, 161] as $invalid) $reject(static fn () => $studentValidator->validate($invalid, 'voortgezetBovenbouw', 'dag'));
$reject(static fn () => $programValidator->validate('ochtend', 'voortgezetBovenbouw', new DateTimeImmutable('2027-02-10')));

$mailer = new class implements MailInterface {
    public array $messages = [];
    public function send(string $toEmail, string $toName, string $subject, string $htmlBody, string $textBody, array $cc = [], array $attachments = [], array $bcc = []): void
    {
        $this->messages[] = compact('toEmail', 'htmlBody', 'textBody', 'attachments');
    }
};
$links = new MailLinks('https://example.test', 'https://example.test/booking/voorwaarden.php', 'education@example.test');
$layout = new MailLayout($links);
$template = new BookingRequestMailTemplate($layout, $links, new Validator(), new EducationSelectionSummaryFactory());
$mailConfig = new MailConfig('unused.invalid', 1, '', '', '', 'sender@example.test', 'Fixture', 'planner@example.test', 'test@example.test', [], 'development');
$attachments = new RosterAttachmentResolver($root . '/public');
$documents = new PublicDocumentAttachmentResolver($root . '/public');
$mustNotRecalculate = new class {
    public function calculate(mixed ...$arguments): never { throw new RuntimeException('Mail recalculated a stored price.'); }
};
$requestMailer = new BookingMailService($mailer, $mailConfig, $template, $rosters, $attachments, $mustNotRecalculate, $documents);
$dataFactory = new StoredBookingMailDataFactory();
$confirmationMailer = new BookingStatusMailSender($mailer, $mailConfig, $template,
    new BookingRejectionMailTemplate($layout, $links, new MailContentBlocks($links)), $dataFactory, $rosters, $attachments, $documents);

$baseRow = [
    'id' => 7, 'status' => 'In optie', 'schoolnaam' => 'Synthetic school', 'land' => 'Nederland',
    'adres' => 'Teststraat 1', 'postcode' => '1234 AB', 'plaats' => 'Teststad',
    'school_telefoonnummer' => '0345123456', 'contactpersoon_telefoonnummer' => '0612345678',
    'contactpersoon_voornaam' => 'Test', 'contactpersoon_achternaam' => 'Fixture', 'email' => 'fixture@example.test',
    'bezoekdatum' => '2027-02-08', 'cjpPasGebruik' => 'nee', 'onderwijs_sector' => 'voortgezetBovenbouw',
    'programma' => 'dag', 'remise_break' => 2, 'kazerne_break' => 0, 'fortgracht_break' => 0,
    'glas_limonade' => 0, 'waterijsje' => 0, 'remise_lunch' => 0, 'eigen_picknick' => 1,
    'voorwaarden_akkoord' => 1, 'voorwaarden_akkoord_op' => '2026-09-01 10:00:00',
];
$counts = [[50, 8, 3], [121, 11, 8], [160, 14, 10]];
$htmlField = static function (string $html, string $label): string {
    $document = new DOMDocument();
    $previous = libxml_use_internal_errors(true);
    $document->loadHTML($html);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);
    return trim((string) (new DOMXPath($document))->evaluate("string(//tr[td[1][normalize-space(.) = '{$label}']]/td[2])"));
};

foreach ($choices as $index => $module) {
    [$students, $supervisors, $groups] = $counts[$index];
    $row = $baseRow + ['keuzemodule_key' => $module, 'aantal_leerlingen' => $students, 'aantal_begeleiders' => $supervisors];
    $booking = (new StoredBookingAssembler())->assemble($row, [['sector_key' => 'voortgezetBovenbouw', 'level_key' => 'havo', 'group_key' => 'havo4']]);
    $assert($studentValidator->validate($students, 'voortgezetBovenbouw', 'dag') === $students
        && $supervisorValidator->validate($supervisors, $students) === $supervisors, 'Public counts changed.');
    $assert((new StoredBookingProgramValidator())->validate($booking)->issues === [], 'Dashboard program/module validation rejected a valid choice.');
    $assert((new StoredBookingValidator(new DisabledDatesSqlService($pdo)))->validateForTargetStatus($booking, BookingPolicy::STATUS_CONFIRMED, new DateTimeImmutable('2027-01-01'))->issues === [], 'Dashboard confirmation validation rejected a valid choice.');
    $badBooking = $booking->withProgramConfiguration('dag', $students, $education, 'Earth-Watch');
    $assert((new StoredBookingProgramValidator())->validate($badBooking)->hasCode('CURRENT_CONFIGURATION_MISMATCH'), 'Dashboard accepts a standard module as the choice.');

    // Synthetic saved snapshot using the existing price version; no historical
    // agreement is inferred from a real booking. Rendering consumes saved details.
    $input = BookingPricingInput::fromStoredBooking($booking);
    $snapshot = (new BookingPriceSnapshotFactory())->complete($booking->id, 1, null, BookingPriceSnapshot::REASON_SUBMISSION,
        $input, (new BookingPriceCalculator())->calculateInput($input), null);
    $before = serialize([$row, $booking, $snapshot]);
    $savedDetails = json_encode($snapshot->details, JSON_THROW_ON_ERROR);
    $quote = BookingPriceQuote::fromArray(json_decode($savedDetails, true, 512, JSON_THROW_ON_ERROR));
    $request = $dataFactory->fromStoredBooking($booking);
    $assert($request->keuzemoduleKey === $module && $request->aantalLeerlingen === $students
        && $request->aantalBegeleiders === $supervisors, 'Stored choice/counts changed during mail conversion.');
    $requestMailer->sendRequestReceivedMail($request, $quote);
    $confirmationMailer->sendConfirmation($booking, $quote);
    foreach (array_slice($mailer->messages, -2) as $message) {
        $assert($message['toEmail'] === 'test@example.test', 'Local mail safety changed.');
        $expectedStandards = 'Klimaat Experience, Voedsel Innovatie, Dynamische Globe, Earth Watch';
        $choiceLabel = BookingProgramConfig::getModuleLabel($module);
        $assert($htmlField($message['htmlBody'], 'Standaard onderdelen') === $expectedStandards
            && str_contains($message['textBody'], 'Standaard onderdelen: ' . $expectedStandards . "\n"), 'Mail must contain exactly four standard activities.');
        $assert($htmlField($message['htmlBody'], 'Keuzemodule') === $choiceLabel
            && str_contains($message['textBody'], 'Keuzemodule: ' . $choiceLabel . "\n"), 'Mail lost the saved choice.');
        foreach (['htmlBody', 'textBody'] as $format) {
            $assert(substr_count($message[$format], 'Stop de Klimaat Klok') === ($module === 'Stop-de-Klimaat-Klok' ? 1 : 0), 'Climate clock appears twice or remains a standard.');
            $assert(str_contains($message[$format], number_format($snapshot->totalAmountInclVatCents / 100, 2, ',', '.')), 'Mail lost the saved total.');
        }
        $assert($htmlField($message['htmlBody'], 'Aantal leerlingen') === (string) $students
            && $htmlField($message['htmlBody'], 'Aantal begeleiders') === (string) $supervisors, 'Mail changed attendance.');
        $assert(str_contains($message['textBody'], 'Aantal leerlingen: ' . $students)
            && str_contains($message['textBody'], 'Aantal begeleiders: ' . $supervisors), 'Plain-text mail changed attendance.');
        $assert(count($message['attachments']) === 2
            && basename($message['attachments'][0]->path) === "{$prefixes[$module]}_{$groups}.pdf", 'Mail selected the wrong concept roster attachment.');
    }
    $assert(serialize([$row, $booking, $snapshot]) === $before && $quote->toArray() === $snapshot->details, 'Rendering changed a saved booking or price snapshot.');
    $assert((new BookingPricingInputChecksum())->matches(BookingPricingInput::fromRequest($request), $snapshot->inputChecksum, $snapshot->checksumFormatVersion), 'Module correction invalidated the saved pricing input.');
}

foreach ([['primairOnderwijs', 'dag', 'Earth-Watch', 'regulier', 'groep7', $ranges], ['voortgezetOnderbouw', 'dag', 'Minecraft-Programmeren', 'havo', 'havo2', $ranges], ['primairOnderwijs', 'ochtend', null, 'regulier', 'groep7', $morningRanges]] as [$sector, $program, $module, $level, $group, $sectorRanges]) {
    $selection = new EducationSelectionData($sector, [$level], [$level => [$group]]);
    $assert($moduleValidator->validate($module, $sector, $program, $selection) === $module, 'Other sector/module validation changed.');
    $programValidator->validate($program, $sector, new DateTimeImmutable($program === 'ochtend' ? '2027-02-10' : '2027-02-08'));
    foreach ($sectorRanges as [$min, $max, $groups]) foreach (array_unique([$min, $max]) as $students) {
        $studentValidator->validate($students, $sector, $program);
        $result = $rosters->resolve($sector, $program, $selection, $module, $students, 10);
        $assert($result->available && $result->groupCount === $groups, 'Existing primary/lower/morning group ranges changed.');
    }
    foreach ([22, 39, $program === 'ochtend' ? 81 : 161] as $invalid) $reject(static fn () => $studentValidator->validate($invalid, $sector, $program));
}
$assert($pdo->query('SELECT total_changes()')->fetchColumn() === $writesBefore, 'Validation or mail rendering performed database writes.');
fwrite(STDOUT, "OK: upper school choices, 363 roster selections, public/dashboard validation, six fake mails, saved prices and unchanged sector/group limits. SQLite :memory: only; no SMTP.\n");

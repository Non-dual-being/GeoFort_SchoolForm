<?php

declare(strict_types=1);

use GeoFort\Booking\BookingPolicy;
use GeoFort\Booking\BookingProgramConfig;
use GeoFort\Booking\Capacity\BookingCapacityValidator;
use GeoFort\Booking\Capacity\PolicyCapacityLimitProvider;
use GeoFort\Booking\Status\BookingStatusChangeCode;
use GeoFort\Booking\Status\BookingStatusChangeCommand;
use GeoFort\Booking\Status\BookingStatusMailMode;
use GeoFort\Booking\Rules\BookingRuleOverrideRequest;
use GeoFort\Booking\Rules\AuthenticatedAdminBookingOverrideAuthorizationService;
use GeoFort\Booking\Rules\BookingRuleContextFingerprint;
use GeoFort\Booking\Rules\BookingRuleOverridePolicy;
use GeoFort\Booking\Stored\StoredBooking;
use GeoFort\Booking\Status\BookingStatusTransitionPolicy;
use GeoFort\Booking\Stored\StoredBookingAssembler;
use GeoFort\Booking\Validation\StoredBookingValidator;
use GeoFort\Services\Booking\Status\BookingStatusChangeService;
use GeoFort\Services\Booking\Status\BookingStatusMailSenderInterface;
use GeoFort\Services\Booking\Pricing\BookingPriceCalculator;
use GeoFort\Services\Booking\Pricing\BookingPriceQuote;
use GeoFort\Services\Booking\Pricing\StoredBookingPricingInputFactory;
use GeoFort\Services\Sql\BookingCalendarSqlService;
use GeoFort\Services\Sql\BookingDaySettingsSqlRepository;
use GeoFort\Services\Sql\BookingStatusHistorySqlRepository;
use GeoFort\Services\Sql\BookingStatusSqlRepository;
use GeoFort\Services\Sql\BookingRuleOverrideSqlRepository;
use GeoFort\Services\Sql\DisabledDatesSqlService;
use GeoFort\Services\Sql\StoredBookingSqlRepository;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

final class SuccessfulStatusMailSender implements BookingStatusMailSenderInterface
{
    public int $confirmations = 0;
    public int $rejections = 0;
    public ?StoredBooking $lastBooking = null;
    public ?BookingPriceQuote $lastQuote = null;

    public function sendConfirmation(StoredBooking $booking, BookingPriceQuote $quote): void { $this->confirmations++; $this->lastBooking = $booking; $this->lastQuote = $quote; }
    public function sendRejection(StoredBooking $booking): void { $this->rejections++; $this->lastBooking = $booking; }
}
final class ThrowingStatusMailSender implements BookingStatusMailSenderInterface
{
    public function sendConfirmation(StoredBooking $booking, BookingPriceQuote $quote): void { throw new RuntimeException('SMTP test failure'); }
    public function sendRejection(StoredBooking $booking): void { throw new RuntimeException('SMTP test failure'); }
}

$required = ['HOST', 'PORT', 'NAME', 'USER'];
$env = [];
foreach ($required as $key) {
    $value = getenv('STATUS_TEST_DB_' . $key);
    if ($value === false) {
        fwrite(STDOUT, "SKIP: geen expliciete STATUS_TEST_DB_* disposable databaseconfiguratie.\n");
        exit(0);
    }
    $env[$key] = (string) $value;
}
if (getenv('STATUS_TEST_DB_CONFIRM') !== 'YES_DISPOSABLE' || preg_match('/(test|tmp|scratch|disposable)/i', $env['NAME']) !== 1 || strtolower((string) getenv('APP_ENV')) === 'production') {
    fwrite(STDERR, "FAIL: integratietest weigert niet-aantoonbaar wegwerpbare database.\n");
    exit(2);
}

$connect = static function () use ($env): PDO {
    $password = getenv('STATUS_TEST_DB_PASSWORD');
    return new PDO(
        sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $env['HOST'], $env['PORT'], $env['NAME']),
        $env['USER'],
        $password === false ? '' : $password,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false],
    );
};
$pdo = $connect();
$cleanup = static function () use ($pdo): void {
    $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
    try {
        foreach (['booking_rule_overrides', 'booking_status_history', 'booking_day_settings', 'aanvraag_onderwijs_selecties', 'disabled_dates', 'aanvragen', 'admin_users'] as $table) {
            $pdo->exec("DROP TABLE IF EXISTS {$table}");
        }
    } finally {
        $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
    }
};
$cleanup();
$exitCode = 0;
try {
$pdo->exec("CREATE TABLE aanvragen (
    id INT NOT NULL AUTO_INCREMENT, status ENUM('In optie','Definitief','Afgewezen') NOT NULL DEFAULT 'In optie',
    schoolnaam VARCHAR(255) NOT NULL, land VARCHAR(32) NOT NULL, adres VARCHAR(255) NOT NULL, postcode VARCHAR(16) NOT NULL, plaats VARCHAR(120) NOT NULL,
    school_telefoonnummer VARCHAR(25) NOT NULL, contactpersoon_telefoonnummer VARCHAR(25) NOT NULL, contactpersoon_voornaam VARCHAR(255) NOT NULL,
    contactpersoon_achternaam VARCHAR(255) NOT NULL, email VARCHAR(255) NOT NULL, bezoekdatum DATE NOT NULL, hoe_kent_u_geofort VARCHAR(120), opmerkingen TEXT,
    cjpPasGebruik ENUM('ja','nee') NOT NULL DEFAULT 'nee', cjpContactpersoonNaam VARCHAR(80), cjpPasnummer VARCHAR(9),
    onderwijs_sector VARCHAR(40) NOT NULL, programma VARCHAR(20) NOT NULL, keuzemodule_key VARCHAR(120), aantal_leerlingen INT UNSIGNED, aantal_begeleiders INT UNSIGNED,
    remise_break INT UNSIGNED NOT NULL DEFAULT 0, kazerne_break INT UNSIGNED NOT NULL DEFAULT 0, fortgracht_break INT UNSIGNED NOT NULL DEFAULT 0,
    glas_limonade INT UNSIGNED NOT NULL DEFAULT 0, waterijsje INT UNSIGNED NOT NULL DEFAULT 0, remise_lunch INT UNSIGNED NOT NULL DEFAULT 0,
    eigen_picknick TINYINT(1) NOT NULL DEFAULT 0, voorwaarden_akkoord TINYINT(1) NOT NULL DEFAULT 0, voorwaarden_akkoord_op DATETIME,
    source_system VARCHAR(80), source_record_id BIGINT UNSIGNED, source_record_checksum CHAR(64), source_import_run_id BIGINT UNSIGNED,
    PRIMARY KEY(id), KEY idx_date_status(bezoekdatum,status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci");
$pdo->exec('CREATE TABLE admin_users (id INT UNSIGNED NOT NULL AUTO_INCREMENT, email VARCHAR(190) NOT NULL, name VARCHAR(120) NOT NULL, role VARCHAR(50) NOT NULL DEFAULT \'admin\', password_hash VARCHAR(255) NOT NULL, is_active TINYINT(1) NOT NULL DEFAULT 1, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY(id), UNIQUE KEY(email)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci');
$pdo->exec('CREATE TABLE aanvraag_onderwijs_selecties (id INT NOT NULL AUTO_INCREMENT, aanvraag_id INT NOT NULL, sector_key VARCHAR(40), level_key VARCHAR(80), group_key VARCHAR(80), level_position INT, group_position INT, PRIMARY KEY(id), FOREIGN KEY(aanvraag_id) REFERENCES aanvragen(id) ON DELETE CASCADE) ENGINE=InnoDB');
$pdo->exec('CREATE TABLE disabled_dates (datum DATE NOT NULL PRIMARY KEY, type VARCHAR(30), reden VARCHAR(255)) ENGINE=InnoDB');
$pdo->exec(file_get_contents(dirname(__DIR__, 2) . '/database/sql/2026-07-17_create_booking_day_settings.sql'));
$pdo->exec(file_get_contents(dirname(__DIR__, 2) . '/database/sql/2026-07-18_create_booking_status_history.sql'));
$pdo->exec(file_get_contents(dirname(__DIR__, 2) . '/database/sql/2026-07-21_create_booking_rule_overrides.sql'));
$mailModeSchema = $pdo->query(<<<'SQL'
    SELECT COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'booking_status_history'
      AND COLUMN_NAME = 'mail_mode'
    SQL)->fetch();
$pdo->exec("INSERT INTO admin_users(email,name,password_hash) VALUES ('admin@example.test','Admin','test')");
$adminId = (int) $pdo->lastInsertId();
$schoolSector = 'primairOnderwijs';
$program = BookingPolicy::PROGRAM_DAY;
$minStudents = BookingProgramConfig::getMinStudentsForSelection($schoolSector, $program);
$maxSchools = BookingPolicy::MAX_SCHOOLS_PER_DAY;
$maxStudents = BookingPolicy::MAX_STUDENTS_TOTAL_PER_DAY;

$service = static function (PDO $connection, ?BookingStatusMailSenderInterface $mailSender = null): BookingStatusChangeService {
    $disabled = new DisabledDatesSqlService($connection);
    return new BookingStatusChangeService(
        $connection,
        new StoredBookingSqlRepository($connection, new StoredBookingAssembler()),
        new BookingStatusSqlRepository($connection),
        new BookingStatusHistorySqlRepository($connection),
        new BookingDaySettingsSqlRepository($connection),
        new BookingCalendarSqlService($connection),
        $disabled,
        new StoredBookingValidator($disabled),
        new BookingStatusTransitionPolicy(),
        new PolicyCapacityLimitProvider(),
        new BookingCapacityValidator(),
        new StoredBookingPricingInputFactory(),
        new BookingPriceCalculator(),
        $mailSender ?? new SuccessfulStatusMailSender(),
        new BookingRuleOverrideSqlRepository($connection),
        new BookingRuleOverridePolicy(),
        new AuthenticatedAdminBookingOverrideAuthorizationService(),
        new BookingRuleContextFingerprint(),
    );
};
$insertBooking = static function (
    string $status,
    string $date,
    ?int $students = null,
    bool $legacyMismatch = false,
    ?int $supervisorCount = null,
) use ($pdo, $minStudents, $schoolSector, $program): int {
    $studentCount = $students ?? $minStudents;
    $supervisors = $supervisorCount ?? BookingPolicy::getMinimumSupervisorCount($studentCount);
    $statement = $pdo->prepare("INSERT INTO aanvragen (
        status,schoolnaam,land,adres,postcode,plaats,school_telefoonnummer,contactpersoon_telefoonnummer,contactpersoon_voornaam,contactpersoon_achternaam,email,
        bezoekdatum,cjpPasGebruik,onderwijs_sector,programma,keuzemodule_key,aantal_leerlingen,aantal_begeleiders,eigen_picknick,voorwaarden_akkoord,voorwaarden_akkoord_op,source_system
    ) VALUES (:status,'Testschool','Nederland','Dijk 1','1234 AB','Teststad','0123456789','0612345678','Jan','Jansen','jan@example.test',
        :date,'nee',:schoolSector,:program,'Earth-Watch',:students,:supervisors,1,1,'2026-07-17 10:00:00',:source)");
    $statement->execute([':status' => $status, ':date' => $date, ':schoolSector' => $schoolSector, ':program' => $program, ':students' => $studentCount, ':supervisors' => $supervisors, ':source' => $legacyMismatch ? 'legacy_geoform' : null]);
    $id = (int) $pdo->lastInsertId();
    if (!$legacyMismatch) {
        $pdo->prepare("INSERT INTO aanvraag_onderwijs_selecties(aanvraag_id,sector_key,level_key,group_key,level_position,group_position) VALUES (:id,'primairOnderwijs','regulier','groep5',1,1)")->execute([':id' => $id]);
    }
    return $id;
};
$change = static function (int $id, string $expected, string $target, ?int $actingAdminId = null, array $overrides = []) use ($service, $pdo, $adminId) {
    return $service($pdo)->change(new BookingStatusChangeCommand($id, $expected, $target, $actingAdminId ?? $adminId, BookingStatusMailMode::None, $overrides), new DateTimeImmutable('2026-07-18'));
};
$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void { if (!$condition) $failures[] = $message; };
$assert(
    $mailModeSchema !== false
    && $mailModeSchema['COLUMN_TYPE'] === "enum('none','send')"
    && $mailModeSchema['IS_NULLABLE'] === 'NO'
    && $mailModeSchema['COLUMN_DEFAULT'] === 'none',
    'mail_mode-schema ondersteunt niet exact none/send met default none.',
);
$assert($minStudents > 0, 'Geconfigureerd minimumaantal leerlingen moet positief zijn.');
$assert($maxSchools >= 2, 'Capaciteitstest vereist minimaal twee scholen per dag.');
$assert($maxStudents >= $minStudents * 2, 'Capaciteitstest vereist ruimte voor twee minimale geldige aanvragen.');
$assert(BookingPolicy::getMinimumSupervisorCount($maxStudents) <= BookingPolicy::MAX_SUPERVISORS_PER_BOOKING, 'Maximale dagaanvraag vereist meer begeleiders dan toegestaan.');
$status = static fn(int $id): string => (string) $pdo->query("SELECT status FROM aanvragen WHERE id={$id}")->fetchColumn();
$historyCount = static fn(int $id): int => (int) $pdo->query("SELECT COUNT(*) FROM booking_status_history WHERE booking_id={$id}")->fetchColumn();
$resultDiagnostic = static function ($result, BookingStatusChangeCode $expected, int $id, array $requested = []) use ($status, $historyCount): string {
    $issues = array_map(static fn($issue): string => sprintf('%s(overridable=%s,severity=%s)', $issue->code, $issue->overridable ? 'true' : 'false', $issue->severity->value), $result->validationIssues);

    return sprintf(
        'expected=%s actual=%s validation=[%s] requested=[%s] capacity=%s database_status=%s audit_count=%d',
        $expected->value,
        $result->code->value,
        implode(',', $issues),
        implode(',', array_map(static fn($override): string => $override->ruleCode, $requested)),
        $result->capacityResult?->code->value ?? 'none',
        $status($id),
        $historyCount($id),
    );
};

$transitionCases = [[BookingPolicy::STATUS_OPTION, BookingPolicy::STATUS_CONFIRMED], [BookingPolicy::STATUS_REJECTED, BookingPolicy::STATUS_CONFIRMED], [BookingPolicy::STATUS_OPTION, BookingPolicy::STATUS_REJECTED], [BookingPolicy::STATUS_CONFIRMED, BookingPolicy::STATUS_OPTION], [BookingPolicy::STATUS_CONFIRMED, BookingPolicy::STATUS_REJECTED], [BookingPolicy::STATUS_REJECTED, BookingPolicy::STATUS_OPTION]];
foreach ($transitionCases as $index => [$from, $to]) {
    $id = $insertBooking($from, '2026-09-' . str_pad((string) (10 + $index), 2, '0', STR_PAD_LEFT));
    $result = $change($id, $from, $to);
    $assert($result->code === BookingStatusChangeCode::Success && $status($id) === $to, "Overgang {$from} -> {$to} faalt.");
}
$same = $insertBooking(BookingPolicy::STATUS_OPTION, '2026-10-01');
$assert($change($same, BookingPolicy::STATUS_OPTION, BookingPolicy::STATUS_OPTION)->code === BookingStatusChangeCode::NoStatusChange, 'Zelfde status geeft niet NO_STATUS_CHANGE.');
$assert($change(999999, BookingPolicy::STATUS_OPTION, BookingPolicy::STATUS_REJECTED)->code === BookingStatusChangeCode::BookingNotFound, 'Onbekend ID geeft niet BOOKING_NOT_FOUND.');
$conflict = $insertBooking(BookingPolicy::STATUS_REJECTED, '2026-10-02');
$assert($change($conflict, BookingPolicy::STATUS_OPTION, BookingPolicy::STATUS_CONFIRMED)->code === BookingStatusChangeCode::StatusConflict && $historyCount($conflict) === 0, 'Expected-statusconflict muteert of auditeert.');

$disabledId = $insertBooking(BookingPolicy::STATUS_OPTION, '2026-11-09');
$pdo->exec("INSERT INTO disabled_dates VALUES ('2026-11-09','manual','test')");
$disabledRequired = $change($disabledId, BookingPolicy::STATUS_OPTION, BookingPolicy::STATUS_CONFIRMED);
$assert($disabledRequired->code === BookingStatusChangeCode::OverrideRequired, 'Disabled datum vraagt geen override: ' . $resultDiagnostic($disabledRequired, BookingStatusChangeCode::OverrideRequired, $disabledId));
$disabledOverride = $change($disabledId, BookingPolicy::STATUS_OPTION, BookingPolicy::STATUS_CONFIRMED, null, [new BookingRuleOverrideRequest('DISABLED_VISIT_DATE', 'De planner accepteert bewust deze geblokkeerde bezoekdatum.')]);
$assert($disabledOverride->success, 'Disabled datum kan niet expliciet worden overschreven: ' . $resultDiagnostic($disabledOverride, BookingStatusChangeCode::Success, $disabledId, [new BookingRuleOverrideRequest('DISABLED_VISIT_DATE', 'De planner accepteert bewust deze geblokkeerde bezoekdatum.')]));
$pdo->prepare('UPDATE aanvragen SET status = :status WHERE id = :id')->execute([':status' => BookingPolicy::STATUS_OPTION, ':id' => $disabledId]);
$assert($change($disabledId, BookingPolicy::STATUS_OPTION, BookingPolicy::STATUS_REJECTED)->success, 'Disabled datum blokkeert afwijzen.');
$historical = $insertBooking(BookingPolicy::STATUS_REJECTED, '2026-07-17');
$assert($change($historical, BookingPolicy::STATUS_REJECTED, BookingPolicy::STATUS_CONFIRMED)->code === BookingStatusChangeCode::HistoricalDate, 'Historische datum blokkeert bevestiging niet.');
$assert($change($historical, BookingPolicy::STATUS_REJECTED, BookingPolicy::STATUS_OPTION)->success, 'Historische datum blokkeert optie.');
$legacy = $insertBooking(BookingPolicy::STATUS_OPTION, '2026-10-04', 40, true);
$assert($change($legacy, BookingPolicy::STATUS_OPTION, BookingPolicy::STATUS_CONFIRMED)->code === BookingStatusChangeCode::InvalidStoredBooking, 'Legacyafwijking blokkeert bevestiging niet.');
$assert($change($legacy, BookingPolicy::STATUS_OPTION, BookingPolicy::STATUS_REJECTED)->success, 'Legacyafwijking blokkeert afwijzen.');

$cjp = $insertBooking(BookingPolicy::STATUS_OPTION, '2026-11-02');
$pdo->prepare("UPDATE aanvragen SET cjpPasGebruik='ja', cjpContactpersoonNaam='Historisch', cjpPasnummer=NULL WHERE id=:id")->execute([':id' => $cjp]);
$cjpRequired = $change($cjp, BookingPolicy::STATUS_OPTION, BookingPolicy::STATUS_CONFIRMED);
$assert($cjpRequired->code === BookingStatusChangeCode::OverrideRequired, 'Onvolledige CJP-details vragen geen override: ' . $resultDiagnostic($cjpRequired, BookingStatusChangeCode::OverrideRequired, $cjp));
$cjpResult = $change($cjp, BookingPolicy::STATUS_OPTION, BookingPolicy::STATUS_CONFIRMED, null, [new BookingRuleOverrideRequest('INCOMPLETE_CJP_DETAILS', 'Historische aanvraag; het pasnummer is destijds niet opgeslagen.')]);
$cjpAudit = $pdo->query("SELECT booking_id,status_history_id,rule_code,reason,context_fingerprint,metadata_json,approved_by_admin_id FROM booking_rule_overrides WHERE booking_id={$cjp}")->fetch();
$cjpOverrideCount = (int) $pdo->query("SELECT COUNT(*) FROM booking_rule_overrides WHERE booking_id={$cjp}")->fetchColumn();
$assert($cjpResult->success && count($cjpResult->overriddenRules) === 1, 'Geldige CJP-override slaagt niet: ' . $resultDiagnostic($cjpResult, BookingStatusChangeCode::Success, $cjp, [new BookingRuleOverrideRequest('INCOMPLETE_CJP_DETAILS', 'Historische aanvraag; het pasnummer is destijds niet opgeslagen.')]));
$assert(
    $cjpAudit !== false
    && $cjpOverrideCount === 1
    && $historyCount($cjp) === 1
    && (int) $cjpAudit['booking_id'] === $cjp
    && $cjpAudit['rule_code'] === 'INCOMPLETE_CJP_DETAILS'
    && $cjpAudit['reason'] === 'Historische aanvraag; het pasnummer is destijds niet opgeslagen.'
    && preg_match('/^[a-f0-9]{64}$/', (string) $cjpAudit['context_fingerprint']) === 1
    && (int) $cjpAudit['approved_by_admin_id'] === $adminId,
    'CJP-overrideaudit is incompleet.',
);
$cjpMetadata = $cjpAudit === false ? null : json_decode((string) $cjpAudit['metadata_json'], true);
$linkedHistoryId = $cjpAudit === false ? 0 : (int) $cjpAudit['status_history_id'];
$linkedHistoryExists = $linkedHistoryId > 0 && (int) $pdo->query("SELECT COUNT(*) FROM booking_status_history WHERE id={$linkedHistoryId} AND booking_id={$cjp}")->fetchColumn() === 1;
$assert($cjpAudit !== false && $linkedHistoryExists && is_array($cjpMetadata) && ($cjpMetadata['cjpSelected'] ?? null) === true, 'Overrideaudit is niet aan geldige history/context gekoppeld.');

$staleOverride = $insertBooking(BookingPolicy::STATUS_OPTION, '2026-11-03');
$staleRequest = [new BookingRuleOverrideRequest('INCOMPLETE_CJP_DETAILS', 'Deze reden hoort niet meer bij een actueel probleem.')];
$staleResult = $change($staleOverride, BookingPolicy::STATUS_OPTION, BookingPolicy::STATUS_CONFIRMED, null, $staleRequest);
$assert($staleResult->code === BookingStatusChangeCode::InvalidOverrideRequest, 'Niet-actuele override wordt niet geweigerd: ' . $resultDiagnostic($staleResult, BookingStatusChangeCode::InvalidOverrideRequest, $staleOverride, $staleRequest));

$deniedOverride = $insertBooking(BookingPolicy::STATUS_OPTION, '2026-11-04');
$pdo->prepare("UPDATE aanvragen SET cjpPasGebruik='ja', cjpContactpersoonNaam='Historisch', cjpPasnummer=NULL WHERE id=:id")->execute([':id' => $deniedOverride]);
$assert($change($deniedOverride, BookingPolicy::STATUS_OPTION, BookingPolicy::STATUS_CONFIRMED, 0, [new BookingRuleOverrideRequest('INCOMPLETE_CJP_DETAILS', 'Deze beheerder heeft hiervoor geen toestemming gekregen.')])->code === BookingStatusChangeCode::OverridePermissionDenied, 'Permission denial ontbreekt.');

$hardOverride = $insertBooking(BookingPolicy::STATUS_OPTION, '2026-11-05');
$assert($change($hardOverride, BookingPolicy::STATUS_OPTION, BookingPolicy::STATUS_CONFIRMED, null, [new BookingRuleOverrideRequest('INVALID_VISIT_DATE', 'Deze harde regel mag onder geen beding worden overgeslagen.')])->code === BookingStatusChangeCode::OverrideNotAllowed, 'Harde rulecode kan als override worden aangevraagd.');

$supervisorOverride = $insertBooking(BookingPolicy::STATUS_OPTION, '2026-11-06', 40, false, 1);
$supervisorRequired = $change($supervisorOverride, BookingPolicy::STATUS_OPTION, BookingPolicy::STATUS_CONFIRMED);
$assert($supervisorRequired->code === BookingStatusChangeCode::OverrideRequired && $supervisorRequired->validationIssues[0]->code === 'MINIMUM_SUPERVISORS_NOT_MET', 'Begeleidersminimum is niet veilig afgesplitst.');
$assert($change($supervisorOverride, BookingPolicy::STATUS_OPTION, BookingPolicy::STATUS_CONFIRMED, null, [new BookingRuleOverrideRequest('MINIMUM_SUPERVISORS_NOT_MET', 'De planner accepteert bewust minder begeleiders voor deze groep.')])->success, 'Begeleidersminimum kan niet expliciet worden overschreven.');

$existing = $insertBooking(BookingPolicy::STATUS_CONFIRMED, '2026-10-05', $maxStudents - $minStudents);
$exact = $insertBooking(BookingPolicy::STATUS_OPTION, '2026-10-05', $minStudents);
$assert($change($exact, BookingPolicy::STATUS_OPTION, BookingPolicy::STATUS_CONFIRMED)->success, 'Exacte school- en leerlinglimiet is niet toegestaan.');
$overSchools = $insertBooking(BookingPolicy::STATUS_OPTION, '2026-10-05', $minStudents);
$overSchoolsResult = $change($overSchools, BookingPolicy::STATUS_OPTION, BookingPolicy::STATUS_CONFIRMED);
$assert($overSchoolsResult->code === BookingStatusChangeCode::OverrideRequired, 'Schoollimietoverschrijding vraagt geen override.');
$assert($change($overSchools, BookingPolicy::STATUS_OPTION, BookingPolicy::STATUS_CONFIRMED, null, [new BookingRuleOverrideRequest('SCHOOL_LIMIT_EXCEEDED', 'De planner accepteert bewust een extra school op deze datum.')])->success, 'Schoolcapaciteit kan niet expliciet worden overschreven.');
$studentBase = $insertBooking(BookingPolicy::STATUS_CONFIRMED, '2026-10-06', $maxStudents - $minStudents + 1);
$studentOver = $insertBooking(BookingPolicy::STATUS_OPTION, '2026-10-06', $minStudents);
$studentOverResult = $change($studentOver, BookingPolicy::STATUS_OPTION, BookingPolicy::STATUS_CONFIRMED);
$assert($studentOverResult->code === BookingStatusChangeCode::OverrideRequired, 'Leerlinglimietoverschrijding vraagt geen override.');
$assert($change($studentOver, BookingPolicy::STATUS_OPTION, BookingPolicy::STATUS_CONFIRMED, null, [new BookingRuleOverrideRequest('STUDENT_LIMIT_EXCEEDED', 'De planner accepteert bewust extra leerlingen op deze datum.')])->success, 'Leerlingcapaciteit kan niet expliciet worden overschreven.');
$maxCapacityBooking = $insertBooking(BookingPolicy::STATUS_REJECTED, '2026-10-07', $maxStudents);
$maxCapacityResult = $change($maxCapacityBooking, BookingPolicy::STATUS_REJECTED, BookingPolicy::STATUS_CONFIRMED);
$assert($maxCapacityResult->code === BookingStatusChangeCode::Success, 'Afgewezen aanvraag met maximale geldige dagcapaciteit kan niet worden bevestigd: ' . $resultDiagnostic($maxCapacityResult, BookingStatusChangeCode::Success, $maxCapacityBooking));

$auditBooking = $insertBooking(BookingPolicy::STATUS_OPTION, '2026-10-08');
$auditResult = $change($auditBooking, BookingPolicy::STATUS_OPTION, BookingPolicy::STATUS_REJECTED);
$assert($auditResult->code === BookingStatusChangeCode::Success, 'Eenvoudige auditstatusovergang faalt: ' . $resultDiagnostic($auditResult, BookingStatusChangeCode::Success, $auditBooking));
$audit = $pdo->query("SELECT previous_status,new_status,mail_mode,mail_sent,admin_user_id FROM booking_status_history WHERE booking_id={$auditBooking}")->fetch();
$assert(
    $audit !== false
    && $audit['previous_status'] === BookingPolicy::STATUS_OPTION
    && $audit['new_status'] === BookingPolicy::STATUS_REJECTED
    && $audit['mail_mode'] === 'none'
    && (int) $audit['mail_sent'] === 0
    && (int) $audit['admin_user_id'] === $adminId
    && $historyCount($auditBooking) === 1,
    'Auditmetadata is onjuist.',
);
$nonPhaseTwoMailAuditCount = (int) $pdo->query("SELECT COUNT(*) FROM booking_status_history WHERE mail_mode <> 'none' OR mail_sent <> 0")->fetchColumn();
$assert($nonPhaseTwoMailAuditCount === 0, 'Fase 2 schrijft mail_mode of mail_sent buiten none/0.');

$confirmationSender = new SuccessfulStatusMailSender();
$confirmationBooking = $insertBooking(BookingPolicy::STATUS_OPTION, '2026-10-20', $minStudents + 1);
$confirmationResult = $service($pdo, $confirmationSender)->change(new BookingStatusChangeCommand(
    $confirmationBooking, BookingPolicy::STATUS_OPTION, BookingPolicy::STATUS_CONFIRMED, $adminId, BookingStatusMailMode::Send,
), new DateTimeImmutable('2026-07-18'));
$confirmationAudit = $pdo->query("SELECT mail_mode,mail_sent FROM booking_status_history WHERE booking_id={$confirmationBooking}")->fetch();
$assert($confirmationResult->success && $confirmationResult->mailSent && $confirmationSender->confirmations === 1, 'Definitief + send verstuurt geen confirmation.');
$assert($confirmationSender->lastBooking?->studentCount === $minStudents + 1 && $confirmationSender->lastQuote instanceof BookingPriceQuote, 'Confirmation gebruikt niet de actuele locked booking en quote.');
$assert($confirmationAudit !== false && $confirmationAudit['mail_mode'] === 'send' && (int) $confirmationAudit['mail_sent'] === 1, 'Succesmail auditeert niet send/1.');

$rejectionSender = new SuccessfulStatusMailSender();
$rejectionBooking = $insertBooking(BookingPolicy::STATUS_OPTION, '2026-10-21');
$rejectionResult = $service($pdo, $rejectionSender)->change(new BookingStatusChangeCommand(
    $rejectionBooking, BookingPolicy::STATUS_OPTION, BookingPolicy::STATUS_REJECTED, $adminId, BookingStatusMailMode::Send,
), new DateTimeImmutable('2026-07-18'));
$assert($rejectionResult->success && $rejectionSender->rejections === 1 && $rejectionSender->lastQuote === null, 'Afgewezen + send gebruikt niet uitsluitend rejection zonder quote.');

$unsupportedBooking = $insertBooking(BookingPolicy::STATUS_REJECTED, '2026-10-22');
$unsupportedSender = new SuccessfulStatusMailSender();
$unsupportedResult = $service($pdo, $unsupportedSender)->change(new BookingStatusChangeCommand(
    $unsupportedBooking, BookingPolicy::STATUS_REJECTED, BookingPolicy::STATUS_OPTION, $adminId, BookingStatusMailMode::Send,
), new DateTimeImmutable('2026-07-18'));
$assert($unsupportedResult->code === BookingStatusChangeCode::MailNotSupportedForTargetStatus && $unsupportedSender->confirmations + $unsupportedSender->rejections === 0 && $historyCount($unsupportedBooking) === 0, 'In optie + send wordt niet schoon geweigerd.');

foreach ([BookingPolicy::STATUS_CONFIRMED, BookingPolicy::STATUS_REJECTED] as $index => $target) {
    $mailFailureBooking = $insertBooking(BookingPolicy::STATUS_OPTION, '2026-10-' . (23 + $index));
    $mailFailure = $service($pdo, new ThrowingStatusMailSender())->change(new BookingStatusChangeCommand(
        $mailFailureBooking, BookingPolicy::STATUS_OPTION, $target, $adminId, BookingStatusMailMode::Send,
    ), new DateTimeImmutable('2026-07-18'));
    $assert($mailFailure->code === BookingStatusChangeCode::MailSendFailed && $status($mailFailureBooking) === BookingPolicy::STATUS_OPTION && $historyCount($mailFailureBooking) === 0, "Mailfout naar {$target} rolt status/audit niet terug.");
}
$overrideMailFailure = $insertBooking(BookingPolicy::STATUS_OPTION, '2026-10-27');
$pdo->prepare("UPDATE aanvragen SET cjpPasGebruik='ja', cjpContactpersoonNaam='Historisch', cjpPasnummer=NULL WHERE id=:id")->execute([':id' => $overrideMailFailure]);
$overrideMailResult = $service($pdo, new ThrowingStatusMailSender())->change(new BookingStatusChangeCommand(
    $overrideMailFailure, BookingPolicy::STATUS_OPTION, BookingPolicy::STATUS_CONFIRMED, $adminId, BookingStatusMailMode::Send,
    [new BookingRuleOverrideRequest('INCOMPLETE_CJP_DETAILS', 'Historische aanvraag zonder opgeslagen CJP-pasnummer.')],
), new DateTimeImmutable('2026-07-18'));
$overrideAuditCount = (int) $pdo->query("SELECT COUNT(*) FROM booking_rule_overrides WHERE booking_id={$overrideMailFailure}")->fetchColumn();
$assert($overrideMailResult->code === BookingStatusChangeCode::MailSendFailed && $status($overrideMailFailure) === BookingPolicy::STATUS_OPTION && $historyCount($overrideMailFailure) === 0 && $overrideAuditCount === 0, 'Mailfout rolt overrideaudit niet volledig terug.');

$overrideAuditFailure = $insertBooking(BookingPolicy::STATUS_OPTION, '2026-10-28');
$pdo->prepare("UPDATE aanvragen SET cjpPasGebruik='ja', cjpContactpersoonNaam='Historisch', cjpPasnummer=NULL WHERE id=:id")->execute([':id' => $overrideAuditFailure]);
$pdo->exec("CREATE TRIGGER booking_override_audit_failure BEFORE INSERT ON booking_rule_overrides FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'test failure'");
$overrideAuditFailureResult = $change($overrideAuditFailure, BookingPolicy::STATUS_OPTION, BookingPolicy::STATUS_CONFIRMED, null, [new BookingRuleOverrideRequest('INCOMPLETE_CJP_DETAILS', 'Historische aanvraag zonder opgeslagen CJP-pasnummer.')]);
$pdo->exec('DROP TRIGGER booking_override_audit_failure');
$assert($overrideAuditFailureResult->code === BookingStatusChangeCode::DatabaseError && $status($overrideAuditFailure) === BookingPolicy::STATUS_OPTION && $historyCount($overrideAuditFailure) === 0, 'Overrideauditfout rolt status en history niet terug.');
$auditFailure = $insertBooking(BookingPolicy::STATUS_OPTION, '2026-10-09');
$result = $change($auditFailure, BookingPolicy::STATUS_OPTION, BookingPolicy::STATUS_REJECTED, 999999);
$assert($result->code === BookingStatusChangeCode::DatabaseError && $status($auditFailure) === BookingPolicy::STATUS_OPTION && $historyCount($auditFailure) === 0, 'Auditinsertfout rolt status niet terug.');
$mailBeforeAuditFailure = $insertBooking(BookingPolicy::STATUS_OPTION, '2026-10-26');
$acceptedSender = new SuccessfulStatusMailSender();
$result = $service($pdo, $acceptedSender)->change(new BookingStatusChangeCommand(
    $mailBeforeAuditFailure, BookingPolicy::STATUS_OPTION, BookingPolicy::STATUS_REJECTED, 999999, BookingStatusMailMode::Send,
), new DateTimeImmutable('2026-07-18'));
$assert($result->code === BookingStatusChangeCode::DatabaseError && $acceptedSender->rejections === 1 && $status($mailBeforeAuditFailure) === BookingPolicy::STATUS_OPTION && $historyCount($mailBeforeAuditFailure) === 0, 'Auditfout na geaccepteerde mail rolt database niet terug naar DATABASE_ERROR.');

$guarded = $insertBooking(BookingPolicy::STATUS_OPTION, '2026-10-12');
$pdo->exec('CREATE TRIGGER booking_status_noop BEFORE UPDATE ON aanvragen FOR EACH ROW SET NEW.status = OLD.status');
$guardedResult = $change($guarded, BookingPolicy::STATUS_OPTION, BookingPolicy::STATUS_REJECTED);
$pdo->exec('DROP TRIGGER booking_status_noop');
$assert($guardedResult->code === BookingStatusChangeCode::StatusConflict && $historyCount($guarded) === 0, 'Guarded rowCount 0 geeft niet STATUS_CONFLICT.');

// Two independent connections, serialized by the persistent date row: the second mutation
// re-reads committed totals and is rejected. This proves the serialized outcome, not process-level parallel execution.
$raceDate = '2026-10-13';
$raceStudents = max($minStudents, intdiv($maxStudents, 2) + 1);
$assert($raceStudents <= $maxStudents && $raceStudents * 2 > $maxStudents, 'Racefixture is niet uitsluitend op leerlingcapaciteit ingericht.');
$assert(2 <= $maxSchools, 'Racefixture zou voortijdig op schoolcapaciteit stranden.');
$raceA = $insertBooking(BookingPolicy::STATUS_OPTION, $raceDate, $raceStudents);
$raceB = $insertBooking(BookingPolicy::STATUS_OPTION, $raceDate, $raceStudents);
$connectionA = $connect();
$connectionB = $connect();
$connectionA->beginTransaction();
(new BookingDaySettingsSqlRepository($connectionA))->lockDate($raceDate);
$connectionB->exec('SET SESSION innodb_lock_wait_timeout = 1');
$connectionB->beginTransaction();
$dateLockBlocked = false;
try {
    $statement = $connectionB->prepare('SELECT visit_date FROM booking_day_settings WHERE visit_date = :visitDate FOR UPDATE');
    $statement->execute([':visitDate' => $raceDate]);
    $statement->fetchColumn();
} catch (PDOException) {
    $dateLockBlocked = true;
}
if ($connectionB->inTransaction()) $connectionB->rollBack();
$connectionA->rollBack();
$assert($dateLockBlocked, 'Datumrij blokkeert een tweede databaseverbinding niet.');
$first = $service($connectionA)->change(new BookingStatusChangeCommand($raceA, BookingPolicy::STATUS_OPTION, BookingPolicy::STATUS_CONFIRMED, $adminId, BookingStatusMailMode::None), new DateTimeImmutable('2026-07-18'));
$second = $service($connectionB)->change(new BookingStatusChangeCommand($raceB, BookingPolicy::STATUS_OPTION, BookingPolicy::STATUS_CONFIRMED, $adminId, BookingStatusMailMode::None), new DateTimeImmutable('2026-07-18'));
$assert($first->code === BookingStatusChangeCode::Success, 'Eerste racebevestiging faalt: ' . $resultDiagnostic($first, BookingStatusChangeCode::Success, $raceA));
$assert($second->code === BookingStatusChangeCode::OverrideRequired, 'Tweede racebevestiging vraagt niet om actuele override: ' . $resultDiagnostic($second, BookingStatusChangeCode::OverrideRequired, $raceB));

if ($failures !== []) {
    foreach ($failures as $failure) fwrite(STDERR, "FAIL: {$failure}\n");
    $exitCode = 1;
} else {
    fwrite(STDOUT, "OK: statusmutatie, audit, rollback, capaciteit en twee-connectie-serialisatie geslaagd.\n");
}
} catch (Throwable $exception) {
    fwrite(STDERR, 'FAIL: onverwachte integratietestfout: ' . $exception->getMessage() . "\n");
    $exitCode = 1;
} finally {
    $cleanup();
}
exit($exitCode);

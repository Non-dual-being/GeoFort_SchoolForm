<?php

declare(strict_types=1);

use GeoFort\Booking\BookingPolicy;
use GeoFort\Booking\BookingProgramConfig;
use GeoFort\Booking\Capacity\BookingCapacityValidator;
use GeoFort\Booking\Capacity\PolicyCapacityLimitProvider;
use GeoFort\Booking\Status\BookingStatusChangeCode;
use GeoFort\Booking\Status\BookingStatusChangeCommand;
use GeoFort\Booking\Status\BookingStatusTransitionPolicy;
use GeoFort\Booking\Stored\StoredBookingAssembler;
use GeoFort\Booking\Validation\StoredBookingValidator;
use GeoFort\Services\Booking\Status\BookingStatusChangeService;
use GeoFort\Services\Sql\BookingCalendarSqlService;
use GeoFort\Services\Sql\BookingDaySettingsSqlRepository;
use GeoFort\Services\Sql\BookingStatusHistorySqlRepository;
use GeoFort\Services\Sql\BookingStatusSqlRepository;
use GeoFort\Services\Sql\DisabledDatesSqlService;
use GeoFort\Services\Sql\StoredBookingSqlRepository;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

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
$pdo->exec('SET FOREIGN_KEY_CHECKS=0');
foreach (['booking_status_history', 'booking_day_settings', 'disabled_dates', 'aanvraag_onderwijs_selecties', 'aanvragen', 'admin_users'] as $table) $pdo->exec("DROP TABLE IF EXISTS {$table}");
$pdo->exec('SET FOREIGN_KEY_CHECKS=1');
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

$service = static function (PDO $connection): BookingStatusChangeService {
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
$change = static function (int $id, string $expected, string $target, ?int $actingAdminId = null) use ($service, $pdo, $adminId) {
    return $service($pdo)->change(new BookingStatusChangeCommand($id, $expected, $target, $actingAdminId ?? $adminId), new DateTimeImmutable('2026-07-18'));
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
$resultDiagnostic = static function ($result, BookingStatusChangeCode $expected, int $id) use ($status, $historyCount): string {
    $issueCodes = array_map(static fn($issue): string => $issue->code, $result->validationIssues);

    return sprintf(
        'expected=%s actual=%s validation=[%s] capacity=%s database_status=%s audit_count=%d',
        $expected->value,
        $result->code->value,
        implode(',', $issueCodes),
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

$disabledId = $insertBooking(BookingPolicy::STATUS_OPTION, '2026-10-03');
$pdo->exec("INSERT INTO disabled_dates VALUES ('2026-10-03','manual','test')");
$assert($change($disabledId, BookingPolicy::STATUS_OPTION, BookingPolicy::STATUS_CONFIRMED)->code === BookingStatusChangeCode::DisabledDate, 'Disabled datum blokkeert bevestiging niet.');
$assert($change($disabledId, BookingPolicy::STATUS_OPTION, BookingPolicy::STATUS_REJECTED)->success, 'Disabled datum blokkeert afwijzen.');
$historical = $insertBooking(BookingPolicy::STATUS_REJECTED, '2026-07-17');
$assert($change($historical, BookingPolicy::STATUS_REJECTED, BookingPolicy::STATUS_CONFIRMED)->code === BookingStatusChangeCode::HistoricalDate, 'Historische datum blokkeert bevestiging niet.');
$assert($change($historical, BookingPolicy::STATUS_REJECTED, BookingPolicy::STATUS_OPTION)->success, 'Historische datum blokkeert optie.');
$legacy = $insertBooking(BookingPolicy::STATUS_OPTION, '2026-10-04', 40, true);
$assert($change($legacy, BookingPolicy::STATUS_OPTION, BookingPolicy::STATUS_CONFIRMED)->code === BookingStatusChangeCode::InvalidStoredBooking, 'Legacyafwijking blokkeert bevestiging niet.');
$assert($change($legacy, BookingPolicy::STATUS_OPTION, BookingPolicy::STATUS_REJECTED)->success, 'Legacyafwijking blokkeert afwijzen.');

$existing = $insertBooking(BookingPolicy::STATUS_CONFIRMED, '2026-10-05', $maxStudents - $minStudents);
$exact = $insertBooking(BookingPolicy::STATUS_OPTION, '2026-10-05', $minStudents);
$assert($change($exact, BookingPolicy::STATUS_OPTION, BookingPolicy::STATUS_CONFIRMED)->success, 'Exacte school- en leerlinglimiet is niet toegestaan.');
$overSchools = $insertBooking(BookingPolicy::STATUS_OPTION, '2026-10-05', $minStudents);
$overSchoolsResult = $change($overSchools, BookingPolicy::STATUS_OPTION, BookingPolicy::STATUS_CONFIRMED);
$assert($overSchoolsResult->code === BookingStatusChangeCode::SchoolLimitExceeded, 'Schoollimietoverschrijding ontbreekt: ' . $resultDiagnostic($overSchoolsResult, BookingStatusChangeCode::SchoolLimitExceeded, $overSchools));
$studentBase = $insertBooking(BookingPolicy::STATUS_CONFIRMED, '2026-10-06', $maxStudents - $minStudents + 1);
$studentOver = $insertBooking(BookingPolicy::STATUS_OPTION, '2026-10-06', $minStudents);
$studentOverResult = $change($studentOver, BookingPolicy::STATUS_OPTION, BookingPolicy::STATUS_CONFIRMED);
$assert($studentOverResult->code === BookingStatusChangeCode::StudentLimitExceeded, 'Leerlinglimietoverschrijding ontbreekt: ' . $resultDiagnostic($studentOverResult, BookingStatusChangeCode::StudentLimitExceeded, $studentOver));
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
$auditFailure = $insertBooking(BookingPolicy::STATUS_OPTION, '2026-10-09');
$result = $change($auditFailure, BookingPolicy::STATUS_OPTION, BookingPolicy::STATUS_REJECTED, 999999);
$assert($result->code === BookingStatusChangeCode::DatabaseError && $status($auditFailure) === BookingPolicy::STATUS_OPTION && $historyCount($auditFailure) === 0, 'Auditinsertfout rolt status niet terug.');

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
$first = $service($connectionA)->change(new BookingStatusChangeCommand($raceA, BookingPolicy::STATUS_OPTION, BookingPolicy::STATUS_CONFIRMED, $adminId), new DateTimeImmutable('2026-07-18'));
$second = $service($connectionB)->change(new BookingStatusChangeCommand($raceB, BookingPolicy::STATUS_OPTION, BookingPolicy::STATUS_CONFIRMED, $adminId), new DateTimeImmutable('2026-07-18'));
$assert($first->code === BookingStatusChangeCode::Success, 'Eerste racebevestiging faalt: ' . $resultDiagnostic($first, BookingStatusChangeCode::Success, $raceA));
$assert($second->code === BookingStatusChangeCode::StudentLimitExceeded, 'Tweede racebevestiging geeft niet de leerlinglimiet: ' . $resultDiagnostic($second, BookingStatusChangeCode::StudentLimitExceeded, $raceB));

if ($failures !== []) { foreach ($failures as $failure) fwrite(STDERR, "FAIL: {$failure}\n"); exit(1); }
fwrite(STDOUT, "OK: statusmutatie, audit, rollback, capaciteit en twee-connectie-serialisatie geslaagd.\n");

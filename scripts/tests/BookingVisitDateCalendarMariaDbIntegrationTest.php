<?php
declare(strict_types=1);

use GeoFort\Booking\Capacity\{BookingCapacityValidator,PolicyCapacityLimitProvider};
use GeoFort\Booking\Stored\StoredBookingAssembler;
use GeoFort\Booking\Validation\{BookingValidationCoordinator,StoredBookingVisitDateValidator};
use GeoFort\Services\Dashboard\Booking\DashboardBookingVisitDateCalendarService;
use GeoFort\Services\Http\Api\Admin\BookingVisitDateCalendarRequest;
use GeoFort\Services\Sql\{BookingCalendarSqlService,BookingDaySettingsSqlRepository,DisabledDatesSqlService,StoredBookingSqlRepository};

require dirname(__DIR__, 2) . '/vendor/autoload.php';

final class CalendarQueryCountingPdo extends PDO
{
    public int $preparedQueries = 0;

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        $this->preparedQueries++;
        return parent::prepare($query, $options);
    }
}

$env = [];
foreach (['HOST', 'PORT', 'NAME', 'USER'] as $key) {
    $value = getenv('STATUS_TEST_DB_' . $key);
    if ($value === false) {
        fwrite(STDERR, "FAIL: STATUS_TEST_DB_{$key} ontbreekt.\n");
        exit(2);
    }
    $env[$key] = (string) $value;
}
if (
    getenv('STATUS_TEST_DB_CONFIRM') !== 'YES_DISPOSABLE'
    || preg_match('/(test|tmp|scratch|disposable)/i', $env['NAME']) !== 1
    || strtolower((string) getenv('APP_ENV')) === 'production'
) {
    fwrite(STDERR, "FAIL: integratietest weigert niet-aantoonbaar wegwerpbare database.\n");
    exit(2);
}

$password = getenv('STATUS_TEST_DB_PASSWORD');
$pdo = new CalendarQueryCountingPdo(
    sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $env['HOST'], $env['PORT'], $env['NAME']),
    $env['USER'],
    $password === false ? '' : $password,
    [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ],
);
$actualDatabase = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
if ($actualDatabase !== $env['NAME']) {
    fwrite(STDERR, "FAIL: verbonden database wijkt af van bevestigde disposable database.\n");
    exit(2);
}

$tables = [
    'booking_rule_overrides',
    'booking_change_history',
    'booking_status_history',
    'booking_day_settings',
    'aanvraag_onderwijs_selecties',
    'disabled_dates',
    'aanvragen',
    'admin_users',
];
$cleanup = static function () use ($pdo, $tables): void {
    $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
    try {
        foreach ($tables as $table) $pdo->exec("DROP TABLE IF EXISTS {$table}");
    } finally {
        $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
    }
};
$cleanup();
$exitCode = 0;

try {
    $pdo->exec("CREATE TABLE aanvragen (
        id INT AUTO_INCREMENT PRIMARY KEY,
        status ENUM('In optie','Definitief','Afgewezen') NOT NULL,
        schoolnaam VARCHAR(255) NOT NULL, land VARCHAR(32) NOT NULL, adres VARCHAR(255) NOT NULL,
        postcode VARCHAR(16) NOT NULL, plaats VARCHAR(120) NOT NULL, school_telefoonnummer VARCHAR(25) NOT NULL,
        contactpersoon_telefoonnummer VARCHAR(25) NOT NULL, contactpersoon_voornaam VARCHAR(255) NOT NULL,
        contactpersoon_achternaam VARCHAR(255) NOT NULL, email VARCHAR(255) NOT NULL, bezoekdatum DATE NOT NULL,
        hoe_kent_u_geofort VARCHAR(120), opmerkingen TEXT, cjpPasGebruik VARCHAR(3) NOT NULL DEFAULT 'nee',
        cjpContactpersoonNaam VARCHAR(80), cjpPasnummer VARCHAR(9), onderwijs_sector VARCHAR(40) NOT NULL,
        programma VARCHAR(20) NOT NULL, keuzemodule_key VARCHAR(120), aantal_leerlingen INT,
        aantal_begeleiders INT, remise_break INT NOT NULL DEFAULT 0, kazerne_break INT NOT NULL DEFAULT 0,
        fortgracht_break INT NOT NULL DEFAULT 0, glas_limonade INT NOT NULL DEFAULT 0,
        waterijsje INT NOT NULL DEFAULT 0, remise_lunch INT NOT NULL DEFAULT 0,
        eigen_picknick TINYINT(1) NOT NULL DEFAULT 0, voorwaarden_akkoord TINYINT(1) NOT NULL DEFAULT 1,
        voorwaarden_akkoord_op DATETIME, source_system VARCHAR(80), source_record_id BIGINT,
        source_record_checksum CHAR(64), source_import_run_id BIGINT,
        KEY idx_date_status(bezoekdatum,status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE aanvraag_onderwijs_selecties (
        id INT AUTO_INCREMENT PRIMARY KEY, aanvraag_id INT NOT NULL, sector_key VARCHAR(40),
        level_key VARCHAR(80), group_key VARCHAR(80), level_position INT, group_position INT
    ) ENGINE=InnoDB");
    $pdo->exec("CREATE TABLE disabled_dates (
        datum DATE PRIMARY KEY, type VARCHAR(30) NOT NULL, reden VARCHAR(255)
    ) ENGINE=InnoDB");
    $pdo->exec("CREATE TABLE admin_users (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, email VARCHAR(190), name VARCHAR(120),
        role VARCHAR(50), password_hash VARCHAR(255), is_active TINYINT DEFAULT 1
    ) ENGINE=InnoDB");
    $sqlRoot = dirname(__DIR__, 2) . '/database/sql/';
    foreach ([
        '2026-07-17_create_booking_day_settings.sql',
        '2026-07-18_create_booking_status_history.sql',
        '2026-07-21_create_booking_rule_overrides.sql',
        '2026-07-22_create_booking_change_history.sql',
        '2026-07-22_link_overrides_to_change_history.sql',
    ] as $migration) {
        $pdo->exec((string) file_get_contents($sqlRoot . $migration));
    }

    $insert = $pdo->prepare("INSERT INTO aanvragen (
        status,schoolnaam,land,adres,postcode,plaats,school_telefoonnummer,
        contactpersoon_telefoonnummer,contactpersoon_voornaam,contactpersoon_achternaam,email,
        bezoekdatum,cjpPasGebruik,onderwijs_sector,programma,keuzemodule_key,aantal_leerlingen,aantal_begeleiders
    ) VALUES (
        :status,'School','Nederland','Dijk 1','1234 AB','Plaats','1','2','Jan','Jansen',
        'jan@example.test',:date,'nee','primairOnderwijs',:program,'Earth-Watch',:students,4
    )");
    $make = static function (string $status, string $date, int $students, string $program = 'dag') use ($insert, $pdo): int {
        $insert->execute([':status' => $status, ':date' => $date, ':program' => $program, ':students' => $students]);
        return (int) $pdo->lastInsertId();
    };

    $currentConfirmed = $make('Definitief', '2027-02-02', 50);
    $otherDayProgram = $make('Definitief', '2027-02-02', 40);
    $otherMorningProgram = $make('Definitief', '2027-02-02', 30, 'ochtend');
    $make('In optie', '2027-02-02', 70);
    $make('Afgewezen', '2027-02-02', 80);
    $make('Definitief', '2027-02-03', 99);
    $draftOption = $make('In optie', '2027-02-01', 35);
    $draftRejected = $make('Afgewezen', '2027-02-01', 35);
    $pdo->exec("INSERT INTO disabled_dates(datum,type,reden) VALUES('2027-02-04','manual','Handmatig gesloten')");

    $disabledDates = new DisabledDatesSqlService($pdo);
    $service = new DashboardBookingVisitDateCalendarService(
        new StoredBookingSqlRepository($pdo, new StoredBookingAssembler()),
        new BookingCalendarSqlService($pdo),
        new BookingDaySettingsSqlRepository($pdo),
        $disabledDates,
        new StoredBookingVisitDateValidator($disabledDates),
        new BookingValidationCoordinator(),
        new PolicyCapacityLimitProvider(),
        new BookingCapacityValidator(),
    );
    $assert = static function (bool $condition, string $message): void {
        if (!$condition) throw new RuntimeException($message);
    };
    $day = static function (array $calendar, string $date): array {
        foreach ($calendar['days'] as $calendarDay) if ($calendarDay['date'] === $date) return $calendarDay;
        throw new RuntimeException("Kalenderdag {$date} ontbreekt.");
    };
    $codes = static fn(array $calendarDay): array => array_column($calendarDay['reasons'], 'code');
    $counts = static fn(): array => [
        'aanvragen' => (int) $pdo->query('SELECT COUNT(*) FROM aanvragen')->fetchColumn(),
        'booking_change_history' => (int) $pdo->query('SELECT COUNT(*) FROM booking_change_history')->fetchColumn(),
        'booking_status_history' => (int) $pdo->query('SELECT COUNT(*) FROM booking_status_history')->fetchColumn(),
        'booking_rule_overrides' => (int) $pdo->query('SELECT COUNT(*) FROM booking_rule_overrides')->fetchColumn(),
    ];

    $assert($service->get(999999, '2027-02-01', '2027-02-07', new DateTimeImmutable('2027-01-01')) === null, 'Booking-not-found geeft geen null.');
    $before = $counts();
    $pdo->preparedQueries = 0;
    $calendar = $service->get($currentConfirmed, '2027-02-01', '2027-02-07', new DateTimeImmutable('2027-01-01'));
    $assert($calendar !== null && count($calendar['days']) === 7, 'Kalenderbereik wordt niet volledig opgebouwd.');
    $assert($pdo->preparedQueries === 5, "Kalenderrange gebruikt {$pdo->preparedQueries} queries in plaats van de vaste vijf.");

    $capacityDay = $day($calendar, '2027-02-02');
    $assert($capacityDay['capacity']['confirmedSchools'] === 2, 'Alleen Definitieve scholen/current exclusion levert niet exact twee scholen.');
    $assert($capacityDay['capacity']['confirmedStudents'] === 70, 'Definitief leerlingtotaal is niet 70.');
    $assert($capacityDay['capacity']['confirmedProgramStudents'] === 40, 'Dagprogramma-totaal is niet 40.');
    $assert($capacityDay['state'] === 'override_required' && $capacityDay['selectable'] === true, 'Definitieve capaciteitsdag is niet overridable/selecteerbaar.');
    $assert(in_array('SCHOOL_LIMIT_EXCEEDED', $codes($capacityDay), true), 'Schoollimietissue ontbreekt.');
    $assert($otherDayProgram !== $otherMorningProgram, 'Fixtureboekingen zijn niet uniek.');

    $otherDate = $day($calendar, '2027-02-03');
    $assert($otherDate['capacity']['confirmedSchools'] === 1 && $otherDate['capacity']['confirmedStudents'] === 99, 'Andere datum lekt in of uit dagtotalen.');
    $disabled = $day($calendar, '2027-02-04');
    $assert($disabled['state'] === 'override_required' && $disabled['selectable'] === true && in_array('DISABLED_VISIT_DATE', $codes($disabled), true), 'Disabled datum is niet override-required.');
    $weekend = $day($calendar, '2027-02-06');
    $assert($weekend['state'] === 'blocked' && $weekend['selectable'] === false && in_array('CURRENT_CONFIGURATION_MISMATCH', $codes($weekend), true), 'Programma/weekdag-incompatibiliteit is niet hard geblokkeerd.');
    $available = $day($calendar, '2027-02-05');
    $assert($available['state'] === 'available' && $available['selectable'] === true, 'Vrije werkdag is niet available.');

    foreach ([$draftOption, $draftRejected] as $draftId) {
        $draftCalendar = $service->get($draftId, '2027-02-01', '2027-02-07', new DateTimeImmutable('2027-02-05'));
        $assert($draftCalendar !== null, 'Draftkalender ontbreekt.');
        $draftDisabled = $day($draftCalendar, '2027-02-04');
        $assert($draftDisabled['state'] === 'warning' && $draftDisabled['selectable'] === true, 'Draft disabled/verleden-datum behoudt selecteerbaarheid niet.');
        $assert($day($draftCalendar, '2027-02-06')['selectable'] === false, 'Draft omzeilt centrale programma/weekdagregel.');
    }
    $assert($counts() === $before, 'Kalenderread schrijft naar aanvragen of audit-/overridehistorie.');

    $assert(BookingVisitDateCalendarRequest::fromQuery(['bookingId' => '1', 'startDate' => '2027-02-01', 'endDate' => '2027-03-14']) !== null, '42-daagse range wordt geweigerd.');
    $assert(BookingVisitDateCalendarRequest::fromQuery(['bookingId' => '1', 'startDate' => '2027-02-01', 'endDate' => '2027-03-15']) === null, '43-daagse range wordt niet geweigerd.');

    fwrite(STDOUT, "OK: booking visit-date kalender MariaDB-integratie geslaagd.\n");
} catch (Throwable $exception) {
    fwrite(STDERR, "FAIL: {$exception->getMessage()}\n");
    $exitCode = 1;
} finally {
    $cleanup();
}
exit($exitCode);

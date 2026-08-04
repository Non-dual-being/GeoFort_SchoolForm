<?php
declare(strict_types=1);

use GeoFort\Dashboard\Calendar\CalendarDateManagementCommand;
use GeoFort\Services\Dashboard\Calendar\CalendarDateManagementPreviewService;
use GeoFort\Services\Dashboard\Calendar\CalendarDateManagementService;
use GeoFort\Services\Sql\BookingDaySettingsSqlRepository;
use GeoFort\Services\Sql\CalendarDateManagementSqlRepository;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

const EXPECTED_DATABASE_PREFIX = 'geofort_calendar_management_disposable_test_';

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
    !str_starts_with($env['NAME'], EXPECTED_DATABASE_PREFIX)
    || preg_match('/^geofort_calendar_management_disposable_test_[a-f0-9]{8}$/', $env['NAME']) !== 1
    || getenv('STATUS_TEST_DB_CONFIRM') !== 'YES_DISPOSABLE'
    || preg_match('/(test|tmp|scratch|disposable)/i', $env['NAME']) !== 1
    || strtolower((string) getenv('APP_ENV')) === 'production'
) {
    fwrite(STDERR, "FAIL: integratietest weigert een andere of niet-aantoonbaar disposable database.\n");
    exit(2);
}

$password = getenv('STATUS_TEST_DB_PASSWORD');
$pdo = new PDO(
    sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $env['HOST'], $env['PORT'], $env['NAME']),
    $env['USER'],
    $password === false ? '' : $password,
    [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ],
);
$guard = static function () use ($pdo, $env): void {
    $actual = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
    if ($actual !== $env['NAME'] || !str_starts_with($actual, EXPECTED_DATABASE_PREFIX)) {
        throw new RuntimeException('Databaseguard: verbonden database wijkt af van de expliciete kalenderbeheer-testdatabase.');
    }
};
$guard();

/** @param array<string, scalar|null> $params */
$mutate = static function (string $sql, array $params = []) use ($pdo, $guard): PDOStatement {
    $guard();
    $statement = $pdo->prepare($sql);
    $statement->execute($params);
    return $statement;
};
$scalar = static function (string $sql, array $params = []) use ($pdo): mixed {
    $statement = $pdo->prepare($sql);
    $statement->execute($params);
    return $statement->fetchColumn();
};
$rows = static function (string $sql, array $params = []) use ($pdo): array {
    $statement = $pdo->prepare($sql);
    $statement->execute($params);
    return $statement->fetchAll();
};
$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};

$runId = bin2hex(random_bytes(8));
$base = null;
for ($attempt = 0; $attempt < 50; $attempt++) {
    $candidate = (new DateTimeImmutable('2035-01-01'))
        ->modify('+' . random_int(0, 7000) . ' days')
        ->modify('monday this week');
    $candidateEnd = $candidate->modify('+114 days');
    $start = $candidate->format('Y-m-d');
    $end = $candidateEnd->format('Y-m-d');
    $params = [
        ':bookingStart' => $start, ':bookingEnd' => $end,
        ':disabledStart' => $start, ':disabledEnd' => $end,
        ':settingsStart' => $start, ':settingsEnd' => $end,
    ];
    $occupied = (int) $scalar(
        'SELECT
            (SELECT COUNT(*) FROM aanvragen WHERE bezoekdatum BETWEEN :bookingStart AND :bookingEnd)
          + (SELECT COUNT(*) FROM disabled_dates WHERE datum BETWEEN :disabledStart AND :disabledEnd)
          + (SELECT COUNT(*) FROM booking_day_settings WHERE visit_date BETWEEN :settingsStart AND :settingsEnd)',
        $params,
    );
    if ($occupied === 0) {
        $base = $candidate;
        break;
    }
}
if (!$base instanceof DateTimeImmutable) {
    fwrite(STDERR, "FAIL: geen ongebruikte unieke toekomstige datumrange gevonden.\n");
    exit(2);
}
$date = static fn (int $days): string => $base->modify("+{$days} days")->format('Y-m-d');
$today = new DateTimeImmutable('2030-01-01');
$adminId = null;
$bookingIds = [];
$ownedDates = [];
$exitCode = 0;

$repository = new CalendarDateManagementSqlRepository($pdo);
$previews = new CalendarDateManagementPreviewService($repository);
$service = new CalendarDateManagementService($pdo, new BookingDaySettingsSqlRepository($pdo), $repository, $previews);
$preview = static function (string $action, string $start, string $end, ?string $type = null) use ($previews, $today) {
    $type ??= str_starts_with($action, 'block_') ? 'manual' : null;
    $result = $previews->preview($action, $start, $end, $type, $today);
    if (!$result->success || $result->preview === null) {
        throw new RuntimeException("Preview {$action} {$start}..{$end} mislukt met {$result->code}.");
    }
    return $result->preview;
};
$change = static function (
    string $action,
    string $start,
    string $end,
    ?string $reason,
    bool $confirmed,
    bool $bookingsAccepted,
    ?string $fingerprint,
    ?string $activeFingerprint,
    int $actingAdminId,
    string $blockType = 'manual',
) use ($service, $today, $guard) {
    $guard();
    return $service->change(new CalendarDateManagementCommand(
        $start,
        $end,
        $action,
        str_starts_with($action, 'block_') ? $blockType : null,
        $reason,
        $confirmed,
        $bookingsAccepted,
        $fingerprint,
        $activeFingerprint,
        $actingAdminId,
    ), $today);
};

try {
    foreach ([
        'aanvragen', 'aanvraag_onderwijs_selecties', 'admin_users', 'booking_status_history',
        'booking_change_history', 'disabled_dates', 'booking_day_settings',
        'calendar_date_change_history', 'calendar_date_change_history_dates',
        'generated_disabled_date_release_overrides',
    ] as $table) {
        $assert((int) $scalar(
            'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table',
            [':table' => $table],
        ) === 1, "Vereiste tabel {$table} ontbreekt.");
    }

    $mutate(
        'INSERT INTO admin_users (email, name, role, password_hash, is_active)
         VALUES (:email, :name, :role, :password, 1)',
        [
            ':email' => "calendar-management-{$runId}@example.test",
            ':name' => "Calendar management {$runId}",
            ':role' => 'admin',
            ':password' => 'integration-test-only',
        ],
    );
    $adminId = (int) $pdo->lastInsertId();

    $makeBooking = static function (string $visitDate, string $status = 'In optie', int $students = 37) use (
        $mutate,
        $pdo,
        $runId,
        &$bookingIds,
        &$ownedDates,
    ): int {
        $ownedDates[$visitDate] = true;
        $mutate(
            'INSERT INTO aanvragen (
                status, schoolnaam, land, adres, postcode, plaats, school_telefoonnummer,
                contactpersoon_telefoonnummer, contactpersoon_voornaam, contactpersoon_achternaam,
                email, bezoekdatum, hoe_kent_u_geofort, opmerkingen, cjpPasGebruik,
                cjpContactpersoonNaam, cjpPasnummer, onderwijs_sector, programma, keuzemodule_key,
                aantal_leerlingen, aantal_begeleiders, remise_break, kazerne_break, fortgracht_break,
                glas_limonade, waterijsje, remise_lunch, eigen_picknick, voorwaarden_akkoord,
                voorwaarden_akkoord_op, source_system
             ) VALUES (
                :status, :school, "Nederland", "Testlaan 1", "1234 AB", "Teststad", "0100000000",
                "0600000000", "Test", "Boeking", :email, :visitDate, "Integratietest",
                :comments, "ja", "Test CJP", "123456789", "primairOnderwijs", "dag", "Earth-Watch",
                :students, 5, 2, 3, 4, 5, 6, 7, 1, 1, NOW(), :sourceSystem
             )',
            [
                ':status' => $status,
                ':school' => "Kalendertest {$runId}",
                ':email' => "booking-{$runId}-" . count($bookingIds) . '@example.test',
                ':visitDate' => $visitDate,
                ':comments' => "Eigen fixture {$runId}",
                ':students' => $students,
                ':sourceSystem' => "calendar-management-test-{$runId}",
            ],
        );
        $id = (int) $pdo->lastInsertId();
        $bookingIds[] = $id;
        $mutate(
            'INSERT INTO aanvraag_onderwijs_selecties (
                aanvraag_id, sector_key, sector_label, level_key, level_label,
                level_position, group_key, group_label, group_position
             ) VALUES (
                :bookingId, "primairOnderwijs", "Primair onderwijs", "regulier",
                "Regulier basisonderwijs", 1, "groep7", "Groep 7", 1
             )',
            [':bookingId' => $id],
        );
        return $id;
    };
    $insertDisabled = static function (string $visitDate, string $type, string $reason, ?string $source = null) use ($mutate, &$ownedDates): void {
        $ownedDates[$visitDate] = true;
        $source ??= $type === 'manual' ? 'planner' : 'generated';
        $mutate(
            'INSERT INTO disabled_dates (datum, type, reden, source) VALUES (:date, :type, :reason, :source)',
            [':date' => $visitDate, ':type' => $type, ':reason' => $reason, ':source' => $source],
        );
    };
    $auditRows = static function (string $start, string $end) use ($rows, $adminId): array {
        return $rows(
            'SELECT * FROM calendar_date_change_history
             WHERE changed_by_admin_id = :adminId AND start_date = :start AND end_date = :end ORDER BY id',
            [':adminId' => $adminId, ':start' => $start, ':end' => $end],
        );
    };
    $children = static function (int $historyId) use ($rows): array {
        return $rows(
            'SELECT * FROM calendar_date_change_history_dates WHERE history_id = :id ORDER BY calendar_date',
            [':id' => $historyId],
        );
    };
    $snapshot = static function (int $bookingId) use ($rows): array {
        $booking = $rows(
            'SELECT id, status, bezoekdatum, programma, aantal_leerlingen, onderwijs_sector,
                    keuzemodule_key, aantal_begeleiders, schoolnaam, land, adres, postcode, plaats,
                    school_telefoonnummer, contactpersoon_telefoonnummer, contactpersoon_voornaam,
                    contactpersoon_achternaam, email, remise_break, kazerne_break, fortgracht_break,
                    glas_limonade, waterijsje, remise_lunch, eigen_picknick, cjpPasGebruik,
                    cjpContactpersoonNaam, cjpPasnummer
             FROM aanvragen WHERE id = :id',
            [':id' => $bookingId],
        );
        $selection = $rows(
            'SELECT sector_key, sector_label, level_key, level_label, level_position,
                    group_key, group_label, group_position
             FROM aanvraag_onderwijs_selecties WHERE aanvraag_id = :id ORDER BY id',
            [':id' => $bookingId],
        );
        return ['booking' => $booking[0] ?? null, 'educationSelection' => $selection];
    };

    // Single block zonder actieve boekingen.
    $single = $date(0);
    $ownedDates[$single] = true;
    $reason = "Onderhoud {$runId}";
    $singlePreview = $preview('block_single', $single, $single);
    $result = $change('block_single', $single, $single, $reason, true, false, $singlePreview->fingerprint, null, $adminId);
    $assert($result->success && $result->code === 'SUCCESS' && $result->affectedCount === 1, 'Single block zonder boeking faalt.');
    $stored = $rows('SELECT type, reden FROM disabled_dates WHERE datum = :date', [':date' => $single]);
    $assert(($stored[0]['type'] ?? null) === 'manual' && ($stored[0]['reden'] ?? null) === $reason, 'Single block bewaart type of reden niet.');
    $audit = $auditRows($single, $single);
    $assert(count($audit) === 1 && $audit[0]['action'] === 'calendar_date_blocked' && $audit[0]['scope'] === 'single', 'Single-blockauditheader klopt niet.');
    $assert((int) $audit[0]['affected_count'] === 1 && $audit[0]['reason'] === $reason, 'Single-blockauditcount of reden klopt niet.');
    $child = $children((int) $audit[0]['id']);
    $assert(count($child) === 1 && (int) $child[0]['manually_blocked_before'] === 0 && (int) $child[0]['manually_blocked_after'] === 1, 'Single-blockauditchild klopt niet.');
    $assert($child[0]['type_before'] === null && $child[0]['type_after'] === 'manual', 'Single-blockaudit bevat niet het juiste type voor en na.');

    // Een period-flow van één dag blijft period en ondersteunt planner-vakanties.
    $oneDayPeriod = $date(7);
    $ownedDates[$oneDayPeriod] = true;
    $vacationReason = "Meivakantie {$runId}";
    $oneDayBlockPreview = $preview('block_period', $oneDayPeriod, $oneDayPeriod, 'school_vacation');
    $oneDayBlock = $change('block_period', $oneDayPeriod, $oneDayPeriod, $vacationReason, true, false, $oneDayBlockPreview->fingerprint, null, $adminId, 'school_vacation');
    $assert($oneDayBlock->success && $oneDayBlock->affectedCount === 1, 'block_period van één dag faalt.');
    $assert((string) $scalar('SELECT CONCAT(type, ":", source) FROM disabled_dates WHERE datum = :date', [':date' => $oneDayPeriod]) === 'school_vacation:planner', 'Planner-vakantie wordt niet met provenance opgeslagen.');
    $oneDayAudit = $auditRows($oneDayPeriod, $oneDayPeriod);
    $assert(count($oneDayAudit) === 1 && $oneDayAudit[0]['scope'] === 'period' && (int) $oneDayAudit[0]['affected_count'] === 1, 'Eéndags-period blockaudit klopt niet.');
    $oneDayBlockChild = $children((int) $oneDayAudit[0]['id']);
    $assert(($oneDayBlockChild[0]['type_after'] ?? null) === 'school_vacation'
        && (int) $oneDayBlockChild[0]['manually_blocked_after'] === 0, 'Vakantieaudit bevat type_after niet of markeert vakantie als manual.');

    $oneDayReleasePreview = $preview('release_period', $oneDayPeriod, $oneDayPeriod);
    $oneDayRelease = $change('release_period', $oneDayPeriod, $oneDayPeriod, null, true, false, $oneDayReleasePreview->fingerprint, null, $adminId);
    $assert($oneDayRelease->success && $oneDayRelease->affectedCount === 1, 'release_period van één dag faalt.');
    $assert((int) $scalar('SELECT COUNT(*) FROM disabled_dates WHERE datum = :date', [':date' => $oneDayPeriod]) === 0, 'Planner-vakantie wordt niet vrijgegeven.');
    $oneDayAudit = $auditRows($oneDayPeriod, $oneDayPeriod);
    $assert(count($oneDayAudit) === 2 && $oneDayAudit[1]['scope'] === 'period' && (int) $oneDayAudit[1]['affected_count'] === 1, 'Eéndags-period releaseaudit klopt niet.');
    $oneDayReleaseChild = $children((int) $oneDayAudit[1]['id']);
    $assert(($oneDayReleaseChild[0]['type_before'] ?? null) === 'school_vacation'
        && $oneDayReleaseChild[0]['type_after'] === null
        && (int) $oneDayReleaseChild[0]['manually_blocked_before'] === 0, 'Vakantiereleaseaudit bevat onjuiste typen of markeert vakantie als manual.');

    // Single block met actieve boeking, bevestiging en bookingintegriteit.
    $bookingDate = $date(14);
    $bookingId = $makeBooking($bookingDate);
    $bookingBefore = $snapshot($bookingId);
    $statusHistoryBefore = (int) $scalar('SELECT COUNT(*) FROM booking_status_history WHERE booking_id = :id', [':id' => $bookingId]);
    $changeHistoryBefore = (int) $scalar('SELECT COUNT(*) FROM booking_change_history WHERE booking_id = :id', [':id' => $bookingId]);
    $bookingPreview = $preview('block_single', $bookingDate, $bookingDate);
    $assert($bookingPreview->bookingCount === 1 && count($bookingPreview->categories['activeBookings']) === 1, 'Preview bevat actieve booking niet.');
    $assert(strlen($bookingPreview->activeBookingsFingerprint) === 64, 'activeBookingsFingerprint ontbreekt.');
    $notConfirmed = $change('block_single', $bookingDate, $bookingDate, $reason, false, true, $bookingPreview->fingerprint, $bookingPreview->activeBookingsFingerprint, $adminId);
    $assert(!$notConfirmed->success && $notConfirmed->code === 'CONFIRMATION_REQUIRED', 'Single block muteert zonder algemene bevestiging.');
    $notAccepted = $change('block_single', $bookingDate, $bookingDate, $reason, true, false, $bookingPreview->fingerprint, $bookingPreview->activeBookingsFingerprint, $adminId);
    $assert(!$notAccepted->success && $notAccepted->code === 'EXISTING_BOOKINGS_CONFIRMATION_REQUIRED', 'Single block muteert zonder bookingbevestiging.');
    $assert((int) $scalar('SELECT COUNT(*) FROM disabled_dates WHERE datum = :date', [':date' => $bookingDate]) === 0, 'Onbevestigde single block schrijft disabled date.');
    $accepted = $change('block_single', $bookingDate, $bookingDate, $reason, true, true, $bookingPreview->fingerprint, $bookingPreview->activeBookingsFingerprint, $adminId);
    $assert($accepted->success && $accepted->affectedCount === 1, 'Bevestigde single block met booking faalt.');
    $assert($snapshot($bookingId) === $bookingBefore, 'Kalenderbeheer wijzigt bookingvelden of onderwijsselectie.');
    $assert((int) $scalar('SELECT COUNT(*) FROM booking_status_history WHERE booking_id = :id', [':id' => $bookingId]) === $statusHistoryBefore, 'Kalenderbeheer schrijft status-history/mailvelden.');
    $assert((int) $scalar('SELECT COUNT(*) FROM booking_change_history WHERE booking_id = :id', [':id' => $bookingId]) === $changeHistoryBefore, 'Kalenderbeheer schrijft booking-change-history.');

    // Veranderde actieve bookingset.
    $conflictDate = $date(28);
    $conflictBooking = $makeBooking($conflictDate, 'In optie', 41);
    $oldPreview = $preview('block_single', $conflictDate, $conflictDate);
    $mutate('UPDATE aanvragen SET aantal_leerlingen = 42 WHERE id = :id', [':id' => $conflictBooking]);
    $conflict = $change('block_single', $conflictDate, $conflictDate, $reason, true, true, $oldPreview->fingerprint, $oldPreview->activeBookingsFingerprint, $adminId);
    $assert(!$conflict->success && $conflict->code === 'EXISTING_BOOKINGS_CONFIRMATION_REQUIRED', 'Oude actieve-bookingsfingerprint wordt niet geweigerd.');
    $assert($conflict->preview !== null && $conflict->preview->activeBookingsFingerprint !== $oldPreview->activeBookingsFingerprint, 'Conflict bevat geen verse preview.');
    $assert((int) $scalar('SELECT COUNT(*) FROM disabled_dates WHERE datum = :date', [':date' => $conflictDate]) === 0, 'Bookingconflict schrijft disabled date.');
    $assert($auditRows($conflictDate, $conflictDate) === [], 'Bookingconflict schrijft audit.');

    // Period block met weekend, bestaande manual en schoolvakantie.
    $periodStart = $date(42);
    $periodEnd = $date(48);
    $manualDate = $date(43);
    $vacationDate = $date(45);
    $weekendDates = [$date(47), $date(48)];
    foreach (range(42, 48) as $offset) $ownedDates[$date($offset)] = true;
    $insertDisabled($manualDate, 'manual', "Bestaand manual {$runId}");
    $insertDisabled($vacationDate, 'school_vacation', "Vakantie {$runId}");
    $periodPreview = $preview('block_period', $periodStart, $periodEnd);
    $expectedAffected = [$date(42), $date(44), $date(46)];
    $assert($periodPreview->categories['affectedDates'] === $expectedAffected, 'Periodpreview bepaalt affectedDates onjuist.');
    $periodResult = $change('block_period', $periodStart, $periodEnd, $reason, true, false, $periodPreview->fingerprint, null, $adminId);
    $assert($periodResult->success && $periodResult->affectedCount === count($expectedAffected), 'Period block faalt of count klopt niet.');
    foreach ($weekendDates as $weekendDate) {
        $assert((int) $scalar('SELECT COUNT(*) FROM disabled_dates WHERE datum = :date', [':date' => $weekendDate]) === 0, 'Weekend krijgt een manual-record.');
    }
    $assert((string) $scalar('SELECT reden FROM disabled_dates WHERE datum = :date AND type = "manual"', [':date' => $manualDate]) === "Bestaand manual {$runId}", 'Bestaande manual wordt overschreven.');
    $assert((string) $scalar('SELECT type FROM disabled_dates WHERE datum = :date', [':date' => $vacationDate]) === 'school_vacation', 'Schoolvakantie wordt overschreven.');
    $audit = $auditRows($periodStart, $periodEnd);
    $assert(count($audit) === 1 && $audit[0]['scope'] === 'period' && (int) $audit[0]['affected_count'] === 3, 'Period-blockauditheader klopt niet.');
    $assert(count($children((int) $audit[0]['id'])) === 3, 'Period block schrijft niet één child per gewijzigde datum.');

    // Period block met actieve booking: alles of niets.
    $blockedPeriodStart = $date(56);
    $blockedPeriodEnd = $date(60);
    foreach (range(56, 60) as $offset) $ownedDates[$date($offset)] = true;
    $makeBooking($date(58), 'Definitief', 33);
    $blockedPreview = $preview('block_period', $blockedPeriodStart, $blockedPeriodEnd);
    $blockedResult = $change('block_period', $blockedPeriodStart, $blockedPeriodEnd, $reason, true, false, $blockedPreview->fingerprint, null, $adminId);
    $assert(!$blockedResult->success && $blockedResult->code === 'ACTIVE_BOOKINGS_IN_PERIOD', 'Period block met actieve booking wordt niet geweigerd.');
    $assert((int) $scalar('SELECT COUNT(*) FROM disabled_dates WHERE datum BETWEEN :start AND :end', [':start' => $blockedPeriodStart, ':end' => $blockedPeriodEnd]) === 0, 'Geweigerde period block schrijft gedeeltelijke records.');
    $assert($auditRows($blockedPeriodStart, $blockedPeriodEnd) === [], 'Geweigerde period block schrijft auditheader.');

    // Single release verwijdert alleen manual en auditeert before/after.
    $releaseDate = $date(70);
    $insertDisabled($releaseDate, 'manual', "Vrijgeven {$runId}");
    $releasePreview = $preview('release_single', $releaseDate, $releaseDate);
    $releaseResult = $change('release_single', $releaseDate, $releaseDate, null, true, false, $releasePreview->fingerprint, null, $adminId);
    $assert($releaseResult->success && $releaseResult->affectedCount === 1, 'Single release faalt.');
    $assert((int) $scalar('SELECT COUNT(*) FROM disabled_dates WHERE datum = :date', [':date' => $releaseDate]) === 0, 'Single release verwijdert manual niet.');
    $audit = $auditRows($releaseDate, $releaseDate);
    $child = count($audit) === 1 ? $children((int) $audit[0]['id']) : [];
    $assert(count($audit) === 1 && $audit[0]['action'] === 'calendar_date_released' && $audit[0]['scope'] === 'single', 'Single-releaseauditheader klopt niet.');
    $assert(count($child) === 1 && (int) $child[0]['manually_blocked_before'] === 1 && (int) $child[0]['manually_blocked_after'] === 0, 'Single-releaseauditchild klopt niet.');

    // Period release met manual, gegenereerde schoolvakantie, weekend, lege datum en actieve booking.
    $releaseStart = $date(84);
    $releaseEnd = $date(90);
    foreach (range(84, 90) as $offset) $ownedDates[$date($offset)] = true;
    $releaseManualDates = [$date(84), $date(86)];
    foreach ($releaseManualDates as $manual) $insertDisabled($manual, 'manual', "Periode vrijgeven {$runId}");
    $releaseVacation = $date(87);
    $insertDisabled($releaseVacation, 'school_vacation', "Vakantie behouden {$runId}");
    $releaseBooking = $makeBooking($date(86), 'In optie', 29);
    $releaseBookingBefore = $snapshot($releaseBooking);
    $releasePeriodPreview = $preview('release_period', $releaseStart, $releaseEnd);
    $assert($releasePeriodPreview->categories['affectedTypeCounts'] === ['manual' => 2, 'school_vacation' => 1], 'Gemengde releasepreview telt typen onjuist.');
    $releasePeriodResult = $change('release_period', $releaseStart, $releaseEnd, null, true, false, $releasePeriodPreview->fingerprint, null, $adminId);
    $assert($releasePeriodResult->success && $releasePeriodResult->affectedCount === 3, 'Gemengde period release met actieve booking faalt.');
    foreach ($releaseManualDates as $manual) {
        $assert((int) $scalar('SELECT COUNT(*) FROM disabled_dates WHERE datum = :date', [':date' => $manual]) === 0, 'Period release laat manual staan.');
    }
    $assert((int) $scalar('SELECT COUNT(*) FROM disabled_dates WHERE datum = :date', [':date' => $releaseVacation]) === 0, 'Period release verwijdert gegenereerde schoolvakantie niet.');
    $assert((int) $scalar('SELECT COUNT(*) FROM generated_disabled_date_release_overrides WHERE datum = :date AND type = "school_vacation"', [':date' => $releaseVacation]) === 1, 'Vakantievrijgave bewaart geen persistente override.');
    $assert($snapshot($releaseBooking) === $releaseBookingBefore, 'Period release wijzigt actieve booking.');
    $audit = $auditRows($releaseStart, $releaseEnd);
    $assert(count($audit) === 1 && $audit[0]['scope'] === 'period' && (int) $audit[0]['affected_count'] === 3, 'Period-releaseauditheader klopt niet.');
    $assert(count($children((int) $audit[0]['id'])) === 3, 'Period release childcount klopt niet.');

    $secondReleasePreview = $preview('release_period', $releaseStart, $releaseEnd);
    $secondRelease = $change('release_period', $releaseStart, $releaseEnd, null, true, false, $secondReleasePreview->fingerprint, null, $adminId);
    $assert($secondRelease->success && $secondRelease->code === 'NO_CHANGE', 'Tweede gemengde vrijgave is niet idempotent.');

    // Een verdwenen verwachte vakantierecord veroorzaakt een snapshotconflict.
    $staleVacation = $date(91);
    $insertDisabled($staleVacation, 'school_vacation', "Verdwenen vakantie {$runId}");
    $stalePreview = $preview('release_single', $staleVacation, $staleVacation);
    $mutate('DELETE FROM disabled_dates WHERE datum = :date', [':date' => $staleVacation]);
    $staleResult = $change('release_single', $staleVacation, $staleVacation, null, true, false, $stalePreview->fingerprint, null, $adminId);
    $assert(!$staleResult->success && $staleResult->code === 'CALENDAR_DATE_CONFLICT', 'Verdwenen verwacht vakantierecord veroorzaakt geen conflict.');

    // Verleden, weekend zonder record en een niet-vrijgeefbaar weekendrecord blijven beschermd.
    $pastResult = $previews->preview('release_single', '2029-12-31', '2029-12-31', null, $today);
    $assert(!$pastResult->success && $pastResult->code === 'CALENDAR_DATE_IN_PAST', 'Schoolvakantie in het verleden wordt niet server-side geweigerd.');
    $weekendPreview = $preview('release_single', $date(97), $date(97));
    $assert($weekendPreview->categories['affectedDates'] === [], 'Weekend zonder disabled-date-record is vrijgeefbaar.');
    $otherTypeDate = $date(99);
    $insertDisabled($otherTypeDate, 'weekend', 'Niet-vrijgeefbaar type', 'generated');
    $otherPreview = $preview('release_single', $otherTypeDate, $otherTypeDate);
    $assert($otherPreview->categories['affectedDates'] === [], 'Een ander disabled-date-type wordt vrijgegeven.');

    // No-op: identieke block, release zonder manual en periode zonder wijzigingen.
    $noopBlockPreview = $preview('block_single', $single, $single);
    $auditCountBefore = count($auditRows($single, $single));
    $noopBlock = $change('block_single', $single, $single, $reason, true, false, $noopBlockPreview->fingerprint, null, $adminId);
    $assert($noopBlock->success && $noopBlock->code === 'NO_CHANGE', 'Identieke block geeft geen NO_CHANGE.');
    $assert(count($auditRows($single, $single)) === $auditCountBefore, 'Identieke block schrijft audit.');
    $noopDate = $date(98);
    $ownedDates[$noopDate] = true;
    $noopReleasePreview = $preview('release_single', $noopDate, $noopDate);
    $noopRelease = $change('release_single', $noopDate, $noopDate, null, true, false, $noopReleasePreview->fingerprint, null, $adminId);
    $assert($noopRelease->success && $noopRelease->code === 'NO_CHANGE' && $auditRows($noopDate, $noopDate) === [], 'Lege release is geen auditvrije NO_CHANGE.');
    $noopPeriodStart = $date(105);
    $noopPeriodEnd = $date(109);
    foreach (range(105, 109) as $offset) $ownedDates[$date($offset)] = true;
    $noopPeriodPreview = $preview('release_period', $noopPeriodStart, $noopPeriodEnd);
    $noopPeriod = $change('release_period', $noopPeriodStart, $noopPeriodEnd, null, true, false, $noopPeriodPreview->fingerprint, null, $adminId);
    $assert($noopPeriod->success && $noopPeriod->code === 'NO_CHANGE' && $auditRows($noopPeriodStart, $noopPeriodEnd) === [], 'Ongewijzigde periode schrijft data of audit.');

    // Auditfout moet disabled-datewrites en audit volledig terugrollen.
    $rollbackStart = $date(112);
    $rollbackEnd = $date(114);
    foreach (range(112, 114) as $offset) $ownedDates[$date($offset)] = true;
    $rollbackPreview = $preview('block_period', $rollbackStart, $rollbackEnd);
    $rollbackResult = $change('block_period', $rollbackStart, $rollbackEnd, $reason, true, false, $rollbackPreview->fingerprint, null, 4294967295);
    $assert(!$rollbackResult->success && $rollbackResult->code === 'DATABASE_ERROR', 'Gecontroleerde auditfout geeft geen DATABASE_ERROR.');
    $assert((int) $scalar('SELECT COUNT(*) FROM disabled_dates WHERE datum BETWEEN :start AND :end', [':start' => $rollbackStart, ':end' => $rollbackEnd]) === 0, 'Auditfout rolt disabled-datewrites niet volledig terug.');
    $assert((int) $scalar('SELECT COUNT(*) FROM calendar_date_change_history WHERE start_date = :start AND end_date = :end', [':start' => $rollbackStart, ':end' => $rollbackEnd]) === 0, 'Auditfout laat een gedeeltelijke header staan.');
    $assert((int) $scalar(
        'SELECT COUNT(*) FROM calendar_date_change_history_dates d
         INNER JOIN calendar_date_change_history h ON h.id = d.history_id
         WHERE h.start_date = :start AND h.end_date = :end',
        [':start' => $rollbackStart, ':end' => $rollbackEnd],
    ) === 0, 'Auditfout laat gedeeltelijke children staan.');

    fwrite(STDOUT, "PROOF: toekomstige manual en school_vacation zijn via preview/execute vrijgegeven; gemengde typetellingen en exacte deletes kloppen.\n");
    fwrite(STDOUT, "PROOF: verleden datum, weekend zonder record en niet-vrijgeefbaar weekendtype bleven beschermd.\n");
    fwrite(STDOUT, "PROOF: verdwenen verwacht vakantierecord gaf CALENDAR_DATE_CONFLICT; tweede uitvoering gaf NO_CHANGE.\n");
    fwrite(STDOUT, "PROOF: boekingssnapshot en booking/status/change-history bleven ongewijzigd.\n");
    fwrite(STDOUT, "OK: kalenderdatumbeheer MariaDB-integratie geslaagd ({$runId}).\n");
} catch (Throwable $exception) {
    fwrite(STDERR, "FAIL: {$exception->getMessage()}\n");
    $exitCode = 1;
} finally {
    try {
        if ($pdo->inTransaction()) {
            $guard();
            $pdo->rollBack();
        }
        if ($adminId !== null) {
            $mutate(
                'DELETE FROM calendar_date_change_history_dates
                 WHERE history_id IN (SELECT id FROM calendar_date_change_history WHERE changed_by_admin_id = :adminId)',
                [':adminId' => $adminId],
            );
            $mutate('DELETE FROM calendar_date_change_history WHERE changed_by_admin_id = :adminId', [':adminId' => $adminId]);
        }
        if ($ownedDates !== []) {
            $dates = array_keys($ownedDates);
            $placeholders = implode(', ', array_fill(0, count($dates), '?'));
            $guard();
            $statement = $pdo->prepare("DELETE FROM disabled_dates WHERE datum IN ({$placeholders})");
            $statement->execute($dates);
            $guard();
            $statement = $pdo->prepare("DELETE FROM generated_disabled_date_release_overrides WHERE datum IN ({$placeholders})");
            $statement->execute($dates);
            $guard();
            $statement = $pdo->prepare("DELETE FROM booking_day_settings WHERE visit_date IN ({$placeholders})");
            $statement->execute($dates);
        }
        if ($bookingIds !== []) {
            $placeholders = implode(', ', array_fill(0, count($bookingIds), '?'));
            foreach ([
                'DELETE FROM booking_rule_overrides WHERE booking_id IN (%s)',
                'DELETE FROM booking_change_history WHERE booking_id IN (%s)',
                'DELETE FROM booking_status_history WHERE booking_id IN (%s)',
                'DELETE FROM aanvraag_onderwijs_selecties WHERE aanvraag_id IN (%s)',
                'DELETE FROM aanvragen WHERE id IN (%s)',
            ] as $sql) {
                $guard();
                $statement = $pdo->prepare(sprintf($sql, $placeholders));
                $statement->execute($bookingIds);
            }
        }
        if ($adminId !== null) {
            $mutate('DELETE FROM admin_users WHERE id = :id', [':id' => $adminId]);
        }
    } catch (Throwable $cleanupException) {
        fwrite(STDERR, "FAIL: fixturecleanup mislukt: {$cleanupException->getMessage()}\n");
        $exitCode = 1;
    }
}

exit($exitCode);

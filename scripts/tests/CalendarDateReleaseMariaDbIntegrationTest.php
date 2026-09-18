<?php
declare(strict_types=1);

use GeoFort\Dashboard\Calendar\CalendarDateManagementCommand;
use GeoFort\Security\AuthMiddleware;
use GeoFort\Security\SessionGuard;
use GeoFort\Services\Auth\CsrfTokenService;
use GeoFort\Services\Booking\Availability\DisabledDateGenerator;
use GeoFort\Services\Dashboard\Calendar\CalendarDateManagementPreviewService;
use GeoFort\Services\Dashboard\Calendar\CalendarDateManagementService;
use GeoFort\Services\Http\Api\Admin\DashboardCalendarDateManagementAction;
use GeoFort\Services\Http\Response\JsonResponse;
use GeoFort\Services\Http\Url\EnvironmentBaseUrlProvider;
use GeoFort\Services\Sql\AdminUsersSqlService;
use GeoFort\Services\Sql\BookingDaySettingsSqlRepository;
use GeoFort\Services\Sql\CalendarDateManagementSqlRepository;
use GeoFort\Services\Sql\DisabledDatesSqlService;

require dirname(__DIR__, 2) . '/vendor/autoload.php';
require __DIR__ . '/Support/DisposableBookingMariaDb.php';

// Never load bootstrap/.env or connect to a remote server for this investigation.
if (!in_array(getenv('STATUS_TEST_DB_HOST') ?: '127.0.0.1', ['127.0.0.1', 'localhost', '::1'], true)) {
    throw new RuntimeException('Deze test vereist een lokale disposable MariaDB.');
}
$database = DisposableBookingMariaDb::create('calendar_release');
$pdo = $database->pdo;
$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};
$root = dirname(__DIR__, 2);
$migration = static function (string $file) use ($pdo, $root): void {
    $pdo->exec((string) file_get_contents($root . '/database/sql/' . $file));
};
$tables = ['disabled_dates', 'booking_day_settings', 'calendar_date_change_history', 'calendar_date_change_history_dates'];
$snapshot = static function () use ($pdo, &$tables): array {
    $result = [];
    foreach ($tables as $table) $result[$table] = $pdo->query("SELECT * FROM {$table} ORDER BY 1, 2")->fetchAll();
    return $result;
};
$repository = new CalendarDateManagementSqlRepository($pdo);
$previews = new CalendarDateManagementPreviewService($repository);
$service = new CalendarDateManagementService($pdo, new BookingDaySettingsSqlRepository($pdo), $repository, $previews);
$today = new DateTimeImmutable('2026-09-17');
$logFile = tempnam(sys_get_temp_dir(), 'geofort_calendar_release_');
if ($logFile === false) throw new RuntimeException('Tijdelijke testlog kon niet worden aangemaakt.');
$previousLog = ini_set('error_log', $logFile);
$command = static function (string $action, string $start, ?string $end = null, ?string $type = null, int $adminId = 1) use ($previews, $today): CalendarDateManagementCommand {
    $end ??= $start;
    $preview = $previews->preview($action, $start, $end, $type, $today)->preview;
    if ($preview === null) throw new RuntimeException('Testpreview ontbreekt.');
    return new CalendarDateManagementCommand(
        $start, $end, $action, $type, $type === null ? null : 'Testreden kalender-vrijgave',
        true, false, $preview->fingerprint, $preview->activeBookingsFingerprint, $adminId,
    );
};
$failure = static function (CalendarDateManagementCommand $command, string $step, string $sqlState, int $driverCode) use ($service, $today, $logFile, $assert) {
    file_put_contents($logFile, '');
    $result = $service->change($command, $today, 'calendar-release-test-request');
    $log = trim((string) file_get_contents($logFile));
    $offset = strpos($log, '{');
    $assert($offset !== false, 'Foutlog mist gestructureerde technische context.');
    $context = json_decode(substr($log, $offset), true, 512, JSON_THROW_ON_ERROR);
    $assert($context['operation'] === $command->action && $context['step'] === $step, 'Log noemt niet de mislukte operatie en opslagstap.');
    $assert($context['database_exception'] === PDOException::class && $context['sqlstate'] === $sqlState && $context['driver_code'] === $driverCode, 'Log verliest de oorspronkelijke PDO-foutcodes.');
    $assert($context['request_id'] === 'calendar-release-test-request', 'Request-ID ontbreekt in de foutlog.');
    $assert(!str_contains($log, 'private-database-message-marker') && !str_contains($log, $command->startDate) && !str_contains($log, 'Testreden'), 'Log lekt databasebericht of parameterwaarden.');
    $public = json_encode($result, JSON_THROW_ON_ERROR);
    foreach ([$sqlState, (string) $driverCode, 'PDOException', 'private-database-message-marker', 'generated_disabled_date_release_overrides'] as $private) {
        $assert(!str_contains($public, $private), 'Mutatieresultaat lekt interne foutdetails.');
    }
    return $result;
};

try {
    $assert(str_contains((string) $pdo->query('SELECT VERSION()')->fetchColumn(), 'MariaDB'), 'Deze test vereist MariaDB.');

    // Reconstruct the pre-provenance seed from the real generator and the INSERT
    // columns used by DisabledDatesSqlService at a5fc7be, then apply the real DDL.
    // This is a code-derived fixture, not a claim about production records.
    $pdo->exec('ALTER TABLE disabled_dates DROP COLUMN source');
    $generated = array_values(array_filter(
        (new DisabledDateGenerator())->generateUntilEndSchoolYear2027(),
        static fn (array $row): bool => $row['datum'] >= '2027-02-20' && $row['datum'] <= '2027-02-28',
    ));
    $seed = $pdo->prepare('INSERT INTO disabled_dates (datum, type, reden) VALUES (:datum, :type, :reden)');
    foreach ($generated as $row) $seed->execute($row);
    $migration('2026-07-28_create_calendar_date_change_history.sql');
    $migration('2026-07-30_add_disabled_date_provenance_and_audit_types.sql');
    $assert(count($generated) === 9, 'De werkelijke vakantieconfiguratie moet negen dagen opleveren.');
    $assert((int) $pdo->query("SELECT COUNT(*) FROM disabled_dates WHERE source = 'generated' AND type = 'school_vacation'")->fetchColumn() === 9, 'Migratie geeft oude vakanties niet de verwachte bron.');

    // Schema at 30 July: both dashboard-created types work without the later table.
    foreach (['2027-02-15' => 'manual', '2027-02-16' => 'school_vacation'] as $date => $type) {
        $blocked = $service->change($command('block_single', $date, type: $type), $today);
        $assert($blocked->success, 'Handmatig blokkeren faalt.');
        $assert($pdo->query("SELECT source FROM disabled_dates WHERE datum = '{$date}'")->fetchColumn() === 'planner', 'Dashboard mist planner-provenance.');
        $released = $service->change($command('release_period', $date), $today);
        $assert($released->success && $released->affectedCount === 1, 'Handmatige vrijgave faalt.');
        $assert((int) $pdo->query("SELECT COUNT(*) FROM disabled_dates WHERE datum = '{$date}'")->fetchColumn() === 0, 'Handmatige blokkade blijft staan.');
        $audit = $pdo->query("SELECT type_before, type_after FROM calendar_date_change_history_dates WHERE calendar_date = '{$date}' ORDER BY history_id DESC LIMIT 1")->fetch();
        $assert($audit['type_before'] === $type && $audit['type_after'] === null, 'Vrijgave mist type-audit.');
    }

    // Reproduce the missing 4 August table confirmed by production inspection,
    // using only the code-derived fixture in this disposable database.
    foreach (['2027-02-22', '2027-02-25'] as $date) {
        $before = $snapshot();
        $result = $failure($command('release_period', $date), 'write_generated_release_override', '42S02', 1146);
        $assert(!$result->success && $result->code === 'DATABASE_ERROR', 'Ontbrekende override-tabel wordt niet als fout behandeld.');
        $assert($snapshot() === $before && !$pdo->inTransaction(), 'Mislukte vakantievrijgave laat gedeeltelijke wijzigingen achter.');
    }
    $pdo->beginTransaction();
    try {
        $repository->releaseBlock('2027-02-22', 'school_vacation', 'generated', 1);
        throw new RuntimeException('Ontbrekende override-tabel gaf geen exception.');
    } catch (Throwable $exception) {
        $cause = $exception;
        while (!$cause instanceof PDOException && $cause->getPrevious() !== null) $cause = $cause->getPrevious();
        $assert($cause instanceof PDOException && $cause->errorInfo[0] === '42S02' && (int) $cause->errorInfo[1] === 1146, 'Ontbrekende tabel geeft niet de verwachte MariaDB-code.');
    } finally {
        $pdo->rollBack();
    }

    $assert($service->change($command('block_single', '2027-02-19', type: 'manual'), $today)->success, 'Gemengde fixture mislukt.');
    $before = $snapshot();
    $mixedFailure = $failure($command('release_period', '2027-02-19', '2027-02-22'), 'write_generated_release_override', '42S02', 1146);
    $assert(!$mixedFailure->success && $snapshot() === $before && !$pdo->inTransaction(), 'Eerder verwijderde plannerblokkade wordt niet teruggerold bij een latere vakantiefout.');

    // Exercise the real HTTP controller without a webserver, bootstrap, mail or
    // production sessions. Its date is relative because it uses the real clock.
    $httpDate = (new DateTimeImmutable('today'))->modify('+2 years')->modify('next monday')->format('Y-m-d');
    $pdo->prepare("INSERT INTO disabled_dates (datum, type, source) VALUES (?, 'school_vacation', 'generated')")->execute([$httpDate]);
    $httpCommand = $command('release_period', $httpDate);
    $body = json_encode([
        'expected' => [
            'startDate' => $httpDate, 'endDate' => $httpDate,
            'previewFingerprint' => $httpCommand->expectedFingerprint,
            'activeBookingsFingerprint' => $httpCommand->expectedActiveBookingsFingerprint,
        ],
        'proposed' => [
            'action' => 'release_period', 'disabledType' => null, 'reason' => null,
            'confirmed' => true, 'existingBookingsAccepted' => false,
        ],
    ], JSON_THROW_ON_ERROR);
    ini_set('session.save_path', sys_get_temp_dir());
    $auth = new AuthMiddleware('geofort_calendar_release_test', 'Lax', false);
    $auth->startPublicSession();
    $user = $pdo->query('SELECT id, email, name, role FROM admin_users WHERE id = 1')->fetch();
    $auth->establishAuthenticatedSession($user, null, 'calendar-test-agent');
    $csrf = new CsrfTokenService();
    $token = $csrf->getOrCreate(DashboardCalendarDateManagementAction::CSRF_SCOPE);
    $response = new JsonResponse(new EnvironmentBaseUrlProvider('development', 'https://onderwijsformulier.test'));
    $action = new DashboardCalendarDateManagementAction($auth, new SessionGuard(new AdminUsersSqlService($pdo, 10), 1800, 300), $csrf, $service, $response);
    $send = static function (string $method, ?string $submittedToken, ?string $requestId) use ($action, $body, $response): array {
        ob_start();
        try {
            $action->send($method, 'application/json', $submittedToken, $body, 'calendar-test-agent', $requestId);
            $json = (string) ob_get_contents();
        } finally {
            ob_end_clean();
        }
        return [http_response_code(), json_decode($json, true, 512, JSON_THROW_ON_ERROR), (new ReflectionProperty($response, 'headers'))->getValue($response)];
    };
    $before = $snapshot();
    file_put_contents($logFile, '');
    [$status, $payload] = $send('POST', $csrf->getOrCreate('logout'), null);
    $assert($status === 403 && $payload['code'] === 'INVALID_CSRF', 'Token van een andere actie autoriseert kalenderbeheer.');
    [$status, $payload] = $send('GET', $token, null);
    $assert($status === 405 && $payload['code'] === 'METHOD_NOT_ALLOWED', 'Mutatie accepteert GET.');
    unset($_SESSION['user_id']);
    [$status, $payload] = $send('POST', $token, null);
    $assert($status === 401 && $payload['code'] === 'UNAUTHENTICATED', 'Ongeldige sessie bereikt de mutatie.');
    $assert($snapshot() === $before && file_get_contents($logFile) === '', 'Autorisatiefout roept de mutatieservice aan.');
    $auth->establishAuthenticatedSession($user, null, 'calendar-test-agent');
    foreach (['http-request-123', "invalid\r\nprivate-marker", null] as $requestId) {
        file_put_contents($logFile, '');
        [$status, $payload, $headers] = $send('POST', $token, $requestId);
        $assert($status === 500 && $payload['code'] === 'DATABASE_ERROR' && $payload['ok'] === false, 'Opslagfout geeft geen generieke HTTP 500.');
        $assert($payload['issues'][0]['description'] === 'De kalenderwijziging kon niet worden opgeslagen. Probeer het later opnieuw.', 'Interne foutdetails bereiken de browser.');
        $log = (string) file_get_contents($logFile);
        $context = json_decode(substr($log, (int) strpos($log, '{')), true, 512, JSON_THROW_ON_ERROR);
        $expectedId = $requestId === 'http-request-123' ? $requestId : null;
        $assert(($headers['X-Request-ID'] ?? null) === $expectedId && $context['request_id'] === $expectedId, 'Request-ID wordt niet veilig tussen response en log gekoppeld.');
        $assert(!str_contains($log, $token) && !str_contains($log, session_id()) && !str_contains($log, 'private-marker'), 'HTTP-foutlog lekt token, sessie of onveilige header.');
        $assert($snapshot() === $before && !$pdo->inTransaction(), 'HTTP-fout laat gedeeltelijke mutatie achter.');
    }
    session_destroy();
    fwrite(STDOUT, "PROOF: zonder migratie 4 augustus falen gegenereerde vakanties met 42S02/1146; beide plannertypen slagen; rollback is volledig.\n");

    // The checked-in migration alone resolves that test scenario.
    $migration('2026-08-04_create_generated_disabled_date_release_overrides.sql');
    $tables[] = 'generated_disabled_date_release_overrides';
    foreach (['2027-02-22' => 'release_single', '2027-02-25' => 'release_period'] as $date => $action) {
        $result = $service->change($command($action, $date), $today);
        $assert($result->success && $result->affectedCount === 1, 'Gegenereerde vakantie kan met volledig schema niet worden vrijgegeven.');
        $assert((int) $pdo->query("SELECT COUNT(*) FROM disabled_dates WHERE datum = '{$date}'")->fetchColumn() === 0, 'Gegenereerde blokkade blijft staan.');
        $assert((int) $pdo->query("SELECT COUNT(*) FROM generated_disabled_date_release_overrides WHERE datum = '{$date}' AND type = 'school_vacation' AND released_by_admin_id = 1")->fetchColumn() === 1, 'Persistente vrijgave ontbreekt.');
        $audit = $pdo->query("SELECT type_before, type_after FROM calendar_date_change_history_dates WHERE calendar_date = '{$date}' ORDER BY history_id DESC LIMIT 1")->fetch();
        $assert($audit['type_before'] === 'school_vacation' && $audit['type_after'] === null, 'Gegenereerde vrijgave mist type-audit.');
    }
    (new DisabledDatesSqlService($pdo))->upsertGeneratedDates($generated);
    $assert((int) $pdo->query("SELECT COUNT(*) FROM disabled_dates WHERE datum IN ('2027-02-22', '2027-02-25')")->fetchColumn() === 0, 'Seeder herintroduceert vrijgegeven vakanties.');
    $before = $snapshot();
    $noop = $service->change($command('release_period', '2027-02-22'), $today);
    $assert($noop->success && $noop->code === 'NO_CHANGE' && $snapshot() === $before, 'Herhaalde vrijgave schrijft toch data/audit.');

    // Fail on a later audit child, after multiple deletes, overrides, header and
    // an earlier child have succeeded. DDL remains outside the tested transaction.
    $pdo->exec("CREATE TRIGGER fail_calendar_release_child BEFORE INSERT ON calendar_date_change_history_dates FOR EACH ROW
        BEGIN IF NEW.calendar_date = '2027-02-24' THEN
        SIGNAL SQLSTATE '45000' SET MYSQL_ERRNO = 1644, MESSAGE_TEXT = 'private-database-message-marker';
        END IF; END");
    $before = $snapshot();
    $auditFailure = $failure($command('release_period', '2027-02-19', '2027-02-26'), 'insert_audit_date', '45000', 1644);
    $assert(!$auditFailure->success && $auditFailure->code === 'DATABASE_ERROR', 'Auditchildfout wordt niet als databasefout behandeld.');
    $assert($snapshot() === $before && !$pdo->inTransaction(), 'Auditchildfout laat deletes, overrides, locks of audit achter.');
    $pdo->exec('DROP TRIGGER fail_calendar_release_child');

    $before = $snapshot();
    $foreignKeyFailure = $failure($command('release_period', '2027-02-19', '2027-02-26', adminId: 4294967295), 'insert_audit_header', '23000', 1452);
    $assert(!$foreignKeyFailure->success && $snapshot() === $before && !$pdo->inTransaction(), 'Audit-FK-fout rolt de volledige gemengde vrijgave niet terug.');

    // Deleting a generated block can fail after its override was written.
    $pdo->exec("CREATE TRIGGER fail_calendar_release_delete BEFORE DELETE ON disabled_dates FOR EACH ROW
        BEGIN IF OLD.datum = '2027-02-24' THEN
        SIGNAL SQLSTATE '45000' SET MYSQL_ERRNO = 1644, MESSAGE_TEXT = 'private-database-message-marker';
        END IF; END");
    $before = $snapshot();
    $deleteFailure = $failure($command('release_single', '2027-02-24'), 'delete_disabled_date', '45000', 1644);
    $assert(!$deleteFailure->success && $snapshot() === $before && !$pdo->inTransaction(), 'Deletefout laat een vrijgave-override achter.');
    $pdo->exec('DROP TRIGGER fail_calendar_release_delete');

    $success = $service->change($command('release_period', '2027-02-19', '2027-02-26'), $today);
    $assert($success->success && $success->affectedCount === 4, 'Gemengde vrijgave slaagt niet na verwijderen van uitsluitend de testfout.');
    $assert((int) $pdo->query("SELECT COUNT(*) FROM disabled_dates WHERE datum IN ('2027-02-20', '2027-02-21', '2027-02-27', '2027-02-28')")->fetchColumn() === 4, 'Weekendvakanties zijn onbedoeld vrijgegeven.');

    // The initial database preview must use the same diagnostic and public boundary.
    $previewCommand = $command('release_period', '2027-02-22');
    $before = $snapshot();
    $pdo->exec('RENAME TABLE disabled_dates TO disabled_dates_test_unavailable');
    try {
        $previewFailure = $failure($previewCommand, 'initial_preview', '42S02', 1146);
        $assert(!$previewFailure->success && $previewFailure->code === 'DATABASE_ERROR', 'Initiële previewfout ontsnapt aan de service.');
    } finally {
        $pdo->exec('RENAME TABLE disabled_dates_test_unavailable TO disabled_dates');
    }
    $assert($snapshot() === $before && !$pdo->inTransaction(), 'Initiële previewfout wijzigt data.');
    fwrite(STDOUT, "PROOF: volledig schema ondersteunt oude seed, single/periode, persistente vrijgave, herhaald seeden en rollback bij audit-FK en latere auditchildfout.\n");
    fwrite(STDOUT, "OK: kalender-vrijgave MariaDB ({$assertions} controles, PHP " . PHP_VERSION . ").\n");
} finally {
    if (session_status() === PHP_SESSION_ACTIVE) session_destroy();
    ini_set('error_log', $previousLog === false ? '' : $previousLog);
    unlink($logFile);
    if ($pdo->inTransaction()) $pdo->rollBack();
    $database->drop();
}

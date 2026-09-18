<?php
declare(strict_types=1);

use GeoFort\Services\Dashboard\Calendar\CalendarDateManagementFailureLogger;
use GeoFort\Services\Sql\CalendarDateManagementSqlException;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};
$file = tempnam(sys_get_temp_dir(), 'geofort_calendar_log_');
if ($file === false) throw new RuntimeException('Tijdelijke testlog ontbreekt.');
$previousLog = ini_set('error_log', $file);
$capture = static function (Throwable $exception, string $operation, string $step, ?string $requestId = null) use ($file, $assert): array {
    file_put_contents($file, '');
    CalendarDateManagementFailureLogger::log($exception, $operation, $step, $requestId);
    $raw = trim((string) file_get_contents($file));
    $assert(!str_contains($raw, 'private-marker') && !str_contains($raw, 'SELECT'), 'Logger schrijft ongefilterde exception/context.');
    return json_decode(substr($raw, (int) strpos($raw, '{')), true, 512, JSON_THROW_ON_ERROR);
};

try {
    $pdoError = new PDOException('private-marker SQL parameters and credentials');
    $pdoError->errorInfo = ['23000', 1452, 'private-marker raw driver message'];
    $wrapped = new RuntimeException('private-marker outer message', 0, new CalendarDateManagementSqlException('insert_audit_header', $pdoError));
    $log = $capture($wrapped, 'release_period', 'insert_audit', 'proxy-request_123:abc@host');
    $assert($log === [
        'operation' => 'release_period',
        'step' => 'insert_audit_header',
        'exception' => RuntimeException::class,
        'database_exception' => PDOException::class,
        'sqlstate' => '23000',
        'driver_code' => 1452,
        'request_id' => 'proxy-request_123:abc@host',
    ], 'Logger verliest geneste foutcodes of voegt onbedoelde velden toe.');

    $log = $capture(new RuntimeException('private-marker'), 'release_single', 'rollback');
    $assert($log['sqlstate'] === null && $log['driver_code'] === null && $log['database_exception'] === null && $log['request_id'] === null, 'Niet-PDO-fout krijgt verzonnen databasegegevens.');

    $pdoError->errorInfo = ['private-marker', "1146\nprivate-marker", 'private-marker'];
    $log = $capture(new CalendarDateManagementSqlException("SELECT private-marker\n", $pdoError), 'private-marker', 'commit', "valid\r\nprivate-marker");
    $assert($log['operation'] === 'manage_date' && $log['step'] === 'unknown' && $log['sqlstate'] === null && $log['driver_code'] === null && $log['request_id'] === null, 'Onveilige technische velden worden niet geweigerd.');

    $pdoError->errorInfo = ['42S02', '1146', 'private-marker'];
    $log = $capture($pdoError, 'release_single', 'initial_preview');
    $assert($log['sqlstate'] === '42S02' && $log['driver_code'] === 1146, 'Drivercode als string wordt niet ondersteund.');

    $log = $capture(new PDOException('private-marker'), 'release_single', 'commit');
    $assert($log['sqlstate'] === null && $log['driver_code'] === null, 'PDO-fout zonder errorInfo levert geen lege codes.');
    foreach (['', "with space", "line\nfeed", str_repeat('a', 129), 'é'] as $invalidId) {
        $assert(CalendarDateManagementFailureLogger::requestId($invalidId) === null, 'Ongeldig request-ID wordt geaccepteerd.');
    }
    $assert(CalendarDateManagementFailureLogger::requestId(str_repeat('a', 128)) === str_repeat('a', 128), 'Geldig begrensd request-ID wordt geweigerd.');
    fwrite(STDOUT, "OK: kalender-foutlogging ({$assertions} controles).\n");
} finally {
    ini_set('error_log', $previousLog === false ? '' : $previousLog);
    unlink($file);
}

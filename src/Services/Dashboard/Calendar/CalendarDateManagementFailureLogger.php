<?php
declare(strict_types=1);

namespace GeoFort\Services\Dashboard\Calendar;

use GeoFort\Dashboard\Calendar\CalendarDateManagementPolicy;
use GeoFort\Services\Sql\CalendarDateManagementSqlException;
use PDOException;
use Throwable;

final class CalendarDateManagementFailureLogger
{
    public static function requestId(?string $value): ?string
    {
        return $value !== null && preg_match('/\A[A-Za-z0-9._@:-]{1,128}\z/', $value) === 1 ? $value : null;
    }

    public static function log(Throwable $exception, string $operation, string $step, ?string $requestId = null): void
    {
        $databaseException = null;
        for ($cause = $exception; $cause !== null; $cause = $cause->getPrevious()) {
            if ($cause instanceof CalendarDateManagementSqlException) $step = $cause->step;
            if ($cause instanceof PDOException) {
                $databaseException = $cause;
                break;
            }
        }
        // Read the exception's own codes, including wrapped audit failures. Never
        // log getMessage(), errorInfo[2], SQL, bound values, traces or request data.
        $sqlState = $databaseException?->errorInfo[0] ?? $databaseException?->getCode();
        $driverCode = $databaseException?->errorInfo[1] ?? null;
        $context = [
            'operation' => in_array($operation, CalendarDateManagementPolicy::ACTIONS, true) ? $operation : 'manage_date',
            'step' => preg_match('/\A[a-z_]{1,64}\z/', $step) === 1 ? $step : 'unknown',
            'exception' => $exception::class,
            'database_exception' => $databaseException === null ? null : $databaseException::class,
            'sqlstate' => is_string($sqlState) && preg_match('/\A[A-Z0-9]{5}\z/', $sqlState) === 1 ? $sqlState : null,
            'driver_code' => (is_int($driverCode) || is_string($driverCode)) && preg_match('/\A[0-9]{1,10}\z/', (string) $driverCode) === 1 ? (int) $driverCode : null,
            'request_id' => self::requestId($requestId),
        ];
        error_log('[CalendarDateManagementService] Mutatie mislukt: ' . json_encode($context, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE));
    }
}

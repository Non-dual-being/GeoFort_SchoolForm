<?php
declare(strict_types=1);

namespace GeoFort\Services\Sql;

use RuntimeException;
use Throwable;

final class CalendarDateManagementSqlException extends RuntimeException
{
    public function __construct(public readonly string $step, Throwable $previous)
    {
        parent::__construct('Kalenderopslag mislukt.', 0, $previous);
    }
}

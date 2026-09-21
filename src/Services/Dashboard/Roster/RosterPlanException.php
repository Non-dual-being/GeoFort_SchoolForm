<?php

declare(strict_types=1);

namespace GeoFort\Services\Dashboard\Roster;

use RuntimeException;

final class RosterPlanException extends RuntimeException
{
    public function __construct(
        public readonly string $publicCode,
        public readonly int $httpStatus,
        string $message,
    ) {
        parent::__construct($message);
    }
}
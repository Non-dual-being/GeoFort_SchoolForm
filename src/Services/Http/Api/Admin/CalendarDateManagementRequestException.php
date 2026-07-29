<?php
declare(strict_types=1);

namespace GeoFort\Services\Http\Api\Admin;

use RuntimeException;

final class CalendarDateManagementRequestException extends RuntimeException
{
    public function __construct(public readonly string $publicCode)
    {
        parent::__construct($publicCode);
    }
}

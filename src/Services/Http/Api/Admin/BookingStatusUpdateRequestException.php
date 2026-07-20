<?php
declare(strict_types=1);

namespace GeoFort\Services\Http\Api\Admin;

use InvalidArgumentException;

final class BookingStatusUpdateRequestException extends InvalidArgumentException
{
    public function __construct(public readonly string $publicCode)
    {
        parent::__construct('Invalid booking status update request.');
    }
}

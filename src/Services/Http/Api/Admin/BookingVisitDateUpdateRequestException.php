<?php
declare(strict_types=1);
namespace GeoFort\Services\Http\Api\Admin;

use RuntimeException;

final class BookingVisitDateUpdateRequestException extends RuntimeException
{
    public function __construct(public readonly string $publicCode){parent::__construct($publicCode);}
}

<?php
declare(strict_types=1);

namespace GeoFort\Services\Dashboard\Booking\Revenue;

final readonly class BookingRevenueCriteria
{
    public function __construct(public string $startDate, public string $endDate) {}
}

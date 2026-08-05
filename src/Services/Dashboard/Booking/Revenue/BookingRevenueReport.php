<?php
declare(strict_types=1);

namespace GeoFort\Services\Dashboard\Booking\Revenue;

use GeoFort\Services\Dashboard\Booking\Export\BookingExportDateBounds;

final readonly class BookingRevenueReport
{
    /** @param array<string, int> $counts @param array<string, int> $definitiveRevenue @param array<string, int> $potentialRevenue @param list<array<string, mixed>> $bookings */
    public function __construct(
        public BookingRevenueCriteria $period,
        public BookingExportDateBounds $availableVisitDateRange,
        public array $counts,
        public array $definitiveRevenue,
        public array $potentialRevenue,
        public array $bookings,
    ) {}
}

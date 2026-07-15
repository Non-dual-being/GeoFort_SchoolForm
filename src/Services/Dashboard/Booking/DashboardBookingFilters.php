<?php
declare(strict_types=1);

namespace GeoFort\Services\Dashboard\Booking;

final readonly class DashboardBookingFilters
{
    public function __construct(
        public ?string $search,
        public ?string $status,
        public ?string $sector,
        public ?string $program,
        public ?string $module,
        public ?string $dateFrom,
        public ?string $dateTo,
        public int $page,
        public int $perPage,
    ) {}
}

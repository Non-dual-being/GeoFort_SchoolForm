<?php
declare(strict_types=1);

namespace GeoFort\Services\Dashboard\Booking;

final readonly class DashboardBookingDateRange
{
    public function __construct(
        public ?string $min,
        public ?string $max,
    ) {}

    /** @return array{min: string|null, max: string|null} */
    public function toArray(): array
    {
        return [
            'min' => $this->min,
            'max' => $this->max,
        ];
    }
}

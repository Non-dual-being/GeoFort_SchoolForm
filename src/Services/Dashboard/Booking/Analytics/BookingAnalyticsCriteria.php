<?php
declare(strict_types=1);

namespace GeoFort\Services\Dashboard\Booking\Analytics;

use GeoFort\Services\Dashboard\Booking\Export\BookingExportDateBounds;

final readonly class BookingAnalyticsCriteria
{
    public function __construct(
        public string $requestedStartDate,
        public string $requestedEndDate,
        public ?string $effectiveStartDate,
        public ?string $effectiveEndDate,
        public BookingExportDateBounds $dateBounds,
        public string $sector = 'all',
        public string $population = 'planning',
        public string $program = 'all',
    ) {}

    public function hasOverlap(): bool
    {
        return $this->effectiveStartDate !== null && $this->effectiveEndDate !== null;
    }

    /** @return array<string, string|bool|null> */
    public function toArray(): array
    {
        return [
            'startDate' => $this->requestedStartDate,
            'endDate' => $this->requestedEndDate,
            'effectiveStartDate' => $this->effectiveStartDate,
            'effectiveEndDate' => $this->effectiveEndDate,
            'normalized' => $this->hasOverlap() && (
                $this->requestedStartDate !== $this->effectiveStartDate
                || $this->requestedEndDate !== $this->effectiveEndDate
            ),
            'hasOverlap' => $this->hasOverlap(),
            'sector' => $this->sector,
            'population' => $this->population,
            'program' => $this->program,
        ];
    }
}

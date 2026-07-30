<?php
declare(strict_types=1);

namespace GeoFort\Services\Dashboard\Booking\Export;

final readonly class BookingExportCriteria
{
    public const SORT_LABEL = 'Bezoekdatum oplopend, daarna boeking-ID';

    public function __construct(
        public string $requestedStartDate,
        public string $requestedEndDate,
        public ?string $effectiveStartDate,
        public ?string $effectiveEndDate,
        public BookingExportDateBounds $availableBounds,
    ) {}

    public function hasOverlap(): bool
    {
        return $this->effectiveStartDate !== null && $this->effectiveEndDate !== null;
    }

    public function wasNormalized(): bool
    {
        return $this->hasOverlap() && (
            $this->requestedStartDate !== $this->effectiveStartDate
            || $this->requestedEndDate !== $this->effectiveEndDate
        );
    }

    /** @return array<string, string|bool|null> */
    public function toArray(): array
    {
        return [
            'requestedStartDate' => $this->requestedStartDate,
            'requestedEndDate' => $this->requestedEndDate,
            'effectiveStartDate' => $this->effectiveStartDate,
            'effectiveEndDate' => $this->effectiveEndDate,
            'availableMinDate' => $this->availableBounds->minDate,
            'availableMaxDate' => $this->availableBounds->maxDate,
            'hasOverlap' => $this->hasOverlap(),
            'normalized' => $this->wasNormalized(),
            'sortLabel' => self::SORT_LABEL,
        ];
    }
}

<?php
declare(strict_types=1);

namespace GeoFort\Services\Dashboard\Booking\Export;

final readonly class BookingExportDateBounds
{
    public function __construct(
        public ?string $minDate,
        public ?string $maxDate,
    ) {}

    public function isEmpty(): bool
    {
        return $this->minDate === null || $this->maxDate === null;
    }

    /** @return array{minDate: string|null, maxDate: string|null} */
    public function toArray(): array
    {
        return ['minDate' => $this->minDate, 'maxDate' => $this->maxDate];
    }
}

<?php

declare(strict_types=1);

namespace GeoFort\Booking\Stored;

final readonly class BookingSourceMetadata
{
    public function __construct(
        public ?string $sourceSystem,
        public ?int $sourceRecordId,
        public ?string $sourceRecordChecksum,
        public ?int $sourceImportRunId,
        public bool $hasNormalizedEducationSelection,
    ) {}

    public function isLegacy(): bool
    {
        return $this->sourceSystem !== null;
    }
}

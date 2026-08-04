<?php
declare(strict_types=1);

namespace GeoFort\Services\Booking\Pricing\Backfill;

final readonly class BookingPriceBackfillAnalysis
{
    /** @param array<string, int> $calculationStates @param list<array<string, mixed>> $candidates @param list<array<string, mixed>> $exceptions */
    public function __construct(
        public int $totalBookings,
        public int $legacyBookings,
        public int $nativeBookings,
        public int $unknownSourceBookings,
        public int $legacyWithSourceRecordId,
        public int $uniqueLegacySourceRecordIds,
        public int $legacyWithoutSourceRecordId,
        public ?int $minimumLegacySourceRecordId,
        public ?int $maximumLegacySourceRecordId,
        public int $withoutSnapshot,
        public int $withSnapshot,
        public int $calculable,
        public int $notCalculable,
        public array $calculationStates,
        public array $candidates,
        public array $exceptions,
        public int $added = 0,
    ) {}

    public function reconciliationMatches(int $expectedTotal, int $expectedLegacy, int $expectedNative, int $expectedMinimumSourceRecordId, int $expectedMaximumSourceRecordId): bool
    {
        return $this->totalBookings === $expectedTotal
            && $this->legacyBookings === $expectedLegacy
            && $this->nativeBookings === $expectedNative
            && $this->unknownSourceBookings === 0
            && $this->legacyWithSourceRecordId === $expectedLegacy
            && $this->uniqueLegacySourceRecordIds === $expectedLegacy
            && $this->legacyWithoutSourceRecordId === 0
            && $this->minimumLegacySourceRecordId === $expectedMinimumSourceRecordId
            && $this->maximumLegacySourceRecordId === $expectedMaximumSourceRecordId;
    }

    public function withExecution(int $added, array $exceptions): self
    {
        return new self($this->totalBookings,$this->legacyBookings,$this->nativeBookings,$this->unknownSourceBookings,$this->legacyWithSourceRecordId,$this->uniqueLegacySourceRecordIds,$this->legacyWithoutSourceRecordId,$this->minimumLegacySourceRecordId,$this->maximumLegacySourceRecordId,$this->withoutSnapshot,$this->withSnapshot,$this->calculable,count($exceptions),$this->calculationStates,$this->candidates,$exceptions,$added);
    }
}

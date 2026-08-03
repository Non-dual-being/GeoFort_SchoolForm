<?php

declare(strict_types=1);

namespace GeoFort\Services\Booking\Pricing;

use InvalidArgumentException;

final readonly class BookingPriceSnapshot
{
    public const STATE_COMPLETE = 'complete';
    public const STATE_HISTORICAL_UNAVAILABLE = 'historical_price_unavailable';
    public const STATE_INVALID_INPUT = 'invalid_input';
    public const REASON_SUBMISSION = 'submission';
    public const REASON_PLANNER_UPDATE = 'planner_update';
    public const REASON_CONFIRMATION = 'confirmation';
    public const REASON_LEGACY_ACCEPTANCE = 'legacy_manual_acceptance';
    public const REASON_LEGACY_UNAVAILABLE_CONFIRMATION = 'legacy_unavailable_at_confirmation';

    /** @param array<string, mixed> $details */
    public function __construct(
        public ?int $id,
        public int $bookingId,
        public int $sequenceNumber,
        public ?int $previousSnapshotId,
        public string $reason,
        public string $calculationState,
        public ?string $pricingVersion,
        public ?string $currencyCode,
        public ?string $vatMeaning,
        public ?int $vatBasisPoints,
        public ?int $visitAmountInclVatCents,
        public ?int $cateringAmountInclVatCents,
        public ?int $totalAmountInclVatCents,
        public ?int $totalAmountExclVatCents,
        public ?int $vatAmountCents,
        public string $canonicalInputJson,
        public array $details,
        public string $inputChecksum,
        public int $checksumFormatVersion,
        public ?int $createdByAdminId,
        public ?string $createdAt = null,
    ) {
        if (!in_array($reason, [self::REASON_SUBMISSION,self::REASON_PLANNER_UPDATE,self::REASON_CONFIRMATION,self::REASON_LEGACY_ACCEPTANCE,self::REASON_LEGACY_UNAVAILABLE_CONFIRMATION], true)) throw new InvalidArgumentException('Ongeldige snapshotreden.');
        if (!in_array($calculationState, [self::STATE_COMPLETE,self::STATE_HISTORICAL_UNAVAILABLE,self::STATE_INVALID_INPUT], true)) throw new InvalidArgumentException('Ongeldige calculation state.');
        if ($sequenceNumber < 1 || preg_match('/^[a-f0-9]{64}$/', $inputChecksum) !== 1) throw new InvalidArgumentException('Ongeldige snapshotmetadata.');
        if ($calculationState === self::STATE_COMPLETE) {
            foreach ([$visitAmountInclVatCents,$cateringAmountInclVatCents,$totalAmountInclVatCents,$totalAmountExclVatCents,$vatAmountCents] as $amount) if ($amount === null || $amount < 0) throw new InvalidArgumentException('Complete snapshot mist bedragen.');
            if ($visitAmountInclVatCents + $cateringAmountInclVatCents !== $totalAmountInclVatCents || $totalAmountExclVatCents + $vatAmountCents !== $totalAmountInclVatCents) throw new InvalidArgumentException('Snapshotbedragen schenden de totaleninvariant.');
            if ($pricingVersion === null || $currencyCode === null || $vatMeaning === null || $vatBasisPoints === null) throw new InvalidArgumentException('Complete snapshot mist prijsmetadata.');
        } elseif ($visitAmountInclVatCents !== null || $cateringAmountInclVatCents !== null || $totalAmountInclVatCents !== null || $totalAmountExclVatCents !== null || $vatAmountCents !== null) {
            throw new InvalidArgumentException('Onbekende bedragen mogen niet als nul of bedrag worden opgeslagen.');
        }
    }

    public function isComplete(): bool { return $this->calculationState === self::STATE_COMPLETE; }
}

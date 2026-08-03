<?php

declare(strict_types=1);

namespace GeoFort\Services\Booking\Pricing;

final readonly class BookingPriceSnapshotFactory
{
    public function __construct(private BookingPricingInputChecksum $checksums = new BookingPricingInputChecksum()) {}

    public function complete(int $bookingId, int $sequence, ?int $previousId, string $reason, BookingPricingInput $input, BookingPriceQuote $quote, ?int $adminId): BookingPriceSnapshot
    {
        return new BookingPriceSnapshot(
            null, $bookingId, $sequence, $previousId, $reason, BookingPriceSnapshot::STATE_COMPLETE,
            $quote->pricingVersion, $quote->currencyCode, BookingPriceCatalog::VAT_MEANING, $quote->vatBasisPoints,
            $quote->visitAmountInclVatCents, $quote->cateringAmountInclVatCents, $quote->totalAmountInclVatCents,
            $quote->totalAmountExclVatCents, $quote->vatAmountCents, $this->checksums->canonicalJson($input),
            $quote->toArray(), $this->checksums->checksum($input), BookingPricingInputChecksum::FORMAT_VERSION, $adminId,
        );
    }
}

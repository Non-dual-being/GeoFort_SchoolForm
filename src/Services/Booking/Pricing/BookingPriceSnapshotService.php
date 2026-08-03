<?php

declare(strict_types=1);

namespace GeoFort\Services\Booking\Pricing;

use GeoFort\Services\Sql\BookingPriceSnapshotSqlRepository;
use RuntimeException;

final readonly class BookingPriceSnapshotService
{
    public function __construct(
        private BookingPriceSnapshotSqlRepository $repository,
        private BookingPriceCalculator $calculator,
        private BookingPriceSnapshotFactory $factory = new BookingPriceSnapshotFactory(),
        private BookingPricingInputChecksum $checksums = new BookingPricingInputChecksum(),
        private BookingPriceCatalogRegistry $catalogs = new BookingPriceCatalogRegistry(),
    ) {}

    public function latest(int $bookingId): ?BookingPriceSnapshot
    {
        $snapshot=$this->repository->latest($bookingId);
        if($snapshot?->isComplete()&&$snapshot->pricingVersion!==null)$this->catalogs->get($snapshot->pricingVersion);
        return $snapshot;
    }

    public function appendUsingActiveVersion(int $bookingId, BookingPricingInput $input, string $reason, ?int $adminId = null): BookingPriceSnapshot
    {
        if ($this->repository->latest($bookingId) !== null && $reason === BookingPriceSnapshot::REASON_LEGACY_ACCEPTANCE) throw new RuntimeException('PRICE_SNAPSHOT_CONFLICT');
        return $this->append($bookingId, $input, $reason, null, $adminId);
    }

    public function appendUsingExistingVersion(int $bookingId, BookingPricingInput $input, string $reason, ?int $adminId = null): BookingPriceSnapshot
    {
        $latest = $this->repository->latest($bookingId);
        if (!$latest?->isComplete() || $latest->pricingVersion === null) throw new RuntimeException('PRICE_SNAPSHOT_REQUIRED');
        if ($this->checksums->matches($input, $latest->inputChecksum, $latest->checksumFormatVersion) && $reason === BookingPriceSnapshot::REASON_PLANNER_UPDATE) return $latest;
        return $this->append($bookingId, $input, $reason, $latest->pricingVersion, $adminId);
    }

    public function isCurrent(BookingPriceSnapshot $snapshot, BookingPricingInput $input): bool
    {
        return $snapshot->isComplete() && $this->checksums->matches($input, $snapshot->inputChecksum, $snapshot->checksumFormatVersion);
    }

    private function append(int $bookingId, BookingPricingInput $input, string $reason, ?string $version, ?int $adminId): BookingPriceSnapshot
    {
        $previous = $this->repository->latest($bookingId);
        $quote = $this->calculator->calculateInput($input, $version);
        return $this->repository->append($this->factory->complete($bookingId, ($previous?->sequenceNumber ?? 0)+1, $previous?->id, $reason, $input, $quote, $adminId));
    }
}

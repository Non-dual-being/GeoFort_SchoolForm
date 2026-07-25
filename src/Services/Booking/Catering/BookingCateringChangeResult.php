<?php

declare(strict_types=1);

namespace GeoFort\Services\Booking\Catering;

use GeoFort\Booking\Validation\StoredBookingIssue;

final readonly class BookingCateringChangeResult
{
    /** @param list<StoredBookingIssue> $validationIssues @param array<string,array{before:int|bool,after:int|bool}> $changedFields */
    public function __construct(
        public BookingCateringChangeCode $code,
        public bool $success,
        public int $bookingId,
        public ?string $status = null,
        public ?BookingCateringValues $previous = null,
        public ?BookingCateringValues $current = null,
        public array $validationIssues = [],
        public array $changedFields = [],
        public ?int $changeHistoryId = null,
    ) {}
}

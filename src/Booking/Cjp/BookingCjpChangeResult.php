<?php

declare(strict_types=1);

namespace GeoFort\Booking\Cjp;

final readonly class BookingCjpChangeResult
{
    /** @param list<BookingCjpIssue> $issues @param array<string,array{before:?string,after:?string}> $changedFields */
    public function __construct(
        public BookingCjpChangeCode $code,
        public bool $success,
        public int $bookingId,
        public ?BookingCjpDetails $previous = null,
        public ?BookingCjpDetails $current = null,
        public array $issues = [],
        public array $changedFields = [],
        public ?int $changeHistoryId = null,
    ) {}
}

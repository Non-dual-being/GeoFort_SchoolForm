<?php

declare(strict_types=1);

namespace GeoFort\Booking\SchoolContact;

final readonly class BookingSchoolContactChangeResult
{
    /** @param list<BookingSchoolContactIssue> $issues @param array<string,array{before:string,after:string}> $changedFields */
    public function __construct(
        public BookingSchoolContactChangeCode $code,
        public bool $success,
        public int $bookingId,
        public ?BookingSchoolContactDetails $previous = null,
        public ?BookingSchoolContactDetails $current = null,
        public array $issues = [],
        public array $changedFields = [],
        public ?int $changeHistoryId = null,
    ) {}
}

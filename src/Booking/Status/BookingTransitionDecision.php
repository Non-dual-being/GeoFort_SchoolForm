<?php
declare(strict_types=1);
namespace GeoFort\Booking\Status;

final readonly class BookingTransitionDecision
{
    public function __construct(
        public BookingTransitionCode $code,
        public bool $allowed,
        public bool $requiresCapacityCheck,
    ) {}
}

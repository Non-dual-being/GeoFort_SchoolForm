<?php
declare(strict_types=1);
namespace GeoFort\Booking\Status;

use GeoFort\Booking\BookingPolicy;

final class BookingStatusTransitionPolicy
{
    public function decide(string $current, string $target): BookingTransitionDecision
    {
        if (!BookingPolicy::isAllowedStatus($current)) return new BookingTransitionDecision(BookingTransitionCode::InvalidCurrentStatus, false, false);
        if (!BookingPolicy::isAllowedStatus($target)) return new BookingTransitionDecision(BookingTransitionCode::InvalidTargetStatus, false, false);
        if ($current === $target) return new BookingTransitionDecision(BookingTransitionCode::NoStatusChange, false, false);
        $requiresCapacity = $target === BookingPolicy::STATUS_CONFIRMED;
        return new BookingTransitionDecision(BookingTransitionCode::Allowed, true, $requiresCapacity);
    }
}

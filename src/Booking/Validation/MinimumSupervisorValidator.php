<?php

declare(strict_types=1);

namespace GeoFort\Booking\Validation;

use GeoFort\Booking\BookingPolicy;
use GeoFort\Booking\Stored\StoredBooking;

final class MinimumSupervisorValidator
{
    public function validate(StoredBooking $booking): ?StoredBookingIssue
    {
        if ($booking->studentCount === null || $booking->studentCount <= 0 || $booking->supervisorCount === null) {
            return null;
        }

        $minimum = BookingPolicy::getMinimumSupervisorCount($booking->studentCount);
        if ($booking->supervisorCount >= $minimum) {
            return null;
        }

        return new StoredBookingIssue(
            'MINIMUM_SUPERVISORS_NOT_MET',
            StoredBookingIssueCategory::Policy,
            'aantalBegeleiders',
            metadata: [
                'studentCount' => $booking->studentCount,
                'supervisorCount' => $booking->supervisorCount,
                'minimumSupervisors' => $minimum,
            ],
        );
    }
}

<?php

declare(strict_types=1);

namespace GeoFort\Booking\Validation;

use GeoFort\Booking\BookingPolicy;
use GeoFort\Booking\Stored\StoredBooking;

final class ProgramStudentLimitValidator
{
    public function validate(StoredBooking $booking): ?StoredBookingIssue
    {
        if (!BookingPolicy::isAllowedProgram($booking->program)) {
            return new StoredBookingIssue(
                'INVALID_CONFIGURATION_KEY',
                StoredBookingIssueCategory::Structural,
                'programma',
            );
        }

        if ($booking->studentCount === null) {
            return null;
        }

        $maximum = BookingPolicy::getMaxStudentsOfProgram($booking->program);
        if ($booking->studentCount <= $maximum) {
            return null;
        }

        return new StoredBookingIssue(
            'PROGRAM_STUDENT_LIMIT_EXCEEDED',
            StoredBookingIssueCategory::Policy,
            'aantalLeerlingen',
            metadata: [
                'studentCount' => $booking->studentCount,
                'maximumStudentsForProgram' => $maximum,
                'program' => $booking->program,
                'programLabel' => BookingPolicy::getProgramLabel($booking->program),
            ],
        );
    }
}

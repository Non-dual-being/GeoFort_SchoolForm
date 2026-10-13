<?php

declare(strict_types=1);

namespace GeoFort\Services\Dashboard\Roster;

use GeoFort\Booking\Stored\StoredBooking;

final class RosterSourceFingerprint
{
    public static function of(StoredBooking $booking): string
    {
        // Keep the existing field order/encoding: saved booking_locked hashes remain valid.
        return hash('sha256', json_encode([
            'visitDate' => $booking->visitDate,
            'schoolSector' => $booking->schoolSector,
            'program' => $booking->program,
            'choiceModuleKey' => $booking->choiceModuleKey,
            'studentCount' => $booking->studentCount,
            'supervisorCount' => $booking->supervisorCount,
            'educationSelection' => $booking->educationSelection->toArray(),
            ...($booking->programVersion === null ? [] : ['programVersion' => $booking->programVersion]),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }
}

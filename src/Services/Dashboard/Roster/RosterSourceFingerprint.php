<?php

declare(strict_types=1);

namespace GeoFort\Services\Dashboard\Roster;

use GeoFort\Booking\Stored\StoredBooking;

final class RosterSourceFingerprint
{
    public static function of(StoredBooking $booking): string
    {
        return self::fromContext(self::context($booking));
    }

    public static function context(StoredBooking $booking): array
    {
        return [
            'visitDate' => $booking->visitDate,
            'schoolSector' => $booking->schoolSector,
            'program' => $booking->program,
            'choiceModuleKey' => $booking->choiceModuleKey,
            'studentCount' => $booking->studentCount,
            'supervisorCount' => $booking->supervisorCount,
            'educationSelection' => $booking->educationSelection->toArray(),
            ...($booking->programVersion === null ? [] : ['programVersion' => $booking->programVersion]),
        ];
    }

    public static function fromContext(array $context): string
    {
        // Preserve the original key order and encoding, including the legacy omitted version.
        $ordered = [];
        foreach (['visitDate', 'schoolSector', 'program', 'choiceModuleKey', 'studentCount', 'supervisorCount', 'educationSelection'] as $key) $ordered[$key] = $context[$key];
        if (($context['programVersion'] ?? null) !== null) $ordered['programVersion'] = $context['programVersion'];
        return hash('sha256', json_encode($ordered, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }
}

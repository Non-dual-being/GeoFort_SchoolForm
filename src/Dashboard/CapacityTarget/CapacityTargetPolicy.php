<?php
declare(strict_types=1);

namespace GeoFort\Dashboard\CapacityTarget;

use DateTimeImmutable;

final class CapacityTargetPolicy
{
    public const MAX_STUDENTS = 160;
    public const MAX_BOOKINGS = 2.0;
    public const BOOKING_STEP = 0.1;
    public const MANAGEMENT_ROLES = ['admin', 'planner'];

    /** @return array<string, string> */
    public static function validate(string $effectiveDate, mixed $students, mixed $bookings, string $today): array
    {
        $issues = [];
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $effectiveDate);
        if ($date === false || $date->format('Y-m-d') !== $effectiveDate) {
            $issues['effectiveDate'] = 'Kies een geldige ingangsdatum.';
        } elseif ($effectiveDate < $today) {
            $issues['effectiveDate'] = 'Een target kan niet met terugwerkende kracht ingaan.';
        }
        if (!is_int($students) || $students < 0 || $students > self::MAX_STUDENTS) {
            $issues['studentsPerAvailableDay'] = 'Vul een geheel aantal leerlingen van 0 tot en met 160 in.';
        }
        if (!is_int($bookings) && !is_float($bookings)) {
            $issues['bookingsPerAvailableDay'] = 'Vul een aantal boekingen van 0 tot en met 2 in.';
        } else {
            $value = (float) $bookings;
            if (!is_finite($value) || $value < 0 || $value > self::MAX_BOOKINGS
                || abs($value * 10 - round($value * 10)) > 0.0000001) {
                $issues['bookingsPerAvailableDay'] = 'Vul een aantal boekingen van 0 tot en met 2 in, in stappen van 0,1.';
            }
        }
        return $issues;
    }

    public static function canManage(string $role): bool
    {
        return in_array($role, self::MANAGEMENT_ROLES, true);
    }
}

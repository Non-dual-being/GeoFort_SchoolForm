<?php

declare(strict_types=1);

namespace GeoFort\Booking\Validation;

use GeoFort\Booking\BookingProgramConfig;
use GeoFort\Validation\FieldValidationException;

final class StoredBookingStudentCountValidator
{
    public function validate(?int $studentCount, string $schoolSector, string $program): int
    {
        if ($studentCount === null || $studentCount <= 0) {
            throw new FieldValidationException(
                'aantalLeerlingen',
                'Het aantal leerlingen moet groter zijn dan 0.',
            );
        }

        $minimum = BookingProgramConfig::getMinStudentsForSelection($schoolSector, $program);
        if ($studentCount < $minimum) {
            throw new FieldValidationException(
                'aantalLeerlingen',
                "Voor dit programma geldt een minimum van {$minimum} leerlingen.",
            );
        }

        return $studentCount;
    }
}

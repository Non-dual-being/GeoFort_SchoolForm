<?php

declare(strict_types=1);

namespace GeoFort\Booking\Validation;

use GeoFort\Validation\FieldValidationException;

final class StoredBookingStudentCountValidator
{
    // Administrative planning deliberately has no commercial student limits.
    // Public submissions use GeoFort\Validation\StudentCountValidator instead.
    public function validate(mixed $studentCount, string $schoolSector, string $program): int
    {
        if (!is_int($studentCount) || $studentCount <= 0) {
            throw new FieldValidationException(
                'aantalLeerlingen',
                'Het aantal leerlingen moet groter zijn dan 0.',
            );
        }

        return $studentCount;
    }
}

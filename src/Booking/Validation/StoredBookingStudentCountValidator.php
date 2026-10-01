<?php

declare(strict_types=1);

namespace GeoFort\Booking\Validation;

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

        // Public booking minima do not apply to existing dashboard bookings.
        // Program compatibility and upper limits are validated separately.
        return $studentCount;
    }
}

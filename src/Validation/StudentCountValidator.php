<?php

declare(strict_types=1);

namespace GeoFort\Validation;

use GeoFort\Booking\BookingPolicy;
use GeoFort\Booking\BookingProgramConfig;

final class StudentCountValidator
{
    public function validate(
        mixed $rawValue,
        string $schoolSector,
        string $program,
    ): int {
        if (is_int($rawValue)) {
            $value = (string) $rawValue;
        } elseif (is_string($rawValue)) {
            $value = trim($rawValue);
        } else {
            throw new FieldValidationException(
                'aantalLeerlingen',
                'Vul een geldig aantal leerlingen in.',
            );
        }

        if ($value === '') {
            throw new FieldValidationException(
                'aantalLeerlingen',
                'Vul het aantal leerlingen in.',
            );
        }

        if (!preg_match('/^[0-9]+$/', $value)) {
            throw new FieldValidationException(
                'aantalLeerlingen',
                'Gebruik alleen cijfers voor het aantal leerlingen.',
            );
        }

        $studentCount = (int) $value;

        if ($studentCount <= 0) {
            throw new FieldValidationException(
                'aantalLeerlingen',
                'Het aantal leerlingen moet groter zijn dan 0.',
            );
        }

        $minStudents = BookingProgramConfig::getMinStudentsForSelection(
            schoolSector: $schoolSector,
            program: $program,
        );

        $maxStudents = BookingPolicy::getMaxStudentsOfProgram($program);

        if ($studentCount < $minStudents) {
            throw new FieldValidationException(
                'aantalLeerlingen',
                "Voor dit programma geldt een minimum van {$minStudents} leerlingen.",
            );
        }

        if ($studentCount > $maxStudents) {
            throw new FieldValidationException(
                'aantalLeerlingen',
                "Voor dit programma kunt u maximaal {$maxStudents} leerlingen aanmelden.",
            );
        }

        return $studentCount;
    }
}

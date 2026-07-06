<?php

declare(strict_types=1);

namespace GeoFort\Validation;

use GeoFort\Booking\BookingPolicy;

final class SupervisorCountValidator
{
    public function validate(mixed $rawValue, int $studentCount): int
    {
        if (is_int($rawValue)) {
            $value = (string) $rawValue;
        } elseif (is_string($rawValue)) {
            $value = trim($rawValue);
        } else {
            throw new FieldValidationException(
                'aantalBegeleiders',
                'Gebruik alleen cijfers voor het aantal begeleiders.',
            );
        }

        if ($value === '') {
            throw new FieldValidationException(
                'aantalBegeleiders',
                'Vul het aantal begeleiders in.',
            );
        }

        if (!preg_match('/^[0-9]+$/', $value)) {
            throw new FieldValidationException(
                'aantalBegeleiders',
                'Gebruik alleen cijfers voor het aantal begeleiders.',
            );
        }

        $supervisorCount = (int) $value;

        if ($supervisorCount <= 0) {
            throw new FieldValidationException(
                'aantalBegeleiders',
                'Het aantal begeleiders moet minimaal 1 zijn.',
            );
        }

        $minSupervisors = BookingPolicy::getMinimumSupervisorCount($studentCount);

        if ($supervisorCount < $minSupervisors) {
            throw new FieldValidationException(
                'aantalBegeleiders',
                "Bij {$studentCount} leerlingen zijn minimaal {$minSupervisors} begeleiders nodig.",
            );
        }

        if ($supervisorCount > BookingPolicy::MAX_SUPERVISORS_PER_BOOKING) {
            throw new FieldValidationException(
                'aantalBegeleiders',
                'Er kunnen maximaal 50 begeleiders worden opgegeven.',
            );
        }

        return $supervisorCount;
    }
}

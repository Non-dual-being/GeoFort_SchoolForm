<?php

declare(strict_types=1);

namespace GeoFort\Validation;

use DateTimeInterface;
use GeoFort\Booking\BookingProgramConfig;
use GeoFort\Validation\FieldValidationException;

final class ProgramSelectionValidator
{
    public function validate(
        string $program,
        string $schoolSector,
        DateTimeInterface $visitDate,
    ): string {
        if (!BookingProgramConfig::programExists($program)) {
            throw new FieldValidationException(
                'programma',
                'Kies een geldig programma.'
            );
        }

        $isoWeekday = (int) $visitDate->format('N');

        if (
            !BookingProgramConfig::isProgramAllowedForSelection(
                $program,
                $schoolSector,
                $isoWeekday,
            )
        ) {
            throw new FieldValidationException(
                'programma',
                'Het gekozen programma past niet bij de gekozen datum en onderwijssector.'
            );
        }

        return $program;
    }
}
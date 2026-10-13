<?php

declare(strict_types=1);

namespace GeoFort\Validation;

use GeoFort\Booking\FourModuleProgram;

final class ProgramVersionValidator
{
    public function forNewRequest(mixed $variant, string $program, string $visitDate): ?string
    {
        // A form opened before the update still represents the original offer.
        $variant ??= 'five';
        if (!in_array($variant, ['auto', 'four', 'five'], true)) {
            throw new FieldValidationException('programma', 'Kies een geldig programmaformulier.');
        }
        if ($program !== 'dag') return null;
        $version = $variant === 'five' ? null :
            (($variant === 'four' || FourModuleProgram::availableOn($visitDate)) ? FourModuleProgram::VERSION : null);
        $this->validate($version, $program, $visitDate);

        return $version;
    }

    public function validate(?string $version, string $program, string $visitDate): void
    {
        // Missing historical version means the original agreement, even in 2027.
        if ($version === null) return;
        if ($version !== FourModuleProgram::VERSION || $program !== 'dag') {
            throw new FieldValidationException('programma', 'Onbekende of niet passende programmaversie.');
        }
        if (!FourModuleProgram::availableOn($visitDate)) {
            throw new FieldValidationException('bezoekdatum', 'Het viermodulesprogramma is beschikbaar vanaf 4 januari 2027.');
        }
    }
}

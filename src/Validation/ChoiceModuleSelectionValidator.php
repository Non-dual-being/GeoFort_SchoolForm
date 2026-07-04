<?php

declare(strict_types=1);

namespace GeoFort\Validation;

use GeoFort\Booking\BookingProgramConfig;
use GeoFort\Services\Booking\Data\EducationSelectionData;

final class ChoiceModuleSelectionValidator
{
    public function validate(
        mixed $rawValue,
        string $schoolSector,
        string $program,
        EducationSelectionData $educationSelection,
    ): ?string {
        if ($rawValue === null) {
            $choiceModuleKey = '';
        } elseif (is_string($rawValue)) {
            $choiceModuleKey = trim($rawValue);
        } else {
            throw new FieldValidationException(
                'keuzemodule',
                'Kies een geldige keuzemodule.',
            );
        }

        $hasChoiceModules = BookingProgramConfig::hasChoiceModulesForSelection(
            $schoolSector,
            $program,
        );

        /**
         * Geen keuzemodules voor deze combinatie.
         *
         * Dan mag de frontend leeg sturen.
         * Stuurt iemand toch een module mee, dan is dat manipulatie.
         */
        if (!$hasChoiceModules) {
            if ($choiceModuleKey !== '') {
                throw new FieldValidationException(
                    'keuzemodule',
                    'Voor dit programma is geen keuzemodule beschikbaar.',
                );
            }

            return null;
        }

        /**
         * Er zijn wel keuzemodules.
         * Dan is exact één keuze verplicht.
         */
        if ($choiceModuleKey === '') {
            throw new FieldValidationException(
                'keuzemodule',
                'Kies een keuzemodule.',
            );
        }

        /**
         * De gekozen waarde moet in de keuze-array staan.
         * Standaardmodules mogen hier dus niet gekozen worden.
         */
        if (
            !BookingProgramConfig::isChoiceModuleConfiguredForSelection(
                moduleKey: $choiceModuleKey,
                schoolSector: $schoolSector,
                program: $program,
            )
        ) {
            throw new FieldValidationException(
                'keuzemodule',
                'De gekozen keuzemodule is niet beschikbaar voor deze onderwijssector en dit programma.',
            );
        }

        /**
         * De module moet ook passen bij de gekozen niveaus/groepen.
         */
        if (
            !BookingProgramConfig::isChoiceModuleAllowedForEducationSelection(
                moduleKey: $choiceModuleKey,
                schoolSector: $schoolSector,
                educationSelection: $educationSelection,
            )
        ) {
            throw new FieldValidationException(
                'keuzemodule',
                'De gekozen keuzemodule past niet bij de gekozen onderwijsniveaus of groepen.',
            );
        }

        return $choiceModuleKey;
    }
}
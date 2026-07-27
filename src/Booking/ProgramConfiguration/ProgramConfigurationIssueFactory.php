<?php
declare(strict_types=1);

namespace GeoFort\Booking\ProgramConfiguration;

use GeoFort\Booking\BookingProgramConfig;
use GeoFort\Booking\Validation\StoredBookingIssue;
use GeoFort\Booking\Validation\StoredBookingIssueCategory;

final class ProgramConfigurationIssueFactory
{
    /** @param array<string, bool|int|string|null> $metadata */
    public static function create(string $code, string $field, array $metadata = [], StoredBookingIssueCategory $category = StoredBookingIssueCategory::Policy): StoredBookingIssue
    {
        [$title, $description] = match ($code) {
            'LEVEL_SELECTION_LIMIT_EXCEEDED' => [
                'Te veel niveaus geselecteerd',
                sprintf('Voor %s kunt u maximaal %d niveaus selecteren.', self::sectorName($metadata['sector'] ?? ''), $metadata['maximum'] ?? 0),
            ],
            'GROUP_SELECTION_LIMIT_EXCEEDED' => [
                'Te veel groepen geselecteerd',
                sprintf('Binnen %s kunt u maximaal %d groepen selecteren.', self::levelName($metadata['sector'] ?? '', $metadata['level'] ?? ''), $metadata['maximum'] ?? 0),
            ],
            'MISSING_CHOICE_MODULE' => [
                'Keuzemodule ontbreekt',
                'Kies een keuzemodule die past bij het programma en de onderwijsselectie.',
            ],
            'INVALID_CHOICE_MODULE' => [
                'Keuzemodule niet beschikbaar',
                'De gekozen keuzemodule past niet bij het programma, de sector of de geselecteerde groepen.',
            ],
            'UNKNOWN_EDUCATION_LEVEL' => ['Onbekend onderwijsniveau', 'Een geselecteerd onderwijsniveau hoort niet bij deze schoolsector.'],
            'UNKNOWN_EDUCATION_GROUP' => ['Onbekende groep', 'Een geselecteerde groep hoort niet bij het gekozen onderwijsniveau.'],
            'GROUP_WITHOUT_SELECTED_LEVEL' => ['Groep zonder niveau', 'Verwijder groepen van onderwijsniveaus die niet zijn geselecteerd.'],
            'INVALID_EDUCATION_SELECTION' => ['Onderwijsselectie ongeldig', 'Controleer de geselecteerde niveaus en groepen.'],
            'INVALID_STUDENT_COUNT' => ['Leerlingenaantal ongeldig', 'Controleer het leerlingenaantal voor het gekozen programma.'],
            'INVALID_STATUS' => ['Status niet wijzigbaar', 'De programmaconfiguratie kan in de huidige status niet worden bijgewerkt.'],
            'INVALID_CONFIGURATION_KEY' => ['Onbekende configuratiewaarde', 'Een voorgestelde waarde komt niet voor in de centrale programmaconfiguratie.'],
            'INVALID_VISIT_DATE' => ['Bezoekdatum ongeldig', 'De opgeslagen bezoekdatum kan niet worden gebruikt om deze configuratie te valideren.'],
            default => ['Programmaconfiguratie ongeldig', 'Controleer de gemarkeerde velden voordat u verdergaat.'],
        };

        return new StoredBookingIssue(
            $code,
            $category,
            $field,
            title: $title,
            description: $description,
            metadata: $metadata,
        );
    }

    private static function sectorName(mixed $sector): string
    {
        return is_string($sector) && isset(BookingProgramConfig::SCHOOL_TYPES_BY_KEY[$sector]['label'])
            ? strtolower((string) BookingProgramConfig::SCHOOL_TYPES_BY_KEY[$sector]['label'])
            : 'deze onderwijssector';
    }

    private static function levelName(mixed $sector, mixed $level): string
    {
        return is_string($sector) && is_string($level) && isset(BookingProgramConfig::SCHOOL_LEVELS[$sector][$level]['label'])
            ? (string) BookingProgramConfig::SCHOOL_LEVELS[$sector][$level]['label']
            : 'dit onderwijsniveau';
    }
}

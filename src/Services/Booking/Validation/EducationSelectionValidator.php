<?php

declare(strict_types=1);

namespace GeoFort\Services\Booking\Validation;

use GeoFort\Booking\BookingProgramConfig;
use GeoFort\Services\Booking\Data\EducationSelectionData;
use GeoFort\Validation\FieldValidationException;
use JsonException;

final class EducationSelectionValidator
{
    public function validate(
        mixed $rawValue,
        string $expectedSector,
    ): EducationSelectionData {
        // De frontend stuurt educationSelection als JSON-string;
        // deze wordt eerst omgezet naar een veilige PHP-array.
        $payload = $this->decodePayload($rawValue);

        $sector = $this->readString($payload, 'sector', 'educationSelection');

        // Voorkomt dat oude of gemanipuleerde frontend-data
        // bij een andere sector wordt opgeslagen.
        if ($sector !== $expectedSector) {
            throw new FieldValidationException(
                'educationSelection',
                'De onderwijsselectie komt niet overeen met de gekozen onderwijssector.',
            );
        }

        if (!BookingProgramConfig::isValidSchoolSectorValue($sector)) {
            throw new FieldValidationException(
                'educationSelection',
                'Ongeldige onderwijssector in de groepssamenstelling.',
            );
        }

        // Vanaf hier hoort de sector geldig te zijn;
        // ontbrekende config is dus een programmeerfout.
        $schoolLevels = BookingProgramConfig::SCHOOL_LEVELS[$sector] ?? null;
        $rules = BookingProgramConfig::SCHOOL_LEVEL_SELECTION_RULES[$sector] ?? null;

        if (!is_array($schoolLevels) || !is_array($rules)) {
            throw new \LogicException(
                "Ontbrekende onderwijsconfiguratie voor sector: {$sector}",
            );
        }

        $selectedLevels = $this->readStringList(
            $payload,
            'selectedLevels',
            'educationSelection',
        );

        // Dubbele levels uit gemanipuleerde payloads tellen maar één keer mee.
        $selectedLevels = $this->uniqueList($selectedLevels);

        // Controleer eerst het aantal gekozen onderwijsniveaus.
        $this->validateLevelCount($selectedLevels, $rules);

        // Controleer daarna of elk gekozen level bestaat binnen deze sector.
        $this->validateLevelsExist($selectedLevels, $schoolLevels);

        $rawGroupsByLevel = $this->readArray(
            $payload,
            'selectedGroupsByLevel',
            'educationSelection',
        );

        // Valideer en normaliseer de groepen per gekozen onderwijsniveau.
        $selectedGroupsByLevel = $this->validateGroupsByLevel(
            selectedLevels: $selectedLevels,
            rawGroupsByLevel: $rawGroupsByLevel,
            schoolLevels: $schoolLevels,
            rules: $rules,
        );

        return new EducationSelectionData(
            sector: $sector,
            selectedLevels: $selectedLevels,
            selectedGroupsByLevel: $selectedGroupsByLevel,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function decodePayload(mixed $rawValue): array
    {
        if (!is_string($rawValue) || trim($rawValue) === '') {
            throw new FieldValidationException(
                'educationSelection',
                'Kies minimaal één onderwijsniveau en bijbehorende groep.',
            );
        }

        try {
            $payload = json_decode($rawValue, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new FieldValidationException(
                'educationSelection',
                'Ongeldige onderwijsselectie ontvangen.',
            );
        }

        if (!is_array($payload)) {
            throw new FieldValidationException(
                'educationSelection',
                'Ongeldige onderwijsselectie ontvangen.',
            );
        }

        return $payload;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function readString(
        array $payload,
        string $key,
        string $field,
    ): string {
        $value = $payload[$key] ?? null;

        if (!is_string($value) || trim($value) === '') {
            throw new FieldValidationException(
                $field,
                "Ontbrekende waarde voor {$key}.",
            );
        }

        return trim($value);
    }

    /**
     * @param array<string, mixed> $payload
     * @return mixed[]
     */
    private function readArray(
        array $payload,
        string $key,
        string $field,
    ): array {
        $value = $payload[$key] ?? null;

        if (!is_array($value)) {
            throw new FieldValidationException(
                $field,
                "Ongeldige waarde voor {$key}.",
            );
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $payload
     * @return string[]
     */
    private function readStringList(
        array $payload,
        string $key,
        string $field,
    ): array {
        $value = $this->readArray($payload, $key, $field);

        foreach ($value as $item) {
            if (!is_string($item) || trim($item) === '') {
                throw new FieldValidationException(
                    $field,
                    "Ongeldige waarde in {$key}.",
                );
            }
        }

        // Trim alle strings en herstel normale numerieke array-indexen.
        return array_values(array_map('trim', $value));
    }

    /**
     * @param string[] $values
     * @return string[]
     */
    private function uniqueList(array $values): array
    {
        // Verwijder dubbele waarden en herstel normale numerieke array-indexen.
        return array_values(array_unique($values));
    }

    /**
     * @param string[] $selectedLevels
     * @param array<string, int> $rules
     */
    private function validateLevelCount(array $selectedLevels, array $rules): void
    {
        $count = count($selectedLevels);

        // De min/max-regels komen uit de sectorconfiguratie.
        $min = (int) $rules['minLevels'];
        $max = (int) $rules['maxLevels'];

        if ($count < $min) {
            throw new FieldValidationException(
                'educationSelection',
                $min === 1
                    ? 'Kies minimaal één onderwijsniveau.'
                    : "Kies minimaal {$min} onderwijsniveaus.",
            );
        }

        if ($count > $max) {
            throw new FieldValidationException(
                'educationSelection',
                $max === 1
                    ? 'Er kan maximaal één onderwijsniveau worden gekozen.'
                    : "Er kunnen maximaal {$max} onderwijsniveaus worden gekozen.",
            );
        }
    }

    /**
     * @param string[] $selectedLevels
     * @param array<string, mixed> $schoolLevels
     */
    private function validateLevelsExist(
        array $selectedLevels,
        array $schoolLevels,
    ): void {
        foreach ($selectedLevels as $levelKey) {
            // Elk gekozen level moet bestaan binnen de sectorconfiguratie.
            if (!array_key_exists($levelKey, $schoolLevels)) {
                throw new FieldValidationException(
                    'educationSelection',
                    'Er is een onderwijsniveau gekozen dat niet bij deze sector hoort.',
                );
            }
        }
    }

    /**
     * @param string[] $selectedLevels
     * @param array<string, mixed> $rawGroupsByLevel
     * @param array<string, array{label: string, groups: array<string, string>}> $schoolLevels
     * @param array<string, int> $rules
     * @return array<string, string[]>
     */
    private function validateGroupsByLevel(
        array $selectedLevels,
        array $rawGroupsByLevel,
        array $schoolLevels,
        array $rules,
    ): array {
        // Voorbeeld vóór array_flip:
        // ['havo', 'vwo']
        //
        // Eigenlijk is dat:
        // [
        //     0 => 'havo',
        //     1 => 'vwo',
        // ]
        //
        // Na array_flip wordt dat:
        // [
        //     'havo' => 0,
        //     'vwo' => 1,
        // ]
        //
        // De 0 en 1 zijn dus de oude indexen.
        // De waarden zelf zijn hier niet belangrijk; we gebruiken alleen de keys.
        $selectedLevelSet = array_flip($selectedLevels);

        // Hier bouwen we de definitieve, opgeschoonde groepsselectie op.
        $normalizedGroupsByLevel = [];

        // Controleer eerst op extra of verdachte data
        // die niet bij de gekozen levels hoort.
        foreach ($rawGroupsByLevel as $levelKey => $groups) {
            if (!is_string($levelKey)) {
                throw new FieldValidationException(
                    'educationSelection',
                    'Ongeldige groepsselectie ontvangen.',
                );
            }

            if (!is_array($groups)) {
                throw new FieldValidationException(
                    'educationSelection',
                    'Ongeldige groepsselectie ontvangen.',
                );
            }

            // Maak van de groepselectie een schone stringlijst zonder dubbele waarden.
            $groups = $this->uniqueListOfStrings($groups);

            // Groepen bij een level dat niet in de sectorconfiguratie bestaat, zijn ongeldig.
            if (!array_key_exists($levelKey, $schoolLevels) && count($groups) > 0) {
                throw new FieldValidationException(
                    'educationSelection',
                    'Er zijn groepen meegestuurd voor een onbekend onderwijsniveau.',
                );
            }

            // Een level mag alleen groepen bevatten als dat level ook echt geselecteerd is.
            if (!array_key_exists($levelKey, $selectedLevelSet) && count($groups) > 0) {
                throw new FieldValidationException(
                    'educationSelection',
                    'Er zijn groepen gekozen bij een onderwijsniveau dat niet geselecteerd is.',
                );
            }
        }

        // Valideer daarna per geselecteerd level de gekozen groepen
        // en neem alleen deze opgeschoonde data mee naar de DTO.
        foreach ($selectedLevels as $levelKey) {
            // Ontbrekende groepsdata wordt een lege lijst;
            // de min/max-validatie vangt dat daarna af.
            $groups = $this->uniqueListOfStrings(
                $rawGroupsByLevel[$levelKey] ?? [],
            );

            $this->validateGroupCount($groups, $rules);

            $this->validateGroupsExistForLevel(
                levelKey: $levelKey,
                selectedGroups: $groups,
                schoolLevels: $schoolLevels,
            );

            $normalizedGroupsByLevel[$levelKey] = $groups;
        }

        return $normalizedGroupsByLevel;
    }

    /**
     * @param mixed[] $values
     * @return string[]
     */
    private function uniqueListOfStrings(array $values): array
    {
        // Normaliseer groepskeys: alleen niet-lege strings zijn toegestaan.
        $out = [];

        foreach ($values as $value) {
            if (!is_string($value) || trim($value) === '') {
                throw new FieldValidationException(
                    'educationSelection',
                    'Ongeldige groep of leerjaar ontvangen.',
                );
            }

            $out[] = trim($value);
        }

        // Verwijder dubbele groepen en herstel normale numerieke array-indexen.
        return array_values(array_unique($out));
    }

    /**
     * @param string[] $groups
     * @param array<string, int> $rules
     */
    private function validateGroupCount(
        array $groups,
        array $rules,
    ): void {
        $count = count($groups);

        // De min/max-regels komen uit de sectorconfiguratie.
        $min = (int) $rules['minGroupsPerLevel'];
        $max = (int) $rules['maxGroupsPerLevel'];

        if ($count < $min) {
            throw new FieldValidationException(
                'educationSelection',
                'Kies minimaal één groep of leerjaar bij elk gekozen onderwijsniveau.',
            );
        }

        if ($count > $max) {
            throw new FieldValidationException(
                'educationSelection',
                $max === 1
                    ? 'Er kan maximaal één groep of leerjaar per onderwijsniveau worden gekozen.'
                    : "Er kunnen maximaal {$max} groepen of leerjaren per onderwijsniveau worden gekozen.",
            );
        }
    }

    /**
     * @param string[] $selectedGroups
     * @param array<string, array{label: string, groups: array<string, string>}> $schoolLevels
     */
    private function validateGroupsExistForLevel(
        string $levelKey,
        array $selectedGroups,
        array $schoolLevels,
    ): void {
        // Haal de toegestane groepen op voor precies dit onderwijsniveau.
        $allowedGroups = $schoolLevels[$levelKey]['groups'] ?? null;

        if (!is_array($allowedGroups)) {
            throw new \LogicException(
                "Ontbrekende groepenconfiguratie voor level: {$levelKey}",
            );
        }

        foreach ($selectedGroups as $groupKey) {
            // Elke gekozen groep moet bestaan binnen de configuratie van dit level.
            if (!array_key_exists($groupKey, $allowedGroups)) {
                throw new FieldValidationException(
                    'educationSelection',
                    'Er is een groep of leerjaar gekozen dat niet bij dit onderwijsniveau hoort.',
                );
            }
        }
    }
}
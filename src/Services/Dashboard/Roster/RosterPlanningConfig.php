<?php

declare(strict_types=1);

namespace GeoFort\Services\Dashboard\Roster;

use GeoFort\Booking\BookingProgramConfig;

final class RosterPlanningConfig
{
    /**
     * Rustige, lichte modulekleuren. De frontend berekent zelf een contrasterende
     * tekstkleur wanneer later een donkerdere beheerkleur wordt gekozen.
     */
    private const COLORS = [
        'Zandtafel' => '#CFE2FF',
        'Rising-Risk' => '#CDEBE7',
        'Dynamische-Globe' => '#BFDDEA',
        'Dynamische-Globe-Bios' => '#C9D8F4',
        'Expedition-Earth' => '#D5EACB',
        'Klimaat-Experience' => '#F6D979',
        'Klimparcours' => '#B8DDA8',
        'Voedsel-Innovatie' => '#BFDDB0',
        'Minecraft-Klimaatspeurtocht' => '#D9B5DB',
        'Earth-Watch' => '#B9DED8',
        'Stop-de-Klimaat-Klok' => '#F2C7A7',
        'Minecraft-Programmeren' => '#C7C3EE',
        'Minecraft-Windenergiespeurtocht' => '#C8C4EE',
        'Crisismanagement' => '#E5C3CF',
    ];

    private const DEFAULT_LOCATIONS = [
        'Klimaat-Experience' => 'Forteiland rond Gebouw A',
        'Klimparcours' => 'Vleermuizen Speurtuin',
        'Voedsel-Innovatie' => 'Auditorium',
        'Dynamische-Globe' => 'Gebouw B - Buskruitbioscoop / Tossing & Turning',
        'Minecraft-Klimaatspeurtocht' => 'Minecraftzaal / Virtual Flowzaal',
        'Minecraft-Windenergiespeurtocht' => 'Minecraftzaal / Virtual Flowzaal',
        'Minecraft-Programmeren' => 'Minecraftzaal / Virtual Flowzaal',
        'Earth-Watch' => 'Bus / Building Blocks Dome',
        'Stop-de-Klimaat-Klok' => 'Climate Dome',
        'Crisismanagement' => 'Gebouw B - Virtual Flowzaal / Powerhousezaal',
    ];

    private const PARALLEL_EXCEPTIONS = [
        'Klimaat-Experience' => 3,
        'Klimparcours' => 3,
    ];

    /** @return list<string> */
    public function requiredModuleKeys(string $sector, string $program, ?string $choiceModule): array
    {
        $configuration = BookingProgramConfig::MODULES[$sector][$program] ?? null;
        if (!is_array($configuration)) {
            return [];
        }

        $modules = array_values(array_filter(
            $configuration['standaard'] ?? [],
            static fn (mixed $value): bool => is_string($value) && $value !== '',
        ));

        // Een concrete opgeslagen aanvraag blijft de bron voor historische
        // keuzemodules, ook wanneer de publieke configuratie later verandert.
        if (
            $choiceModule !== null
            && trim($choiceModule) !== ''
            && !in_array($choiceModule, $modules, true)
        ) {
            $modules[] = $choiceModule;
        }

        return $modules;
    }

    /** @return list<array<string,mixed>> */
    public function moduleOptions(string $sector, string $program, ?string $choiceModule): array
    {
        return array_map(
            fn (string $moduleKey): array => $this->moduleOption($moduleKey),
            $this->requiredModuleKeys($sector, $program, $choiceModule),
        );
    }

    /** @return array<string,mixed> */
    public function moduleOption(string $moduleKey): array
    {
        return [
            'key' => $moduleKey,
            'label' => BookingProgramConfig::MODULE_LABELS[$moduleKey] ?? $moduleKey,
            'color' => self::COLORS[$moduleKey] ?? '#E2E8F0',
            'defaultLocation' => self::DEFAULT_LOCATIONS[$moduleKey] ?? null,
            'maxParallel' => $this->maxParallel($moduleKey),
            'minimumGeoFortStaff' => $this->minimumGeoFortStaff($moduleKey),
            'schoolSupervisionAllowed' => $this->schoolSupervisionAllowed($moduleKey),
        ];
    }

    public function maxParallel(string $moduleKey): int
    {
        return min(3, self::PARALLEL_EXCEPTIONS[$moduleKey] ?? 2);
    }

    /**
     * Huidige vijfmodulevariant: KE en Klimparcours kunnen door schoolbegeleiding
     * worden overgenomen. In de toekomstige 4-modulepolicy kan dit per versie
     * strenger worden gemaakt zonder deze legacyregel te overschrijven.
     */
    public function minimumGeoFortStaff(string $moduleKey): int
    {
        return $this->schoolSupervisionAllowed($moduleKey) ? 0 : 1;
    }

    public function schoolSupervisionAllowed(string $moduleKey): bool
    {
        return in_array($moduleKey, ['Klimaat-Experience', 'Klimparcours'], true);
    }
}

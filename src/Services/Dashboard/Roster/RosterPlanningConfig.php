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
        'Zandtafel' => '#F6E3A4',
        'Rising-Risk' => '#CDEBE7',
        'Dynamische-Globe' => '#BFDDEA',
        'Dynamische-Globe-Bios' => '#BCC8E8',
        'Expedition-Earth' => '#E9C8B5',
        'Klimaat-Experience' => '#F6D979',
        'Klimparcours' => '#B8DDA8',
        'Voedsel-Innovatie' => '#EADCF8',
        'Minecraft-Klimaatspeurtocht' => '#D9B5DB',
        'Earth-Watch' => '#D9EAD3',
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
        'Klimaat-Experience' => 2,
        'Klimparcours' => 3,
    ];

    /** Teaching families do not merge the separate morning activity capacities. */
    public static function teachingFamily(string $module): string
    {
        return match ($module) {
            'Zandtafel', 'Rising-Risk', 'Expedition-Earth' => 'Klimaat-Experience',
            'Dynamische-Globe-Bios' => 'Dynamische-Globe',
            default => $module,
        };
    }

    /** @param list<string> $keys @return list<string> */
    public static function qualifiedModuleKeys(array $keys): array
    {
        $families = array_map(self::teachingFamily(...), $keys);
        return array_values(array_unique([...$keys, ...array_filter(array_keys(self::COLORS),
            static fn (string $key): bool => in_array(self::teachingFamily($key), $families, true))]));
    }

    /** Derived roster skills only; never save these aliases to the staff catalog. */
    public static function rosterPreferences(array $preferences): array
    {
        $ranks = [];
        foreach ($preferences as $p) foreach (self::qualifiedModuleKeys([$p['moduleKey']]) as $key) {
            $ranks[$key] = min($ranks[$key] ?? PHP_INT_MAX, (int) $p['rank']);
        }
        $result = [];
        foreach ($ranks as $key => $rank) $result[] = ['moduleKey' => $key, 'rank' => $rank];
        return $result;
    }

    /** Each activity uses its own station; both Globe variants share two places.
     * Two simultaneous KE groups reserve all three related morning stations.
     * @return array<string,int> Resource key => maximum concurrent groups.
     */
    public function capacityPools(string $module): array
    {
        $morning = ['Zandtafel', 'Rising-Risk', 'Expedition-Earth'];
        if (in_array($module, $morning, true)) return [$module => 1, 'KE/' . $module => 2];
        if ($module === 'Klimaat-Experience') return [
            $module => 2, 'KE/Zandtafel' => 2, 'KE/Rising-Risk' => 2, 'KE/Expedition-Earth' => 2,
        ];
        if ($module === 'Dynamische-Globe-Bios') return ['Dynamische-Globe' => 2];
        return [$module => $this->maxParallel($module)];
    }

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

    /**
     * Maximale groepsgrootte van een gedeelde roostersessie.
     *
     * VS blijft voorlopig een enkelgroepssessie: daarvoor is nog geen
     * expliciete dubbelgroep-policy afgesproken.
     */
    public function maxGroupsPerSession(string $moduleKey): int
    {
        return in_array($moduleKey, [
            'Voedsel-Innovatie',
            'Dynamische-Globe',
            'Klimaat-Experience',
            'Stop-de-Klimaat-Klok',
            'Earth-Watch',
            'Minecraft-Klimaatspeurtocht',
            'Minecraft-Programmeren',
            'Minecraft-Windenergiespeurtocht',
        ], true) ? 2 : 1;
    }

    /**
     * Prioriteit voor 1 begeleider op 2 groepen.
     * Lager nummer = eerder inzetten wanneer personeelsdruk dit nodig maakt.
     *
     * VI en DG staan hier niet in:
     * - VI: 1 kok + 1 begeleider voor maximaal 2 groepen.
     * - DG: bij 2 groepen zijn altijd 2 begeleiders nodig.
     */
    public function sharedGuidePriority(string $moduleKey): ?int
    {
        return match ($moduleKey) {
            'Klimaat-Experience' => 1,
            'Stop-de-Klimaat-Klok' => 2,
            'Earth-Watch' => 3,
            'Minecraft-Klimaatspeurtocht',
            'Minecraft-Programmeren',
            'Minecraft-Windenergiespeurtocht' => 4,
            default => null,
        };
    }

    /**
     * Welke modules mogen bij het huidige zoekniveau daadwerkelijk twee
     * groepen in een sessie samenbrengen?
     *
     * VI en DG mogen altijd dubbel worden aangeboden.
     * De overige modules volgen de afgesproken compressievolgorde:
     * KE -> Stop -> Earth Watch -> Minecraft.
     */
    public function effectiveMaxGroupsForCompressionLevel(
        string $moduleKey,
        int $compressionLevel,
    ): int {
        $maximum = $this->maxGroupsPerSession($moduleKey);
        if ($maximum <= 1) {
            return 1;
        }

        if (in_array($moduleKey, ['Voedsel-Innovatie', 'Dynamische-Globe'], true)) {
            return $maximum;
        }

        $priority = $this->sharedGuidePriority($moduleKey);
        if ($priority === null) {
            return 1;
        }

        return $priority <= $compressionLevel ? $maximum : 1;
    }

    /**
     * Harde GeoFort-bezetting voor een echte sessie, dus na het samenvoegen
     * van groepen.
     */
    public function requiredGeoFortGuidesForSession(
        string $moduleKey,
        int $groupCount,
        ?bool $schoolSupervisionAllowed = null,
    ): int {
        $groupCount = max(1, min($groupCount, $this->maxGroupsPerSession($moduleKey)));
        $schoolSupervisionAllowed = $this->schoolSupervisionAllowed($moduleKey) && ($schoolSupervisionAllowed ?? true);

        if ($schoolSupervisionAllowed) {
            return 0;
        }

        return match ($moduleKey) {
            // Twee groepen VI = kok + precies één begeleider.
            'Voedsel-Innovatie' => 1,

            // DG kan dubbel worden aangeboden, maar twee groepen vragen
            // daadwerkelijk twee begeleiders.
            'Dynamische-Globe' => $groupCount,

            // Bij deze modules mag één begeleider maximaal twee groepen
            // tegelijk begeleiden. De generator gebruikt deze mogelijkheid
            // in de volgorde KE -> Stop -> Earth Watch -> Minecraft.
            'Klimaat-Experience',
            'Stop-de-Klimaat-Klok',
            'Earth-Watch',
            'Minecraft-Klimaatspeurtocht',
            'Minecraft-Programmeren',
            'Minecraft-Windenergiespeurtocht' => 1,

            default => $groupCount,
        };
    }

    /**
     * Harde modulecapaciteit per tijdvak, uitgedrukt in AANTAL GROEPEN.
     *
     * Standaard mogen maximaal twee groepen tegelijk dezelfde module volgen.
     * Alleen Klimaat Experience en Vleermuizen Speurtuin mogen bij hoge
     * uitzondering drie groepen tegelijk hebben.
     */
    public function maxParallel(string $moduleKey): int
    {
        if (in_array($moduleKey, ['Zandtafel', 'Rising-Risk', 'Expedition-Earth'], true)) return 1;
        return min(3, self::PARALLEL_EXCEPTIONS[$moduleKey] ?? 2);
    }

    /**
     * KE en Vleermuizen Speurtuin staan School toe bij ieder moduleaantal.
     * Het argument blijft bestaan voor bestaande callers; er is één centrale policy.
     */
    public function schoolSupervisionAllowedForModuleCount(
        string $moduleKey,
        int $moduleCount,
    ): bool {
        return $this->schoolSupervisionAllowed($moduleKey);
    }

    /**
     * Alleen KE en Klimparcours kunnen door schoolbegeleiding worden overgenomen.
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

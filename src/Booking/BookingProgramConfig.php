<?php

declare(strict_types=1);

namespace GeoFort\Booking;

use GeoFort\Services\Booking\Data\EducationSelectionData;
use InvalidArgumentException;
use LogicException;
use GeoFort\Services\Booking\Pricing\BookingPriceCatalogRegistry;

final class BookingProgramConfig
{
    public static function priceCents(string $key): int
    {
        return (new BookingPriceCatalogRegistry())->active()->cateringPriceCents($key);
    }

    /** @return array<string, mixed> Presentation-only euro values for the existing public information contract. */
    private static function pricesForFrontend(): array
    {
        $catalog=(new BookingPriceCatalogRegistry())->active();
        $visit=[];
        foreach($catalog->visitPricesCents() as $program=>$prices)foreach($prices as $type=>$cents)$visit[$program][$type]=$cents/100;
        $catering=$catalog->cateringPricesCents();
        $euros=static fn(array $keys):array=>array_reduce($keys,static function(array $result,string $key)use($catering):array{$result[$key]=$catering[$key]/100;return $result;},[]);
        return ['bezoek'=>$visit,'snacks'=>$euros(['remise_break','kazerne_break','fortgracht_break','glas_limonade','waterijsje']),'lunch'=>$euros(['remise_lunch','eigen_picknick'])];
    }
    /**
     * Onderwijssectoren keyed by technische frontend/backend value.
     *
     * Deze keys moeten overeenkomen met:
     * - frontend SchoolSectorKey
     * - FormRules::RULES['onderwijsSector']
     * - databasewaarde onderwijs_sector
     */
    public const SCHOOL_TYPES_BY_KEY = [
        'primairOnderwijs' => [
            'label' => 'Primair onderwijs',
            'roosterType' => 'primair',
            'priceType' => 'basis',
            'category' => 'basis',
        ],
        'voortgezetOnderbouw' => [
            'label' => 'Voortgezet onderwijs onderbouw',
            'roosterType' => 'onderbouw',
            'priceType' => 'voortgezet',
            'category' => 'voortgezet',
        ],
        'voortgezetBovenbouw' => [
            'label' => 'Voortgezet onderwijs bovenbouw',
            'roosterType' => 'bovenbouw',
            'priceType' => 'voortgezet',
            'category' => 'voortgezet',
        ],
    ];

    /**
     * Backwards-compatible alias voor bestaande code die nog SCHOOL_TYPES gebruikt.
     * Nieuwe code gebruikt bij voorkeur SCHOOL_TYPES_BY_KEY.
     */
    public const SCHOOL_TYPES = self::SCHOOL_TYPES_BY_KEY;

    public const PROGRAMS = [
        'ochtend' => [
            'label' => 'Ochtendprogramma',
            'beginTijd' => '10:00',
            'eindTijd' => '12:15',
            'duurLesmodule' => '20 minuten',
            'allowedSchoolTypes' => ['primairOnderwijs'],
            'allowedWeekdays' => [3],
            'description' => [
                'Alleen op woensdagochtend mogelijk.',
                'Duur van het programma: 10:00 tot 12:15 uur.',
                'Korte lesmodules van 20 minuten per onderdeel.',
            ],
        ],
        'dag' => [
            'label' => 'Dagprogramma',
            'beginTijd' => '10:00',
            'eindTijd' => '15:00',
            'duurLesmodule' => '45 minuten',
            'allowedSchoolTypes' => [
                'primairOnderwijs',
                'voortgezetOnderbouw',
                'voortgezetBovenbouw',
            ],
            'allowedWeekdays' => [1, 2, 3, 4, 5],
            'description' => [
                'Van maandag t/m vrijdag buiten de schoolvakanties.',
                'Duur van het programma: 10:00 tot 15:00 uur.',
                'Lesmodules van 45 minuten.',
            ],
        ],
    ];

    private const PRIMARY_GROUPS = [
        'groep5' => 'Groep 5',
        'groep6' => 'Groep 6',
        'groep7' => 'Groep 7',
        'groep8' => 'Groep 8',
    ];

    public const SCHOOL_LEVELS = [
        'primairOnderwijs' => [
            'regulier' => [
                'label' => 'Regulier basisonderwijs',
                'groups' => self::PRIMARY_GROUPS,
            ],
            'speciaal' => [
                'label' => 'Speciaal basisonderwijs',
                'groups' => self::PRIMARY_GROUPS,
            ],
        ],

        'voortgezetOnderbouw' => [
            'vmboBasisKader' => [
                'label' => 'VMBO basis/kader',
                'groups' => [
                    'vmbo1' => 'VMBO 1',
                    'vmbo2' => 'VMBO 2',
                    'vmbo3' => 'VMBO 3',
                ],
            ],
            'vmboGemengdTheoretisch' => [
                'label' => 'VMBO gemengd/theoretisch',
                'groups' => [
                    'vmbo1' => 'VMBO 1',
                    'vmbo2' => 'VMBO 2',
                    'vmbo3' => 'VMBO 3',
                ],
            ],
            'havo' => [
                'label' => 'HAVO',
                'groups' => [
                    'havo1' => 'HAVO 1',
                    'havo2' => 'HAVO 2',
                    'havo3' => 'HAVO 3',
                ],
            ],
            'vwo' => [
                'label' => 'VWO',
                'groups' => [
                    'atheneum1' => 'Atheneum 1',
                    'atheneum2' => 'Atheneum 2',
                    'atheneum3' => 'Atheneum 3',
                    'gymnasium1' => 'Gymnasium 1',
                    'gymnasium2' => 'Gymnasium 2',
                    'gymnasium3' => 'Gymnasium 3',
                ],
            ],
            'praktijkOnderwijs' => [
                'label' => 'Praktijkonderwijs',
                'groups' => [
                    'praktijk1' => 'Praktijkonderwijs 1',
                    'praktijk2' => 'Praktijkonderwijs 2',
                    'praktijk3' => 'Praktijkonderwijs 3',
                ],
            ],
        ],

        'voortgezetBovenbouw' => [
            'vmbo' => [
                'label' => 'VMBO',
                'groups' => [
                    'vmbo4' => 'VMBO 4',
                ],
            ],
            'havo' => [
                'label' => 'HAVO',
                'groups' => [
                    'havo4' => 'HAVO 4',
                    'havo5' => 'HAVO 5',
                ],
            ],
            'vwo' => [
                'label' => 'VWO',
                'groups' => [
                    'atheneum4' => 'Atheneum 4',
                    'atheneum5' => 'Atheneum 5',
                    'atheneum6' => 'Atheneum 6',
                    'gymnasium4' => 'Gymnasium 4',
                    'gymnasium5' => 'Gymnasium 5',
                    'gymnasium6' => 'Gymnasium 6',
                ],
            ],
            'praktijkOnderwijs' => [
                'label' => 'Praktijkonderwijs',
                'groups' => [
                    'praktijk4' => 'Praktijkonderwijs 4',
                    'praktijk5' => 'Praktijkonderwijs 5',
                ],
            ],
        ],
    ];

    public const SCHOOL_LEVEL_SELECTION_RULES = [
        'primairOnderwijs' => [
            'minLevels' => 1,
            'maxLevels' => 1,
            'minGroupsPerLevel' => 1,
            'maxGroupsPerLevel' => 3,
        ],
        'voortgezetOnderbouw' => [
            'minLevels' => 1,
            'maxLevels' => 3,
            'minGroupsPerLevel' => 1,
            'maxGroupsPerLevel' => 3,
        ],
        'voortgezetBovenbouw' => [
            'minLevels' => 1,
            'maxLevels' => 3,
            'minGroupsPerLevel' => 1,
            'maxGroupsPerLevel' => 3,
        ],
    ];

    public const MODULES = [
        'primairOnderwijs' => [
            'ochtend' => [
                'standaard' => [
                    'Zandtafel',
                    'Rising-Risk',
                    'Dynamische-Globe',
                    'Dynamische-Globe-Bios',
                    'Expedition-Earth',
                ],
                'keuze' => [],
            ],
            'dag' => [
                'standaard' => [
                    'Klimaat-Experience',
                    'Klimparcours',
                    'Voedsel-Innovatie',
                    'Dynamische-Globe',
                ],
                'keuze' => [
                    'Minecraft-Klimaatspeurtocht',
                    'Earth-Watch',
                    'Stop-de-Klimaat-Klok',
                    'Minecraft-Programmeren',
                ],
            ],
        ],
        'voortgezetOnderbouw' => [
            'dag' => [
                'standaard' => [
                    'Klimaat-Experience',
                    'Voedsel-Innovatie',
                    'Dynamische-Globe',
                    'Earth-Watch',
                ],
                'keuze' => [
                    'Minecraft-Windenergiespeurtocht',
                    'Stop-de-Klimaat-Klok',
                    'Minecraft-Programmeren',
                    'Klimparcours',
                ],
            ],
        ],
        'voortgezetBovenbouw' => [
            'dag' => [
                'standaard' => [
                    'Klimaat-Experience',
                    'Voedsel-Innovatie',
                    'Dynamische-Globe',
                    'Earth-Watch',
                ],
                'keuze' => [
                    'Crisismanagement',
                    'Minecraft-Programmeren',
                    'Stop-de-Klimaat-Klok',
                ],
            ],
        ],
    ];

    public const MODULE_LABELS = [
        'Zandtafel' => 'Zandtafel',
        'Rising-Risk' => 'Rising Risk',
        'Dynamische-Globe' => 'Dynamische Globe',
        'Dynamische-Globe-Bios' => 'Dynamische Globe Bios',
        'Expedition-Earth' => 'Expedition Earth',
        'Klimaat-Experience' => 'Klimaat Experience',
        'Klimparcours' => 'Vleermuizen Speurtuin',
        'Voedsel-Innovatie' => 'Voedsel Innovatie',
        'Minecraft-Klimaatspeurtocht' => 'Minecraft Klimaatspeurtocht',
        'Earth-Watch' => 'Earth Watch',
        'Stop-de-Klimaat-Klok' => 'Stop de Klimaat Klok',
        'Minecraft-Programmeren' => 'Minecraft Programmeren',
        'Minecraft-Windenergiespeurtocht' => 'Minecraft Windenergiespeurtocht',
        'Crisismanagement' => 'Crisismanagement',
    ];

    /**
     * Korte lesaanduidingen voor compacte roosterweergaven.
     * Technische modulekeys blijven stabiel voor historische data.
     */
    public const MODULE_ABBREVIATIONS = [
        'Klimparcours' => 'VS',
    ];

    /**
     * MODULE_FILTERS werkt als uitsluitfilter.
     *
     * Voorbeeld:
     * - 'vmboBasisKader' => '*' sluit dit hele niveau uit.
     * - 'havo' => ['havo1'] sluit alleen HAVO 1 uit.
     */
    public const MODULE_FILTERS = [
        'Minecraft-Windenergiespeurtocht' => [
            'voortgezetOnderbouw' => [
                'vmboBasisKader' => '*',
                'vmboGemengdTheoretisch' => '*',
                'praktijkOnderwijs' => '*',
                'havo' => ['havo1'],
            ],
            'voortgezetBovenbouw' => [
                'vmbo' => '*',
                'praktijkOnderwijs' => '*',
            ],
        ],
    ];

    public const FOOD_AND_DRINK_INFO = [
        'included' => [
            [
                'label' => 'Koffie en thee',
                'description' => 'Voor begeleiders bij aankomst.',
            ],
            [
                'label' => 'Voedsel Innovatie snack',
                'description' => 'Vegetarische snack en plantaardige chocolademelk voor leerlingen tijdens de lesmodule Voedsel Innovatie.',
            ],
        ],
        'optional' => [
            'snacks' => [
                [
                    'key' => 'remise_break',
                    'label' => 'Remise break',
                    'description' => 'Ontbijtkoek met limonade.',
                ],
                [
                    'key' => 'kazerne_break',
                    'label' => 'Kazerne break',
                    'description' => 'Zakje chips met limonade.',
                ],
                [
                    'key' => 'fortgracht_break',
                    'label' => 'Fortgracht break',
                    'description' => 'Fruit met limonade.',
                ],
                [
                    'key' => 'waterijsje',
                    'label' => 'Waterijsje',
                    'description' => 'Waterijsje.',
                ],
                [
                    'key' => 'glas_limonade',
                    'label' => 'Glaasje limonade',
                    'description' => 'Glaasje limonade.',
                ],
            ],
            'lunch' => [
                [
                    'key' => 'remise_lunch',
                    'label' => 'Remiselunch',
                    'description' => 'Tarwebol met vegetarisch beleg voor leerlingen en begeleiders.',
                ],
                [
                    'key' => 'eigen_picknick',
                    'label' => 'Eigen lunch',
                    'description' => 'Neem uw eigen lunch mee.',
                ],
            ],
        ],
        'notes' => [
            'Eten en drinken kunt u alleen vooraf bestellen.',
            'Het restaurant is tijdens het schoolbezoek gesloten.',
        ],
    ];

    public const FOOD_AND_DRINK_OPTIONS = [
        'snacks' => [
            'remise_break' => [
                'label' => 'Remise break',
                'description' => 'Ontbijtkoek met limonade.',
                'min' => 1,
                'max' => 200,
                'priceGroup' => 'snacks',
            ],
            'kazerne_break' => [
                'label' => 'Kazerne break',
                'description' => 'Zakje chips met limonade.',
                'min' => 1,
                'max' => 200,
                'priceGroup' => 'snacks',
            ],
            'fortgracht_break' => [
                'label' => 'Fortgracht break',
                'description' => 'Fruit met limonade.',
                'min' => 1,
                'max' => 200,
                'priceGroup' => 'snacks',
            ],
            'waterijsje' => [
                'label' => 'Waterijsje',
                'description' => 'Waterijsje.',
                'min' => 1,
                'max' => 200,
                'priceGroup' => 'snacks',
            ],
            'glas_limonade' => [
                'label' => 'Glaasje limonade',
                'description' => 'Glaasje limonade.',
                'min' => 1,
                'max' => 200,
                'priceGroup' => 'snacks',
            ],
        ],
        'lunch' => [
            'remise_lunch' => [
                'label' => 'Remiselunch',
                'description' => 'Tarwebol met vegetarisch beleg voor leerlingen en begeleiders.',
                'min' => 50,
                'max' => 200,
                'priceGroup' => 'lunch',
            ],
            'eigen_picknick' => [
                'label' => 'Eigen lunch meenemen',
                'description' => 'Neem uw eigen lunch mee.',
                'min' => 0,
                'max' => 1,
                'priceGroup' => 'lunch',
            ],
        ],
    ];

    public const STUDENT_LIMITS = [
        'min' => [
            'ochtend' => [
                'basis' => 40,
            ],
            'dag' => [
                'basis' => 40,
                'voortgezet' => 40,
            ],
        ],
        'max' => [
            'ochtend' => 80,
            'dag' => 160,
        ],
    ];

    public const PRACTICAL_INFO = [
        'included' => [
            'Per 8 leerlingen is 1 begeleider gratis.',
            'Onderwijs vanuit GeoFort bij de meeste modules.',
            'Een vegetarische snack bij de module Voedsel-Innovatie.',
            'Koffie/thee voor de docenten bij ontvangst.',
            'Een onvergetelijke dag!',
        ],
        'specialNotes' => [
            'Museumjaarkaart is niet geldig op onderwijsarrangementen.',
            'Cultuurkaart/CJP zijn wel geldig.',
            'In geval van allergieën, geef deze door via het opmerkingenveld.',
        ],
        'vatText' => 'Alle genoemde tarieven zijn inclusief BTW.',
    ];

    public static function forFrontend(): array
    {
        self::assertConfigIsComplete();

        return [
            'schoolTypes' => self::getSchoolTypesForFrontend(),
            'schoolTypesByKey' => self::SCHOOL_TYPES_BY_KEY,

            'programs' => self::PROGRAMS,

            'schoolLevels' => self::SCHOOL_LEVELS,
            'schoolLevelSelectionRules' => self::SCHOOL_LEVEL_SELECTION_RULES,

            'modules' => self::MODULES,
            'moduleLabels' => self::MODULE_LABELS,
            'moduleFilters' => self::MODULE_FILTERS,

            'prices' => self::pricesForFrontend(),
            'studentLimits' => self::STUDENT_LIMITS,
            'practicalInfo' => self::PRACTICAL_INFO,
            'foodAndDrinkInfo' => self::FOOD_AND_DRINK_INFO,
            'foodAndDrinkOptions' => self::getFoodAndDrinkOptionsForFrontend(),
        ];
    }

    public static function getFoodAndDrinkOptionsForFrontend(): array
    {
        $options = [];

        foreach (self::FOOD_AND_DRINK_OPTIONS as $category => $items) {
            foreach ($items as $key => $config) {
                $priceGroup = $config['priceGroup'];

                $options[$category][$key] = [
                    'key' => $key,
                    'label' => $config['label'],
                    'description' => $config['description'],
                    'min' => $config['min'],
                    'max' => $config['max'],
                    'price' => self::priceCents($key) / 100,
                ];
            }
        }

        return $options;
    }

    public static function getFoodAndDrinkOptionLabel(string $key): string
    {
        foreach (self::FOOD_AND_DRINK_OPTIONS as $options) {
            if (array_key_exists($key, $options)) {
                return $options[$key]['label'];
            }
        }

        throw new InvalidArgumentException("Unknown food and drink option: {$key}");
    }

    public static function getSchoolTypesForFrontend(): array
    {
        $schoolTypes = [];

        foreach (self::SCHOOL_TYPES_BY_KEY as $value => $config) {
            $schoolTypes[] = [
                'value' => $value,
                ...$config,
            ];
        }

        return $schoolTypes;
    }

    public static function getSchoolSectorLabel(string $schoolSector): string
    {
        return self::getSchoolSectorConfig($schoolSector)['label'];
    }

    public static function getSchoolSectorConfig(string $schoolSector): array
    {
        if (!array_key_exists($schoolSector, self::SCHOOL_TYPES_BY_KEY)) {
            throw new InvalidArgumentException(
                "{$schoolSector} is not a valid school sector.",
            );
        }

        return self::SCHOOL_TYPES_BY_KEY[$schoolSector];
    }

    public static function getPriceTypeForSchoolSector(string $schoolSector): string
    {
        $config = self::getSchoolSectorConfig($schoolSector);

        if (!isset($config['priceType'])) {
            throw new InvalidArgumentException(
                "Missing priceType for school sector {$schoolSector}.",
            );
        }

        return (string) $config['priceType'];
    }

    public static function isValidSchoolSectorValue(string $schoolSector): bool
    {
        return array_key_exists($schoolSector, self::SCHOOL_TYPES_BY_KEY);
    }

    public static function programExists(string $program): bool
    {
        return array_key_exists($program, self::PROGRAMS);
    }

    public static function getProgramConfig(string $program): array
    {
        if (!self::programExists($program)) {
            throw new InvalidArgumentException("Unknown program: {$program}");
        }

        return self::PROGRAMS[$program];
    }

    public static function isProgramAllowedForSchoolSector(
        string $program,
        string $schoolSector,
    ): bool {
        if (!self::programExists($program)) {
            return false;
        }

        return in_array(
            $schoolSector,
            self::PROGRAMS[$program]['allowedSchoolTypes'],
            true,
        );
    }

    public static function isProgramAllowedForWeekday(
        string $program,
        int $isoWeekday,
    ): bool {
        if (!self::programExists($program)) {
            return false;
        }

        return in_array(
            $isoWeekday,
            self::PROGRAMS[$program]['allowedWeekdays'],
            true,
        );
    }

    public static function isProgramAllowedForSelection(
        string $program,
        string $schoolSector,
        int $isoWeekday,
    ): bool {
        return self::isProgramAllowedForSchoolSector($program, $schoolSector)
            && self::isProgramAllowedForWeekday($program, $isoWeekday);
    }

    public static function getMinStudentsForSelection(
        string $schoolSector,
        string $program,
    ): int {
        if (!self::programExists($program)) {
            throw new InvalidArgumentException("Unknown program: {$program}");
        }

        if (!isset(self::STUDENT_LIMITS['min'][$program])) {
            throw new InvalidArgumentException(
                "Missing student min limits for program {$program}.",
            );
        }

        $priceType = self::getPriceTypeForSchoolSector($schoolSector);

        if (!isset(self::STUDENT_LIMITS['min'][$program][$priceType])) {
            throw new InvalidArgumentException(
                "Missing student min limit for {$program} and {$priceType}.",
            );
        }

        return (int) self::STUDENT_LIMITS['min'][$program][$priceType];
    }

    public static function getStandardModulesForSelection(
        string $schoolSector,
        string $program,
    ): array {
        return self::MODULES[$schoolSector][$program]['standaard'] ?? [];
    }

    public static function getChoiceModulesForSelection(
        string $schoolSector,
        string $program,
    ): array {
        return self::MODULES[$schoolSector][$program]['keuze'] ?? [];
    }

    public static function hasChoiceModulesForSelection(
        string $schoolSector,
        string $program,
    ): bool {
        return self::getChoiceModulesForSelection($schoolSector, $program) !== [];
    }

    public static function isChoiceModuleConfiguredForSelection(
        string $moduleKey,
        string $schoolSector,
        string $program,
    ): bool {
        return in_array(
            $moduleKey,
            self::getChoiceModulesForSelection($schoolSector, $program),
            true,
        );
    }

    public static function isChoiceModuleAllowedForEducationSelection(
        string $moduleKey,
        string $schoolSector,
        EducationSelectionData $educationSelection,
    ): bool {
        if ($educationSelection->sector !== $schoolSector) {
            return false;
        }

        return !self::isModuleExcludedByEducationSelection(
            moduleKey: $moduleKey,
            schoolSector: $schoolSector,
            educationSelection: $educationSelection,
        );
    }

    public static function getModuleLabel(string $moduleKey): string
    {
        if (!self::moduleExists($moduleKey)) {
            throw new InvalidArgumentException("Unknown module: {$moduleKey}");
        }

        return self::MODULE_LABELS[$moduleKey]
            ?? str_replace('-', ' ', $moduleKey);
    }

    public static function getModuleLabels(array $moduleKeys): array
    {
        return array_map(
            static fn (string $moduleKey): string => self::getModuleLabel($moduleKey),
            $moduleKeys,
        );
    }

    private static function isModuleExcludedByEducationSelection(
        string $moduleKey,
        string $schoolSector,
        EducationSelectionData $educationSelection,
    ): bool {
        $filtersForModule = self::MODULE_FILTERS[$moduleKey][$schoolSector] ?? [];

        if ($filtersForModule === []) {
            return false;
        }

        foreach ($educationSelection->selectedLevels as $selectedLevelKey) {
            if (!array_key_exists($selectedLevelKey, $filtersForModule)) {
                continue;
            }

            $rule = $filtersForModule[$selectedLevelKey];

            if ($rule === '*') {
                return true;
            }

            if (!is_array($rule)) {
                return true;
            }

            $selectedGroupsForLevel =
                $educationSelection->selectedGroupsByLevel[$selectedLevelKey] ?? [];

            foreach ($selectedGroupsForLevel as $selectedGroupKey) {
                if (in_array($selectedGroupKey, $rule, true)) {
                    return true;
                }
            }
        }

        return false;
    }

    private static function moduleExists(string $moduleKey): bool
    {
        return in_array($moduleKey, self::getAllConfiguredModuleKeys(), true);
    }

    private static function getAllConfiguredModuleKeys(): array
    {
        $moduleKeys = [];

        foreach (self::MODULES as $programsBySector) {
            foreach ($programsBySector as $moduleGroups) {
                foreach (['standaard', 'keuze'] as $moduleType) {
                    foreach ($moduleGroups[$moduleType] ?? [] as $moduleKey) {
                        $moduleKeys[$moduleKey] = true;
                    }
                }
            }
        }

        return array_keys($moduleKeys);
    }

    private static function assertConfigIsComplete(): void
    {
        self::assertSchoolConfigIsComplete();
        self::assertProgramConfigIsComplete();
        self::assertModuleConfigIsComplete();
        self::assertStudentLimitConfigIsComplete();
        self::assertFoodAndDrinkInfoConfigIsComplete();
        self::assertFoodAndDrinkOptionsConfigIsComplete();
    }

    private static function assertSchoolConfigIsComplete(): void
    {
        foreach (array_keys(self::SCHOOL_TYPES_BY_KEY) as $sector) {
            if (!array_key_exists($sector, self::SCHOOL_LEVELS)) {
                throw new LogicException("Missing SCHOOL_LEVELS for sector: {$sector}");
            }

            if (!array_key_exists($sector, self::SCHOOL_LEVEL_SELECTION_RULES)) {
                throw new LogicException("Missing SCHOOL_LEVEL_SELECTION_RULES for sector: {$sector}");
            }
        }
    }

    private static function assertProgramConfigIsComplete(): void
    {
        foreach (self::PROGRAMS as $programKey => $programConfig) {
            foreach ([
                'label',
                'beginTijd',
                'eindTijd',
                'duurLesmodule',
                'allowedSchoolTypes',
                'allowedWeekdays',
                'description',
            ] as $requiredKey) {
                if (!array_key_exists($requiredKey, $programConfig)) {
                    throw new LogicException(
                        "Program {$programKey} is missing {$requiredKey}",
                    );
                }
            }

            foreach ($programConfig['allowedSchoolTypes'] as $schoolSector) {
                if (!array_key_exists($schoolSector, self::SCHOOL_TYPES_BY_KEY)) {
                    throw new LogicException(
                        "Program {$programKey} contains invalid school sector {$schoolSector}",
                    );
                }
            }

            foreach ($programConfig['allowedWeekdays'] as $weekday) {
                if (!is_int($weekday) || $weekday < 1 || $weekday > 5) {
                    throw new LogicException(
                        "Program {$programKey} contains invalid weekday {$weekday}",
                    );
                }
            }
        }
    }

    private static function assertModuleConfigIsComplete(): void
    {
        foreach (self::getAllConfiguredModuleKeys() as $moduleKey) {
            if (!array_key_exists($moduleKey, self::MODULE_LABELS)) {
                throw new LogicException("Missing MODULE_LABELS for module: {$moduleKey}");
            }
        }

        foreach (self::MODULES as $sector => $programsBySector) {
            if (!array_key_exists($sector, self::SCHOOL_TYPES_BY_KEY)) {
                throw new LogicException("MODULES contains invalid sector: {$sector}");
            }

            foreach ($programsBySector as $program => $moduleGroups) {
                if (!self::programExists($program)) {
                    throw new LogicException("MODULES contains invalid program: {$program}");
                }

                $standardModules = $moduleGroups['standaard'] ?? [];
                $choiceModules = $moduleGroups['keuze'] ?? [];

                $duplicates = array_intersect($standardModules, $choiceModules);

                if ($duplicates !== []) {
                    throw new LogicException(
                        "Modules cannot be both standard and choice for {$sector}.{$program}: "
                        . implode(', ', $duplicates),
                    );
                }
            }
        }

        foreach (self::MODULE_FILTERS as $moduleKey => $filtersBySector) {
            if (!self::moduleExists($moduleKey)) {
                throw new LogicException("MODULE_FILTERS contains unknown module: {$moduleKey}");
            }

            foreach ($filtersBySector as $sector => $filtersByLevel) {
                if (!array_key_exists($sector, self::SCHOOL_TYPES_BY_KEY)) {
                    throw new LogicException(
                        "Module {$moduleKey} contains invalid sector filter: {$sector}",
                    );
                }

                foreach ($filtersByLevel as $levelKey => $groupRule) {
                    if (!array_key_exists($levelKey, self::SCHOOL_LEVELS[$sector])) {
                        throw new LogicException(
                            "Module {$moduleKey} contains invalid level {$levelKey} for sector {$sector}",
                        );
                    }

                    if ($groupRule === '*') {
                        continue;
                    }

                    if (!is_array($groupRule)) {
                        throw new LogicException(
                            "Module {$moduleKey} filter for {$sector}.{$levelKey} must be '*' or an array",
                        );
                    }

                    $validGroups = self::SCHOOL_LEVELS[$sector][$levelKey]['groups'];

                    foreach ($groupRule as $groupKey) {
                        if (!array_key_exists($groupKey, $validGroups)) {
                            throw new LogicException(
                                "Module {$moduleKey} contains invalid group {$groupKey} for {$sector}.{$levelKey}",
                            );
                        }
                    }
                }
            }
        }
    }

    private static function assertStudentLimitConfigIsComplete(): void
    {
        foreach (self::PROGRAMS as $programKey => $_programConfig) {
            if (!array_key_exists($programKey, self::STUDENT_LIMITS['min'])) {
                throw new LogicException("Missing min student limits for program: {$programKey}");
            }

            if (!array_key_exists($programKey, self::STUDENT_LIMITS['max'])) {
                throw new LogicException("Missing max student limits for program: {$programKey}");
            }
        }

        foreach (self::SCHOOL_TYPES_BY_KEY as $sector => $sectorConfig) {
            $priceType = $sectorConfig['priceType'];

            foreach (self::PROGRAMS as $programKey => $programConfig) {
                if (!in_array($sector, $programConfig['allowedSchoolTypes'], true)) {
                    continue;
                }

                if (!isset(self::STUDENT_LIMITS['min'][$programKey][$priceType])) {
                    throw new LogicException(
                        "Missing min student limit for {$programKey} and price type {$priceType}",
                    );
                }
            }
        }
    }

    private static function assertFoodAndDrinkInfoConfigIsComplete(): void
    {
        if (!is_array(self::FOOD_AND_DRINK_INFO['included'] ?? null)) {
            throw new LogicException('FOOD_AND_DRINK_INFO.included must be an array');
        }

        if (!is_array(self::FOOD_AND_DRINK_INFO['notes'] ?? null)) {
            throw new LogicException('FOOD_AND_DRINK_INFO.notes must be an array');
        }

        foreach (['snacks', 'lunch'] as $category) {
            $items = self::FOOD_AND_DRINK_INFO['optional'][$category] ?? null;

            if (!is_array($items)) {
                throw new LogicException("FOOD_AND_DRINK_INFO.optional.{$category} must be an array");
            }

            foreach ($items as $item) {
                $key = $item['key'] ?? null;

                if (!is_string($key) || $key === '') {
                    throw new LogicException("FOOD_AND_DRINK_INFO.optional.{$category} contains an item without key");
                }

                if (!array_key_exists($key, (new BookingPriceCatalogRegistry())->active()->cateringPricesCents())) {
                    throw new LogicException(
                        "FOOD_AND_DRINK_INFO.optional.{$category} contains unknown price key: {$key}",
                    );
                }
            }
        }
    }

    private static function assertFoodAndDrinkOptionsConfigIsComplete(): void
    {
        foreach (['snacks', 'lunch'] as $category) {
            $items = self::FOOD_AND_DRINK_OPTIONS[$category] ?? null;

            if (!is_array($items)) {
                throw new LogicException("FOOD_AND_DRINK_OPTIONS.{$category} must be an array");
            }

            foreach ($items as $key => $config) {
                if (!array_key_exists($key, (new BookingPriceCatalogRegistry())->active()->cateringPricesCents())) {
                    throw new LogicException(
                        "FOOD_AND_DRINK_OPTIONS.{$category} contains unknown price key: {$key}",
                    );
                }

                if (($config['priceGroup'] ?? null) !== $category) {
                    throw new LogicException(
                        "FOOD_AND_DRINK_OPTIONS.{$category}.{$key} has invalid priceGroup",
                    );
                }

                if (!is_int($config['min'] ?? null) || !is_int($config['max'] ?? null)) {
                    throw new LogicException(
                        "FOOD_AND_DRINK_OPTIONS.{$category}.{$key} min and max must be integers",
                    );
                }

                if ($config['min'] > $config['max']) {
                    throw new LogicException(
                        "FOOD_AND_DRINK_OPTIONS.{$category}.{$key} min cannot exceed max",
                    );
                }
            }
        }
    }
}

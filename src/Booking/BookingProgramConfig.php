<?php
declare(strict_types=1);
namespace GeoFort\Booking;

final class BookingProgramConfig 
{
    public const SCHOOL_TYPES = [
        'primairOnderwijs' => [
            'label' => 'Primair Onderwijs',
            'roosterType' => 'primair',
            'priceType' => 'basis',
            'category' => 'basis',
        ],
        'voortgezetOnderbouw' => [
            'label' => 'Voortgezet Onderwijs onderbouw',
            'roosterType' => 'onderbouw',
            'priceType' => 'voortgezet',
            'category' => 'voortgezet',
        ],
        'voortgezetBovenbouw' => [
            'label' => 'Voortgezet Onderwijs bovenbouw',
            'roosterType' => 'bovenbouw',
            'priceType' => 'voortgezet',
            'category' => 'voortgezet',
        ],
    ];

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
                'groups' => [
                    'groep5' => 'Groep 5',
                    'groep6' => 'Groep 6',
                    'groep7' => 'Groep 7',
                    'groep8' => 'Groep 8',
                ],
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
                    'Stop-de-Klimaat-Klok',
                ],
                'keuze' => [
                    'Crisismanagement',
                    'Stop-de-Klimaat-Klok',
                    'Minecraft-Programmeren',
                ],
            ],
        ],
    ];

    public const PRICES = [
        'bezoek' => [
            'ochtend' => [
                'basis' => 9.95,
            ],
            'dag' => [
                'basis' => 18.00,
                'voortgezet' => 22.00,
            ],
        ],
        'snacks' => [
            'remise_break' => 2.60,
            'kazerne_break' => 2.60,
            'fortgracht_break' => 2.60,
            'glas_limonade' => 1.00,
            'waterijsje' => 1.00,
        ],
        'lunch' => [
            'remise_lunch' => 3.60,
            'eigen_picknick' => 0.00,
        ],
    ];

    public const STUDENT_LIMITS = [
        'min' => [
            'ochtend' => [
                'basis' => 20,
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

    public const SCHOOL_LEVEL_SELECTION_RULES = [
        'primairOnderwijs' => [
            'minLevels' => 1,
            'maxLevels' => 1,
            'minGroupsPerLevel' => 1,
            'maxGroupsPerLevel' => 4,
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

    public static function SchoolSectorSelectOptionsForFrontend(): array {
        $options = []; 
        foreach (self::SCHOOL_TYPES as $value => $config) {
            $options[] = [
                'value' => $value,
                'label' => $config['label'],
                'roosterType' => $config['roosterType'],
                'priceType' => $config['priceType'],
                'category' => $config['category'],
            ];
        }

        return $options;
    }

    public static function forFrontend(): array
    {
        self::assertConfigIsComplete();

        return [
            'schoolTypes' => self::SchoolSectorSelectOptionsForFrontend(),

            // handig voor lookups / validatie in frontend
            'schoolTypesByKey' => self::SCHOOL_TYPES,

            'programs' => self::PROGRAMS,
            'schoolLevels' => self::SCHOOL_LEVELS,
            'schoolLevelSelectionRules' => self::SCHOOL_LEVEL_SELECTION_RULES,

            'modules' => self::MODULES,
            'prices' => self::PRICES,
            'studentLimits' => self::STUDENT_LIMITS,
            'practicalInfo' => self::PRACTICAL_INFO,
        ];
    }


    public static function getSchoolSectorLabel(string $schoolSector): string {
        if (!array_key_exists($schoolSector, self::SCHOOL_TYPES))
            throw new \InvalidArgumentException("Invalid school sector");

        return self::SCHOOL_TYPES[$schoolSector]['label'];
    }

    public static function isValidSchoolSectorValue(string $schoolSectorValue): bool
    {
        return array_key_exists($schoolSectorValue, self::SCHOOL_TYPES);
    }

    private static function assertConfigIsComplete(): void
    {
        foreach (array_keys(self::SCHOOL_TYPES) as $sector) {
            if (!array_key_exists($sector, self::SCHOOL_LEVELS)) {
                throw new \LogicException("Missing SCHOOL_LEVELS for sector: {$sector}");
            }

            if (!array_key_exists($sector, self::SCHOOL_LEVEL_SELECTION_RULES)) {
                throw new \LogicException("Missing SCHOOL_LEVEL_SELECTION_RULES for sector: {$sector}");
            }
        }

        foreach (self::PROGRAMS as $programKey => $program) {
            foreach ($program['allowedSchoolTypes'] as $sector) {
                if (!array_key_exists($sector, self::SCHOOL_TYPES)) {
                    throw new \LogicException("Program {$programKey} contains invalid sector: {$sector}");
                }
            }
        }
    }

}
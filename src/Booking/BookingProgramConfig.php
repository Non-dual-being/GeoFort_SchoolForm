<?php
declare(strict_types=1);
namespace BookingProgramConfig;

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

    public static function forFrontend(): array
    {
        return [
            'schoolTypes' => self::SCHOOL_TYPES,
            'programs' => self::PROGRAMS,
            'modules' => self::MODULES,
            'prices' => self::PRICES,
            'studentLimits' => self::STUDENT_LIMITS,
            'practicalInfo' => self::PRACTICAL_INFO,
        ];
    }
}
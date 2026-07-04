<?php

declare(strict_types=1);

namespace GeoFort\Booking;

use InvalidArgumentException;

final class BookingPolicy
{
    public const PROGRAM_DAY = 'dag';
    public const PROGRAM_MORNING = 'ochtend';

    public const PROGRAM_LABELS = [
        self::PROGRAM_DAY => 'Dagprogramma',
        self::PROGRAM_MORNING => 'Ochtendprogramma',
    ];

    public const ALLOWED_PROGRAMS = [
        self::PROGRAM_DAY,
        self::PROGRAM_MORNING,
    ];

    public const STATUS_OPTION = 'In optie';
    public const STATUS_CONFIRMED = 'Definitief';
    public const STATUS_REJECTED = 'Afgewezen';

    public const ALLOWED_STATUSES = [
        self::STATUS_OPTION,
        self::STATUS_CONFIRMED,
        self::STATUS_REJECTED,
    ];

    public const ACTIVE_STATUSES = [
        self::STATUS_OPTION,
        self::STATUS_CONFIRMED,
    ];

    public const MAX_SCHOOLS_PER_DAY = 2;

    public const MAX_STUDENTS_TOTAL_PER_DAY = 160;

    public const MAX_STUDENTS_PER_DAY_PROGRAM = [
        self::PROGRAM_DAY => 160,
        self::PROGRAM_MORNING => 80,
    ];

    public const BOOKABLE_YEARS_AHEAD = 2;

    public static function isAllowedProgram(string $program): bool
    {
        return in_array($program, self::ALLOWED_PROGRAMS, true);
    }

    public static function getProgramLabel(string $program): string
    {
        if (!self::isAllowedProgram($program)) {
            throw new InvalidArgumentException(
                "{$program} is not a valid program",
            );
        }

        return self::PROGRAM_LABELS[$program];
    }

    public static function getMaxStudentsOfProgram(string $program): int
    {
        if (!self::isAllowedProgram($program)) {
            throw new InvalidArgumentException(
                "{$program} is not a valid program",
            );
        }

        return self::MAX_STUDENTS_PER_DAY_PROGRAM[$program];
    }

    public static function isAllowedStatus(string $status): bool
    {
        return in_array($status, self::ALLOWED_STATUSES, true);
    }

    public static function isActiveStatus(string $status): bool
    {
        return in_array($status, self::ACTIVE_STATUSES, true);
    }

    public static function getBookingPolicyForFrontend(): array
    {
        return [
            'programma' => [
                self::PROGRAM_DAY => [
                    'label' => self::PROGRAM_LABELS[self::PROGRAM_DAY],
                    'maxLeerlingenPerDag' => self::MAX_STUDENTS_PER_DAY_PROGRAM[self::PROGRAM_DAY],
                ],
                self::PROGRAM_MORNING => [
                    'label' => self::PROGRAM_LABELS[self::PROGRAM_MORNING],
                    'maxLeerlingenPerDag' => self::MAX_STUDENTS_PER_DAY_PROGRAM[self::PROGRAM_MORNING],
                ],
            ],
            'limieten' => [
                'maxScholenPerDag' => self::MAX_SCHOOLS_PER_DAY,
                'maxStudentenTotaal' => self::MAX_STUDENTS_TOTAL_PER_DAY,
            ],
            'boekingregels' => [
                'agendabereik' => self::BOOKABLE_YEARS_AHEAD,
            ],
            'statussen' => [
                'actief' => self::ACTIVE_STATUSES,
                'toegestaan' => self::ALLOWED_STATUSES,
                'standaardNieuweAanvraag' => self::STATUS_OPTION,
            ],
        ];
    }
}
<?php

declare(strict_types=1);

namespace GeoFort\Booking;

use DateTimeImmutable;
use InvalidArgumentException;

/** Explicit opt-in policy; never infer an existing booking's version from its date. */
final class FourModuleProgram
{
    public const VERSION = 'day-four-2027';
    public const START_DATE = '2027-01-04';
    public const GROUP_TARGET = 19;
    public const MAX_STUDENTS = 160;
    public const MAX_GROUPS = 8;

    public static function availableOn(string $visitDate): bool
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $visitDate);
        if ($date === false || $date->format('Y-m-d') !== $visitDate) {
            throw new InvalidArgumentException('Ongeldige bezoekdatum.');
        }

        return $visitDate >= self::START_DATE;
    }

    /** This is a grouping proposal; classes and activity capacity still need validation. */
    public static function suggestedGroupCount(int $students): int
    {
        if ($students < 1 || $students > self::MAX_STUDENTS) {
            throw new InvalidArgumentException('Het viermodulesprogramma ondersteunt 1 tot 160 leerlingen.');
        }

        // The agreed eight-group exception permits 20 pupils per group at 160.
        return min(self::MAX_GROUPS, (int) ceil($students / self::GROUP_TARGET));
    }

    /** @return list<string> */
    public static function standardModules(string $sector): array
    {
        self::validateSector($sector);

        return ['Dynamische-Globe', 'Voedsel-Innovatie',
            $sector === 'primairOnderwijs' ? 'Klimparcours' : 'Klimaat-Experience'];
    }

    /** @param list<string> $schoolGroups @return list<string> */
    public static function choiceModules(string $sector, array $schoolGroups): array
    {
        self::validateSector($sector);
        if ($sector !== 'primairOnderwijs') {
            return ['Earth-Watch', 'Stop-de-Klimaat-Klok', 'Minecraft-Klimaatspeurtocht',
                'Minecraft-Programmeren', 'Klimparcours'];
        }

        $choices = ['Minecraft-Klimaatspeurtocht', 'Klimaat-Experience'];
        // A shared choice must be suitable for every selected school group.
        if ($schoolGroups !== [] && array_diff($schoolGroups, ['groep7', 'groep8']) === []) {
            $choices = [...$choices, 'Stop-de-Klimaat-Klok', 'Earth-Watch'];
        }

        return $choices;
    }

    /** @param list<string> $schoolGroups @return list<string> */
    public static function modules(string $sector, array $schoolGroups, string $choice): array
    {
        if (!in_array($choice, self::choiceModules($sector, $schoolGroups), true)) {
            throw new InvalidArgumentException('Kies een vierde module die past bij de hele school.');
        }

        return [...self::standardModules($sector), $choice];
    }

    /** @return list<array{start:string,end:string,kind:string}> */
    public static function timetable(): array
    {
        return [
            ['start' => '10:00', 'end' => '10:15', 'kind' => 'arrival'],
            ['start' => '10:15', 'end' => '11:00', 'kind' => 'lesson'],
            ['start' => '11:00', 'end' => '11:45', 'kind' => 'lesson'],
            ['start' => '11:45', 'end' => '12:15', 'kind' => 'lunch'],
            ['start' => '12:15', 'end' => '13:00', 'kind' => 'lesson'],
            ['start' => '13:00', 'end' => '13:45', 'kind' => 'lesson'],
            ['start' => '13:45', 'end' => '14:00', 'kind' => 'departure'],
        ];
    }

    public static function requiredTeachers(int $groups): int
    {
        if ($groups < 1 || $groups > self::MAX_GROUPS) {
            throw new InvalidArgumentException('Ongeldig aantal groepen.');
        }

        return $groups;
    }

    private static function validateSector(string $sector): void
    {
        if (!isset(BookingProgramConfig::SCHOOL_TYPES_BY_KEY[$sector])) {
            throw new InvalidArgumentException('Onbekende onderwijssector.');
        }
    }
}

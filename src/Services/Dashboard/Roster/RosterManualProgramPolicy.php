<?php

declare(strict_types=1);

namespace GeoFort\Services\Dashboard\Roster;

final class RosterManualProgramPolicy
{
    public const MIN_MODULE_COUNT = 2;
    public const MAX_MODULE_COUNT = 5;
    public const GROUPS_PER_MODULE = 2;
    public const MAX_GROUP_COUNT = self::MAX_MODULE_COUNT * self::GROUPS_PER_MODULE;

    public static function supportsModuleCount(int $moduleCount): bool
    {
        return $moduleCount >= self::MIN_MODULE_COUNT
            && $moduleCount <= self::MAX_MODULE_COUNT;
    }

    public static function maxGroupsForModuleCount(int $moduleCount): int
    {
        if (!self::supportsModuleCount($moduleCount)) {
            return 0;
        }

        return $moduleCount * self::GROUPS_PER_MODULE;
    }

    public static function minimumModulesForGroupCount(int $groupCount): int
    {
        if ($groupCount <= 0) {
            return self::MIN_MODULE_COUNT;
        }

        return max(
            self::MIN_MODULE_COUNT,
            (int) ceil($groupCount / self::GROUPS_PER_MODULE),
        );
    }

    /** @return list<string> */
    public static function expectedTimelineTypes(int $moduleCount, string $program): array
    {
        return match ($moduleCount) {
            2 => ['welcome', 'round', 'round', 'departure'],
            3 => ['welcome', 'round', 'break', 'round', 'round', 'departure'],
            4 => ['welcome', 'round', 'round', 'break', 'round', 'round', 'departure'],
            5 => $program === 'ochtend'
                ? ['welcome', 'round', 'round', 'break', 'round', 'round', 'round', 'departure']
                : ['welcome', 'round', 'break', 'round', 'round', 'lunch', 'round', 'round', 'departure'],
            default => [],
        };
    }

    /** @param list<array{type:string,label:string,startTime:string,endTime:string}> $blocks */
    public static function matchesTimelineStructure(
        array $blocks,
        int $moduleCount,
        string $program,
        ?string $programVersion = null,
    ): bool {
        $expected = self::expectedTimelineTypes($moduleCount, $program);
        if ($programVersion === \GeoFort\Booking\FourModuleProgram::VERSION && $moduleCount === 4) $expected[3] = 'lunch';

        if ($expected === [] || count($blocks) !== count($expected)) {
            return false;
        }

        foreach ($expected as $index => $type) {
            if (($blocks[$index]['type'] ?? null) !== $type) {
                return false;
            }
        }

        return true;
    }
}

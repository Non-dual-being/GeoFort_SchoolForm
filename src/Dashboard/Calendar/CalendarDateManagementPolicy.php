<?php
declare(strict_types=1);

namespace GeoFort\Dashboard\Calendar;

final class CalendarDateManagementPolicy
{
    public const MANUAL_BLOCK_TYPE = 'manual';
    public const SCHOOL_VACATION_BLOCK_TYPE = 'school_vacation';
    public const PLANNER_SOURCE = 'planner';
    public const GENERATED_SOURCE = 'generated';
    public const MANAGEABLE_TYPES = [
        self::MANUAL_BLOCK_TYPE,
        self::SCHOOL_VACATION_BLOCK_TYPE,
    ];
    public const RELEASABLE_SOURCES_BY_TYPE = [
        self::MANUAL_BLOCK_TYPE => [self::PLANNER_SOURCE],
        self::SCHOOL_VACATION_BLOCK_TYPE => [self::GENERATED_SOURCE, self::PLANNER_SOURCE],
    ];

    public static function isReleasable(string $type, string $source): bool
    {
        return in_array($source, self::RELEASABLE_SOURCES_BY_TYPE[$type] ?? [], true);
    }
    public const MIN_REASON_LENGTH = 3;
    public const MAX_REASON_LENGTH = 255;
    public const MAX_PERIOD_DAYS = 93;

    public const ACTIONS = [
        'block_single',
        'block_period',
        'release_single',
        'release_period',
    ];
}

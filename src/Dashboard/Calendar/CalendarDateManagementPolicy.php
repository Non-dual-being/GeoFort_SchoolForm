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

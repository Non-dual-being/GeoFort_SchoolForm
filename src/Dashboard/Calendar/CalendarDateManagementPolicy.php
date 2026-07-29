<?php
declare(strict_types=1);

namespace GeoFort\Dashboard\Calendar;

final class CalendarDateManagementPolicy
{
    public const MANUAL_BLOCK_TYPE = 'manual';
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

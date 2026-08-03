<?php

declare(strict_types=1);

namespace GeoFort\Services\Http\Api\Admin;

final readonly class DashboardCalendarOverviewRequest
{
    private const MIN_YEAR = 2000;
    private const MAX_YEAR = 2100;

    public function __construct(public int $year, public int $month) {}

    /** @param array<string, mixed> $query */
    public static function fromQuery(array $query): ?self
    {
        $keys = array_keys($query);
        sort($keys);
        if ($keys !== ['month', 'year']) return null;
        if (!is_string($query['year']) || preg_match('/^\d{4}$/', $query['year']) !== 1) return null;
        if (!is_string($query['month']) || preg_match('/^(?:[1-9]|1[0-2])$/', $query['month']) !== 1) return null;
        $year = (int) $query['year'];
        $month = (int) $query['month'];
        return $year >= self::MIN_YEAR && $year <= self::MAX_YEAR ? new self($year, $month) : null;
    }
}

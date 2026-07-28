<?php

declare(strict_types=1);

namespace GeoFort\Services\Http\Api\Admin;

use DateTimeImmutable;

final readonly class DashboardCalendarRequest
{
    public function __construct(public string $startDate, public string $endDate) {}

    /** @param array<string, mixed> $query */
    public static function fromQuery(array $query): ?self
    {
        $start = is_string($query['startDate'] ?? null) ? DateTimeImmutable::createFromFormat('!Y-m-d', $query['startDate']) : false;
        $end = is_string($query['endDate'] ?? null) ? DateTimeImmutable::createFromFormat('!Y-m-d', $query['endDate']) : false;

        if (
            $start === false
            || $end === false
            || $start->format('Y-m-d') !== ($query['startDate'] ?? '')
            || $end->format('Y-m-d') !== ($query['endDate'] ?? '')
        ) {
            return null;
        }

        $days = (int) $start->diff($end)->format('%r%a') + 1;
        return $days >= 1 && $days <= 42
            ? new self($start->format('Y-m-d'), $end->format('Y-m-d'))
            : null;
    }
}

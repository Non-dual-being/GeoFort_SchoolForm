<?php
declare(strict_types=1);
namespace GeoFort\Services\Http\Api\Admin;

use DateTimeImmutable;

final readonly class BookingVisitDateCalendarRequest
{
    public function __construct(public int $bookingId, public string $startDate, public string $endDate) {}

    /** @param array<string, mixed> $query */
    public static function fromQuery(array $query): ?self
    {
        $id = is_string($query['bookingId'] ?? null) ? filter_var($query['bookingId'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : false;
        $start = is_string($query['startDate'] ?? null) ? DateTimeImmutable::createFromFormat('!Y-m-d', $query['startDate']) : false;
        $end = is_string($query['endDate'] ?? null) ? DateTimeImmutable::createFromFormat('!Y-m-d', $query['endDate']) : false;
        if ($id === false || $start === false || $end === false || $start->format('Y-m-d') !== ($query['startDate'] ?? '') || $end->format('Y-m-d') !== ($query['endDate'] ?? '')) return null;
        $days = (int) $start->diff($end)->format('%r%a') + 1;
        if ($days < 1 || $days > 42) return null;
        return new self((int) $id, $start->format('Y-m-d'), $end->format('Y-m-d'));
    }
}

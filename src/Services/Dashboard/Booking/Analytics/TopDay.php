<?php
declare(strict_types=1);

namespace GeoFort\Services\Dashboard\Booking\Analytics;

final readonly class TopDay
{
    public function __construct(public int $rank, public string $date, public string $weekday, public int $bookings, public int $uniqueSchools, public int $students) {}
    /** @return array<string, int|string> */
    public function toArray(): array { return get_object_vars($this); }
}

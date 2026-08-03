<?php

declare(strict_types=1);

namespace GeoFort\Dashboard\Calendar;

final readonly class CalendarOverviewAggregate
{
    public function __construct(
        public string $program,
        public string $status,
        public int $bookingCount,
        public int $studentCount,
        public int $unknownStudentCount,
        public int $invalidStudentCount,
    ) {}

    /** @return array<string, int|string> */
    public function toArray(): array
    {
        return get_object_vars($this);
    }
}

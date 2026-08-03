<?php

declare(strict_types=1);

namespace GeoFort\Dashboard\Calendar;

final readonly class CalendarOverviewResponse
{
    /** @param array<string, mixed> $period @param array<string, mixed> $filters @param list<CalendarOverviewDay> $days */
    public function __construct(
        public array $period,
        public string $generatedAt,
        public string $timezone,
        public array $filters,
        public array $days,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'period' => $this->period,
            'generatedAt' => $this->generatedAt,
            'timezone' => $this->timezone,
            'filters' => $this->filters,
            'days' => array_map(static fn (CalendarOverviewDay $day): array => $day->toArray(), $this->days),
        ];
    }
}

<?php

declare(strict_types=1);

namespace GeoFort\Dashboard\Calendar;

final readonly class CalendarOverviewDay
{
    /**
     * @param array{totalDaily:int,programs:array<string,int>} $capacity
     * @param list<CalendarOverviewAggregate> $aggregates
     */
    public function __construct(
        public string $date,
        public int $weekday,
        public bool $inSelectedMonth,
        public bool $isPast,
        public bool $isToday,
        public bool $isBookableWeekday,
        public ?CalendarOverviewDisabledDate $disabled,
        public bool $hasBookingsOnBlockedDate,
        public array $capacity,
        public array $aggregates,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'date' => $this->date,
            'weekday' => $this->weekday,
            'inSelectedMonth' => $this->inSelectedMonth,
            'isPast' => $this->isPast,
            'isToday' => $this->isToday,
            'isBookableWeekday' => $this->isBookableWeekday,
            'disabled' => $this->disabled?->toArray(),
            'hasBookingsOnBlockedDate' => $this->hasBookingsOnBlockedDate,
            'capacity' => $this->capacity,
            'aggregates' => array_map(static fn (CalendarOverviewAggregate $aggregate): array => $aggregate->toArray(), $this->aggregates),
        ];
    }
}

<?php
declare(strict_types=1);

namespace GeoFort\Dashboard\Overview;

final readonly class DashboardOverviewData
{
    /** @param list<array<string, mixed>> $optionBookings @param list<array<string, mixed>> $nextOptionBookings @param array<string, int> $monthStatistics */
    public function __construct(
        public string $today,
        public string $timezone,
        public int $optionTotal,
        public array $optionBookings,
        public ?string $nextOptionDate,
        public array $nextOptionBookings,
        public array $monthStatistics,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'generatedForDate' => $this->today,
            'timezone' => $this->timezone,
            'options' => ['total' => $this->optionTotal, 'items' => $this->optionBookings],
            'nextOption' => ['visitDate' => $this->nextOptionDate, 'items' => $this->nextOptionBookings],
            'currentMonth' => $this->monthStatistics,
        ];
    }
}

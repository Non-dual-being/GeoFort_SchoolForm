<?php
declare(strict_types=1);

namespace GeoFort\Services\Dashboard\Booking;

final readonly class DashboardBookingPage
{
    /** @param list<DashboardBookingListItem> $items */
    public function __construct(
        public array $items,
        public int $currentPage,
        public int $perPage,
        public int $totalItems,
        public int $totalPages,
        public int $from,
        public int $to,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'items' => array_map(
                static fn (DashboardBookingListItem $item): array => $item->toArray(),
                $this->items,
            ),
            'pagination' => [
                'currentPage' => $this->currentPage,
                'perPage' => $this->perPage,
                'totalItems' => $this->totalItems,
                'totalPages' => $this->totalPages,
                'from' => $this->from,
                'to' => $this->to,
            ],
        ];
    }
}

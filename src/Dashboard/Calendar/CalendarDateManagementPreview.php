<?php
declare(strict_types=1);

namespace GeoFort\Dashboard\Calendar;

final readonly class CalendarDateManagementPreview
{
    /** @param array<string, mixed> $categories */
    public function __construct(
        public string $action,
        public string $startDate,
        public string $endDate,
        public int $calendarDayCount,
        public array $categories,
        public int $bookingCount,
        public int $studentCount,
        public string $fingerprint,
        public string $activeBookingsFingerprint,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'action' => $this->action,
            'startDate' => $this->startDate,
            'endDate' => $this->endDate,
            'calendarDayCount' => $this->calendarDayCount,
            'categories' => $this->categories,
            'bookingCount' => $this->bookingCount,
            'studentCount' => $this->studentCount,
            'fingerprint' => $this->fingerprint,
            'activeBookingsFingerprint' => $this->activeBookingsFingerprint,
        ];
    }
}

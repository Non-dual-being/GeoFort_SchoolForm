<?php
declare(strict_types=1);

namespace GeoFort\Dashboard\Calendar;

final readonly class CalendarDateManagementCommand
{
    public function __construct(
        public string $startDate,
        public string $endDate,
        public string $action,
        public ?string $reason,
        public bool $confirmed,
        public bool $existingBookingsAccepted,
        public ?string $expectedFingerprint,
        public ?string $expectedActiveBookingsFingerprint,
        public int $actingAdminId,
    ) {}

    public function isPeriod(): bool
    {
        return str_ends_with($this->action, '_period');
    }

    public function isBlock(): bool
    {
        return str_starts_with($this->action, 'block_');
    }
}

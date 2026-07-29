<?php
declare(strict_types=1);

namespace GeoFort\Dashboard\Calendar;

final readonly class CalendarDateManagementResult
{
    /** @param list<CalendarDateManagementIssue> $issues */
    public function __construct(
        public string $code,
        public bool $success,
        public string $startDate,
        public string $endDate,
        public int $affectedCount = 0,
        public ?CalendarDateManagementPreview $preview = null,
        public array $issues = [],
    ) {}
}

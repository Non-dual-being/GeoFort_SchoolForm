<?php
declare(strict_types=1);

namespace GeoFort\Services\Dashboard\Booking\Analytics;

final readonly class YearlyAnalysis
{
    /** @param list<array<string, mixed>> $years @param array<string, string> $availableMetrics */
    public function __construct(public string $generatedAt, public string $analyticsAsOfDate, public array $years, public array $availableMetrics, public string $comparisonAvailability, public string $context) {}
    /** @return array<string, mixed> */
    public function toArray(): array { return get_object_vars($this); }
}

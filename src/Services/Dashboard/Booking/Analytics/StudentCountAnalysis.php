<?php
declare(strict_types=1);

namespace GeoFort\Services\Dashboard\Booking\Analytics;

final readonly class StudentCountAnalysis
{
    /** @param list<array<string, mixed>> $bins @param list<array<string, mixed>> $capacityBins @param list<array<string, mixed>> $programs @param array<string, string> $definitions */
    public function __construct(public array $bins, public array $capacityBins, public array $programs, public int $invalidRecordCount, public array $definitions, public string $context) {}
    /** @return array<string, mixed> */
    public function toArray(): array { return get_object_vars($this); }
}

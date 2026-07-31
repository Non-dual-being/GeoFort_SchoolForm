<?php
declare(strict_types=1);

namespace GeoFort\Services\Dashboard\Booking\Analytics;

final readonly class CateringAnalysis
{
    /** @param list<array<string, mixed>> $bookingProfiles @param list<array<string, mixed>> $schoolProfiles @param list<array<string, mixed>> $programBreakdown @param list<array<string, mixed>> $sectorBreakdown @param list<array<string, mixed>> $sizeBandBreakdown @param array<string, int> $denominators @param array<string, float> $insights @param array<string, string> $definitions */
    public function __construct(public array $bookingProfiles, public array $schoolProfiles, public array $programBreakdown, public array $sectorBreakdown, public array $sizeBandBreakdown, public array $denominators, public array $insights, public array $definitions, public string $context) {}
    /** @return array<string, mixed> */
    public function toArray(): array { return get_object_vars($this); }
}

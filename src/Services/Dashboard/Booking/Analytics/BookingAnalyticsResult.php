<?php
declare(strict_types=1);

namespace GeoFort\Services\Dashboard\Booking\Analytics;

final readonly class BookingAnalyticsResult
{
    /**
     * @param list<array<string, int|float|string>> $monthlyTrend
     * @param list<array<string, int|float|string>> $sectorDistribution
     * @param list<array<string, int|float|string>> $programDistribution
     * @param list<array<string, int|float|string>> $choiceModuleDistribution
     * @param array<string, mixed> $compositionDistribution
     * @param list<array<string, int|float|string>> $weekdayDistribution
     * @param list<array<string, int|float|string>> $busiestVisitDates
     */
    public function __construct(
        public BookingAnalyticsSummary $summary,
        public array $monthlyTrend,
        public array $sectorDistribution,
        public array $programDistribution,
        public array $choiceModuleDistribution,
        public array $compositionDistribution,
        public array $weekdayDistribution,
        public array $busiestVisitDates,
        public BookingAnalyticsDeepAnalysis $deepAnalysis,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'summary' => $this->summary->toArray(),
            'monthlyTrend' => $this->monthlyTrend,
            'sectorDistribution' => $this->sectorDistribution,
            'programDistribution' => $this->programDistribution,
            'choiceModuleDistribution' => $this->choiceModuleDistribution,
            'compositionDistribution' => $this->compositionDistribution,
            'weekdayDistribution' => $this->weekdayDistribution,
            'busiestVisitDates' => $this->busiestVisitDates,
            ...$this->deepAnalysis->toArray(),
        ];
    }
}

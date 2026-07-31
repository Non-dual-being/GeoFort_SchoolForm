<?php
declare(strict_types=1);

namespace GeoFort\Services\Dashboard\Booking\Analytics;

final readonly class BookingAnalyticsDeepAnalysis
{
    /**
     */
    public function __construct(
        public StudentCountAnalysis $studentCountAnalysis,
        public CateringAnalysis $cateringAnalysis,
        public YearlyAnalysis $yearlyAnalysis,
        public SeasonalityAnalysis $seasonalityAnalysis,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'studentCountAnalysis' => $this->studentCountAnalysis->toArray(),
            'cateringAnalysis' => $this->cateringAnalysis->toArray(),
            'yearlyAnalysis' => $this->yearlyAnalysis->toArray(),
            'seasonalityAnalysis' => $this->seasonalityAnalysis->toArray(),
        ];
    }
}

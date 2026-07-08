<?php

declare(strict_types=1);

namespace GeoFort\Services\Booking\Pricing;

final readonly class BookingPriceQuote
{
    /**
     * @param list<BookingPriceLine> $visitLines
     * @param list<BookingPriceLine> $foodLines
     */
    public function __construct(
        public array $visitLines,
        public array $foodLines,
        public int $studentCount,
        public int $supervisorCount,
        public int $freeSupervisors,
        public int $paidSupervisors,
        public float $pricePerVisitorInclVat,
        public float $visitTotalInclVat,
        public float $visitTotalExclVat,
        public float $foodTotalInclVat,
        public float $foodTotalExclVat,
        public float $totalInclVat,
        public float $totalExclVat,
        public int $vatPercentage,
    ) {}

    public function toArray(): array
    {
        return [
            'vatPercentage' => $this->vatPercentage,
            'visit' => [
                'studentCount' => $this->studentCount,
                'supervisorCount' => $this->supervisorCount,
                'freeSupervisors' => $this->freeSupervisors,
                'paidSupervisors' => $this->paidSupervisors,
                'pricePerVisitorInclVat' => $this->pricePerVisitorInclVat,
                'lines' => array_map(
                    static fn (BookingPriceLine $line): array => $line->toArray(),
                    $this->visitLines,
                ),
                'totalInclVat' => $this->visitTotalInclVat,
                'totalExclVat' => $this->visitTotalExclVat,
            ],
            'foodAndDrink' => [
                'lines' => array_map(
                    static fn (BookingPriceLine $line): array => $line->toArray(),
                    $this->foodLines,
                ),
                'totalInclVat' => $this->foodTotalInclVat,
                'totalExclVat' => $this->foodTotalExclVat,
            ],
            'total' => [
                'totalInclVat' => $this->totalInclVat,
                'totalExclVat' => $this->totalExclVat,
            ],
        ];
    }
}

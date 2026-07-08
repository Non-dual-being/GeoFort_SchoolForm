<?php

declare(strict_types=1);

namespace GeoFort\Services\Booking\Pricing;

use GeoFort\Booking\BookingPolicy;
use GeoFort\Booking\BookingProgramConfig;
use GeoFort\Services\Booking\Data\FoodAndDrinkSelectionData;
use InvalidArgumentException;

final class BookingPriceCalculator
{
    private const VAT_PERCENTAGE = 9;
    private const VAT_FACTOR = 1.09;

    public function calculate(
        string $schoolSector,
        string $program,
        int $studentCount,
        int $supervisorCount,
        FoodAndDrinkSelectionData $foodAndDrinkSelection,
    ): BookingPriceQuote {
        $pricePerVisitor = $this->getPricePerVisitor(
            schoolSector: $schoolSector,
            program: $program,
        );

        $freeSupervisors = BookingPolicy::getFreeSupervisorCount($studentCount);
        $paidSupervisors = max(0, $supervisorCount - $freeSupervisors);

        $visitLines = $this->calculateVisitLines(
            studentCount: $studentCount,
            paidSupervisors: $paidSupervisors,
            pricePerVisitor: $pricePerVisitor,
        );

        $foodLines = $this->calculateFoodLines($foodAndDrinkSelection);

        $visitTotalInclVat = $this->sumLines($visitLines);
        $foodTotalInclVat = $this->sumLines($foodLines);
        $totalInclVat = $this->roundMoney($visitTotalInclVat + $foodTotalInclVat);

        return new BookingPriceQuote(
            visitLines: $visitLines,
            foodLines: $foodLines,
            studentCount: $studentCount,
            supervisorCount: $supervisorCount,
            freeSupervisors: $freeSupervisors,
            paidSupervisors: $paidSupervisors,
            pricePerVisitorInclVat: $pricePerVisitor,
            visitTotalInclVat: $visitTotalInclVat,
            visitTotalExclVat: $this->calculateExclVat($visitTotalInclVat),
            foodTotalInclVat: $foodTotalInclVat,
            foodTotalExclVat: $this->calculateExclVat($foodTotalInclVat),
            totalInclVat: $totalInclVat,
            totalExclVat: $this->calculateExclVat($totalInclVat),
            vatPercentage: self::VAT_PERCENTAGE,
        );
    }

    private function getPricePerVisitor(string $schoolSector, string $program): float
    {
        $priceType = BookingProgramConfig::getPriceTypeForSchoolSector($schoolSector);
        $price = BookingProgramConfig::PRICES['bezoek'][$program][$priceType] ?? null;

        if (!is_int($price) && !is_float($price)) {
            throw new InvalidArgumentException(
                "Missing visit price for {$program} and {$priceType}.",
            );
        }

        return $this->roundMoney((float) $price);
    }

    /**
     * @return list<BookingPriceLine>
     */
    private function calculateVisitLines(
        int $studentCount,
        int $paidSupervisors,
        float $pricePerVisitor,
    ): array {
        $lines = [
            new BookingPriceLine(
                key: 'students',
                label: $studentCount . ' ' . ($studentCount === 1 ? 'leerling' : 'leerlingen'),
                quantity: $studentCount,
                unitPriceInclVat: $pricePerVisitor,
                totalInclVat: $this->roundMoney($studentCount * $pricePerVisitor),
            ),
        ];

        if ($paidSupervisors > 0) {
            $lines[] = new BookingPriceLine(
                key: 'paid_supervisors',
                label: $paidSupervisors . ' te betalen '
                    . ($paidSupervisors === 1 ? 'begeleider' : 'begeleiders'),
                quantity: $paidSupervisors,
                unitPriceInclVat: $pricePerVisitor,
                totalInclVat: $this->roundMoney($paidSupervisors * $pricePerVisitor),
            );
        }

        return $lines;
    }

    /**
     * @return list<BookingPriceLine>
     */
    private function calculateFoodLines(
        FoodAndDrinkSelectionData $foodAndDrinkSelection,
    ): array {
        $lines = [];

        foreach ($foodAndDrinkSelection->orderedQuantities() as $key => $quantity) {
            $unitPrice = $this->getFoodUnitPrice($key);

            if ($unitPrice <= 0.0) {
                continue;
            }

            $lines[] = new BookingPriceLine(
                key: $key,
                label: $quantity . ' ' . BookingProgramConfig::getFoodAndDrinkOptionLabel($key),
                quantity: $quantity,
                unitPriceInclVat: $unitPrice,
                totalInclVat: $this->roundMoney($quantity * $unitPrice),
            );
        }

        return $lines;
    }

    private function getFoodUnitPrice(string $key): float
    {
        foreach (['snacks', 'lunch'] as $category) {
            $price = BookingProgramConfig::PRICES[$category][$key] ?? null;

            if (is_int($price) || is_float($price)) {
                return $this->roundMoney((float) $price);
            }
        }

        throw new InvalidArgumentException("Missing food price for {$key}.");
    }

    /**
     * @param list<BookingPriceLine> $lines
     */
    private function sumLines(array $lines): float
    {
        return $this->roundMoney(
            array_reduce(
                $lines,
                static fn (float $sum, BookingPriceLine $line): float => $sum + $line->totalInclVat,
                0.0,
            ),
        );
    }

    private function calculateExclVat(float $amountInclVat): float
    {
        return $this->roundMoney($amountInclVat / self::VAT_FACTOR);
    }

    private function roundMoney(float $amount): float
    {
        return round($amount, 2);
    }
}

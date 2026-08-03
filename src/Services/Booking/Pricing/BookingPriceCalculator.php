<?php

declare(strict_types=1);

namespace GeoFort\Services\Booking\Pricing;

use GeoFort\Booking\BookingProgramConfig;
use GeoFort\Services\Booking\Data\FoodAndDrinkSelectionData;
use InvalidArgumentException;

final class BookingPriceCalculator
{
    public function __construct(private readonly BookingPriceCatalogRegistry $catalogs = new BookingPriceCatalogRegistry()) {}

    public function calculate(
        string $schoolSector,
        string $program,
        int $studentCount,
        int $supervisorCount,
        FoodAndDrinkSelectionData $foodAndDrinkSelection,
        ?string $pricingVersion = null,
    ): BookingPriceQuote {
        return $this->calculateInput(new BookingPricingInput($schoolSector, $program, $studentCount, $supervisorCount, $foodAndDrinkSelection), $pricingVersion);
    }

    public function calculateInput(BookingPricingInput $input, ?string $pricingVersion = null): BookingPriceQuote
    {
        $this->validateFoodAndDrink($input->foodAndDrink);
        $catalog = $pricingVersion === null ? $this->catalogs->active() : $this->catalogs->get($pricingVersion);
        $priceType = $catalog->priceTypeForSector($input->schoolSector);
        $visitUnit = $catalog->visitPriceCents($input->program, $priceType);
        $free = $catalog->freeSupervisorCount($input->studentCount);
        $paid = max(0, $input->supervisorCount - $free);
        $visitLines = [new BookingPriceLine('students', $input->studentCount . ' ' . ($input->studentCount === 1 ? 'leerling' : 'leerlingen'), $input->studentCount, $visitUnit, $this->multiply($input->studentCount, $visitUnit))];
        if ($paid > 0) $visitLines[] = new BookingPriceLine('paid_supervisors', $paid . ' te betalen ' . ($paid === 1 ? 'begeleider' : 'begeleiders'), $paid, $visitUnit, $this->multiply($paid, $visitUnit));
        $foodLines = [];
        foreach ($input->foodAndDrink->orderedQuantities() as $key => $quantity) {
            $unit = $catalog->cateringPriceCents($key);
            if ($unit > 0) $foodLines[] = new BookingPriceLine($key, $quantity . ' ' . BookingProgramConfig::getFoodAndDrinkOptionLabel($key), $quantity, $unit, $this->multiply($quantity, $unit));
        }
        $visit = array_sum(array_map(static fn (BookingPriceLine $line): int => $line->totalInclVatCents, $visitLines));
        $catering = array_sum(array_map(static fn (BookingPriceLine $line): int => $line->totalInclVatCents, $foodLines));
        $total = $visit + $catering;
        $visitExcl = $catalog->amountExcludingVatCents($visit);
        $cateringExcl = $catalog->amountExcludingVatCents($catering);
        $excl = $catalog->amountExcludingVatCents($total);
        return new BookingPriceQuote($visitLines, $foodLines, $input->studentCount, $input->supervisorCount, $free, $paid, 'complete', $catalog->version, $catalog->currencyCode, $visitUnit, $visit, $visitExcl, $catering, $cateringExcl, $total, $excl, $total - $excl, $catalog->vatBasisPoints);
    }

    private function multiply(int $quantity, int $unitCents): int
    {
        if ($quantity < 0 || $unitCents < 0 || ($unitCents !== 0 && $quantity > intdiv(PHP_INT_MAX, $unitCents))) throw new InvalidArgumentException('PRICE_CALCULATION_INVALID');
        return $quantity * $unitCents;
    }

    private function validateFoodAndDrink(FoodAndDrinkSelectionData $food): void
    {
        foreach([$food->remiseBreak,$food->kazerneBreak,$food->fortgrachtBreak,$food->waterijsje,$food->glasLimonade,$food->remiseLunch] as $quantity)if($quantity<0)throw new InvalidArgumentException('PRICE_CALCULATION_INVALID');
        $valid=match($food->lunchChoice){
            FoodAndDrinkSelectionData::LUNCH_NONE=>$food->remiseLunch===0&&!$food->eigenPicknick,
            FoodAndDrinkSelectionData::LUNCH_REMISE=>$food->remiseLunch>0&&!$food->eigenPicknick,
            FoodAndDrinkSelectionData::LUNCH_OWN_PICNIC=>$food->remiseLunch===0&&$food->eigenPicknick,
            default=>false,
        };
        if(!$valid)throw new InvalidArgumentException('PRICE_CALCULATION_INVALID');
    }
}

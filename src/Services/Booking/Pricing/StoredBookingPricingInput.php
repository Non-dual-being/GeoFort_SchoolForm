<?php
declare(strict_types=1);
namespace GeoFort\Services\Booking\Pricing;

use GeoFort\Services\Booking\Data\FoodAndDrinkSelectionData;

final readonly class StoredBookingPricingInput
{
    public function __construct(public string $schoolSector, public string $program, public int $studentCount, public int $supervisorCount, public FoodAndDrinkSelectionData $foodAndDrinkSelection) {}
}

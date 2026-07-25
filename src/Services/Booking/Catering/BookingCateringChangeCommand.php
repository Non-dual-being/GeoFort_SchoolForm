<?php

declare(strict_types=1);

namespace GeoFort\Services\Booking\Catering;

use GeoFort\Services\Booking\Data\FoodAndDrinkSelectionData;

final readonly class BookingCateringChangeCommand
{
    public function __construct(
        public int $bookingId,
        public BookingCateringValues $expected,
        public FoodAndDrinkSelectionData $proposed,
        public int $actingAdminId,
    ) {}
}

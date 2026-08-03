<?php

declare(strict_types=1);

namespace GeoFort\Services\Booking\Pricing;

use GeoFort\Booking\Stored\StoredBooking;
use GeoFort\Services\Booking\Data\BookingRequestData;
use GeoFort\Services\Booking\Data\FoodAndDrinkSelectionData;
use InvalidArgumentException;

final readonly class BookingPricingInput
{
    public function __construct(
        public string $schoolSector,
        public string $program,
        public int $studentCount,
        public int $supervisorCount,
        public FoodAndDrinkSelectionData $foodAndDrink,
    ) {
        if ($studentCount <= 0 || $supervisorCount < 0) throw new InvalidArgumentException('PRICE_CALCULATION_INVALID');
    }

    public static function fromRequest(BookingRequestData $request): self
    {
        return new self($request->schoolSector, $request->programma, $request->aantalLeerlingen, $request->aantalBegeleiders, $request->foodAndDrinkSelection);
    }

    public static function fromStoredBooking(StoredBooking $booking): self
    {
        if ($booking->studentCount === null || $booking->supervisorCount === null) throw new InvalidArgumentException('PRICE_CALCULATION_INVALID');
        return new self($booking->schoolSector, $booking->program, $booking->studentCount, $booking->supervisorCount, $booking->foodAndDrinkSelection);
    }

    /** @return array<string, string|int|bool> */
    public function canonicalData(): array
    {
        return [
            'schoolSector' => $this->schoolSector,
            'program' => $this->program,
            'studentCount' => $this->studentCount,
            'supervisorCount' => $this->supervisorCount,
            'remiseBreak' => $this->foodAndDrink->remiseBreak,
            'kazerneBreak' => $this->foodAndDrink->kazerneBreak,
            'fortgrachtBreak' => $this->foodAndDrink->fortgrachtBreak,
            'waterIce' => $this->foodAndDrink->waterijsje,
            'lemonade' => $this->foodAndDrink->glasLimonade,
            'lunchChoice' => $this->foodAndDrink->lunchChoice,
            'remiseLunch' => $this->foodAndDrink->remiseLunch,
            'ownPicnic' => $this->foodAndDrink->eigenPicknick,
        ];
    }
}

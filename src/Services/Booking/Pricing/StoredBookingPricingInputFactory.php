<?php
declare(strict_types=1);
namespace GeoFort\Services\Booking\Pricing;

use GeoFort\Booking\Stored\StoredBooking;
use InvalidArgumentException;

final class StoredBookingPricingInputFactory
{
    public function fromStoredBooking(StoredBooking $booking): StoredBookingPricingInput
    {
        return $this->build($booking, $booking->studentCount);
    }

    public function forStudentCountPreview(StoredBooking $booking, int $previewStudentCount): StoredBookingPricingInput
    {
        return $this->build($booking, $previewStudentCount);
    }

    private function build(StoredBooking $booking, ?int $students): StoredBookingPricingInput
    {
        if ($students === null || $students <= 0 || $booking->supervisorCount === null || $booking->supervisorCount <= 0) throw new InvalidArgumentException('Prijsinput vereist positieve aantallen.');
        return new StoredBookingPricingInput($booking->schoolSector, $booking->program, $students, $booking->supervisorCount, $booking->foodAndDrinkSelection);
    }

    public function calculate(StoredBookingPricingInput $input, BookingPriceCalculator $calculator): BookingPriceQuote
    {
        return $calculator->calculate($input->schoolSector, $input->program, $input->studentCount, $input->supervisorCount, $input->foodAndDrinkSelection);
    }
}

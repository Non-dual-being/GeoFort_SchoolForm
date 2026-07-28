<?php

declare(strict_types=1);

namespace GeoFort\Booking\Cjp;

use GeoFort\Booking\Stored\StoredBooking;

final readonly class BookingCjpDetails
{
    public function __construct(
        public string $useCjp,
        public ?string $contactName,
        public ?string $cardNumber,
    ) {}

    public static function fromStoredBooking(StoredBooking $booking): self
    {
        return new self($booking->cjpPassUse, $booking->cjpContactName, $booking->cjpPassNumber);
    }

    /** @return array{useCjp:string,contactName:?string,cardNumber:?string} */
    public function toArray(): array
    {
        return ['useCjp'=>$this->useCjp, 'contactName'=>$this->contactName, 'cardNumber'=>$this->cardNumber];
    }
}

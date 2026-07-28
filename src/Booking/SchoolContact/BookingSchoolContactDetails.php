<?php

declare(strict_types=1);

namespace GeoFort\Booking\SchoolContact;

use GeoFort\Booking\Stored\StoredBooking;

final readonly class BookingSchoolContactDetails
{
    public function __construct(
        public string $schoolName,
        public string $country,
        public string $address,
        public string $postalCode,
        public string $city,
        public string $schoolPhone,
        public string $contactFirstName,
        public string $contactLastName,
        public string $contactEmail,
        public string $contactPhone,
    ) {}

    public static function fromStoredBooking(StoredBooking $booking): self
    {
        return new self(
            $booking->schoolName, $booking->country, $booking->address,
            $booking->postalCode, $booking->city, $booking->schoolPhone,
            $booking->contactFirstName, $booking->contactLastName,
            $booking->email, $booking->contactPhone,
        );
    }

    /** @return array<string,string> */
    public function toArray(): array
    {
        return [
            'schoolName'=>$this->schoolName, 'country'=>$this->country,
            'address'=>$this->address, 'postalCode'=>$this->postalCode,
            'city'=>$this->city, 'schoolPhone'=>$this->schoolPhone,
            'contactFirstName'=>$this->contactFirstName,
            'contactLastName'=>$this->contactLastName,
            'contactEmail'=>$this->contactEmail, 'contactPhone'=>$this->contactPhone,
        ];
    }
}

<?php
declare(strict_types=1);

namespace GeoFort\Services\Booking;

final class BookingRequestData
{
    public function __construct(
        public readonly string $schoolnaam,
        public readonly string $land,
        public readonly string $postcode,
        public readonly string $adres,
        public readonly string $plaats,
        public readonly string $schoolTelefoonnummer,
        public readonly string $contactpersoonTelefoonnummer,
        public readonly string $contactpersoonVoornaam,
        public readonly string $contactpersoonAchternaam,
        public readonly string $email
    ) {}
}
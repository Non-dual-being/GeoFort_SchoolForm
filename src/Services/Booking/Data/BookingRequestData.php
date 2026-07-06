<?php

declare(strict_types=1);

namespace GeoFort\Services\Booking\Data;

final readonly class BookingRequestData
{
    public function __construct(
        public string $schoolnaam,
        public string $land,
        public string $adres,
        public string $postcode,
        public string $plaats,
        public string $schoolTelefoonnummer,
        public string $contactpersoonTelefoonnummer,
        public string $contactpersoonVoornaam,
        public string $contactpersoonAchternaam,
        public string $email,

        /**
         * Technische datum voor database/capaciteit.
         * Formaat: Y-m-d
         */
        public string $bezoekdatum,

        /**
         * Presentatiedatum voor mail.
         * Bijvoorbeeld: woensdag 23 september 2026
         */
        public string $bezoekdatumLabel,

        public string $hoeKentUGeoFort,
        public string $cjpPasGebruik,
        public ?string $cjpContactpersoonNaam,
        public ?string $cjpPasnummer,

        public string $schoolSector,
        public string $programma,
        public ?string $keuzemoduleKey,
        public int $aantalLeerlingen,
        public int $aantalBegeleiders,

        public EducationSelectionData $educationSelection,
    ) {}
}

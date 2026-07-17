<?php

declare(strict_types=1);

namespace GeoFort\Booking\Stored;

use GeoFort\Services\Booking\Data\EducationSelectionData;
use GeoFort\Services\Booking\Data\FoodAndDrinkSelectionData;

final readonly class StoredBooking
{
    public function __construct(
        public int $id,
        public string $status,
        public string $visitDate,
        public string $schoolName,
        public string $country,
        public string $address,
        public string $postalCode,
        public string $city,
        public string $schoolPhone,
        public string $contactPhone,
        public string $contactFirstName,
        public string $contactLastName,
        public string $email,
        public ?string $discoverySource,
        public string $cjpPassUse,
        public ?string $cjpContactName,
        public ?string $cjpPassNumber,
        public string $schoolSector,
        public string $program,
        public ?string $choiceModuleKey,
        public ?int $studentCount,
        public ?int $supervisorCount,
        public EducationSelectionData $educationSelection,
        public FoodAndDrinkSelectionData $foodAndDrinkSelection,
        public ?string $comments,
        public bool $termsAccepted,
        public ?string $termsAcceptedAt,
        public BookingSourceMetadata $source,
    ) {}
}

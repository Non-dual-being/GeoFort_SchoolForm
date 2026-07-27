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

    public function withAttendance(int $studentCount, int $supervisorCount): self
    {
        return new self(
            $this->id, $this->status, $this->visitDate, $this->schoolName, $this->country,
            $this->address, $this->postalCode, $this->city, $this->schoolPhone,
            $this->contactPhone, $this->contactFirstName, $this->contactLastName, $this->email,
            $this->discoverySource, $this->cjpPassUse, $this->cjpContactName, $this->cjpPassNumber,
            $this->schoolSector, $this->program, $this->choiceModuleKey, $studentCount,
            $supervisorCount, $this->educationSelection, $this->foodAndDrinkSelection,
            $this->comments, $this->termsAccepted, $this->termsAcceptedAt, $this->source,
        );
    }

    public function withFoodAndDrink(FoodAndDrinkSelectionData $selection): self
    {
        return new self(
            $this->id, $this->status, $this->visitDate, $this->schoolName, $this->country,
            $this->address, $this->postalCode, $this->city, $this->schoolPhone,
            $this->contactPhone, $this->contactFirstName, $this->contactLastName, $this->email,
            $this->discoverySource, $this->cjpPassUse, $this->cjpContactName, $this->cjpPassNumber,
            $this->schoolSector, $this->program, $this->choiceModuleKey, $this->studentCount,
            $this->supervisorCount, $this->educationSelection, $selection,
            $this->comments, $this->termsAccepted, $this->termsAcceptedAt, $this->source,
        );
    }

    public function withVisitDate(string $visitDate): self
    {
        return new self(
            $this->id, $this->status, $visitDate, $this->schoolName, $this->country,
            $this->address, $this->postalCode, $this->city, $this->schoolPhone,
            $this->contactPhone, $this->contactFirstName, $this->contactLastName, $this->email,
            $this->discoverySource, $this->cjpPassUse, $this->cjpContactName, $this->cjpPassNumber,
            $this->schoolSector, $this->program, $this->choiceModuleKey, $this->studentCount,
            $this->supervisorCount, $this->educationSelection, $this->foodAndDrinkSelection,
            $this->comments, $this->termsAccepted, $this->termsAcceptedAt, $this->source,
        );
    }

    public function withProgram(string $program): self
    {
        return new self(
            $this->id, $this->status, $this->visitDate, $this->schoolName, $this->country,
            $this->address, $this->postalCode, $this->city, $this->schoolPhone,
            $this->contactPhone, $this->contactFirstName, $this->contactLastName, $this->email,
            $this->discoverySource, $this->cjpPassUse, $this->cjpContactName, $this->cjpPassNumber,
            $this->schoolSector, $program, $this->choiceModuleKey, $this->studentCount,
            $this->supervisorCount, $this->educationSelection, $this->foodAndDrinkSelection,
            $this->comments, $this->termsAccepted, $this->termsAcceptedAt, $this->source,
        );
    }
}

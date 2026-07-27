<?php
declare(strict_types=1);
namespace GeoFort\Booking\ProgramConfiguration;

use GeoFort\Booking\Stored\StoredBooking;
use GeoFort\Services\Booking\Data\EducationSelectionData;

final readonly class BookingProgramConfigurationSnapshot
{
    public function __construct(
        public string $status,
        public string $visitDate,
        public string $program,
        public int $studentCount,
        public EducationSelectionData $educationSelection,
        public ?string $choiceModule,
    ) {}

    public static function fromBooking(StoredBooking $booking): self
    {
        return new self(
            $booking->status,
            $booking->visitDate,
            $booking->program,
            $booking->studentCount ?? 0,
            $booking->educationSelection,
            $booking->choiceModuleKey,
        );
    }

    public function equals(self $other): bool
    {
        return $this->status === $other->status
            && $this->visitDate === $other->visitDate
            && $this->program === $other->program
            && $this->studentCount === $other->studentCount
            && $this->choiceModule === $other->choiceModule
            && $this->educationSelection->toArray() === $other->educationSelection->toArray();
    }
}

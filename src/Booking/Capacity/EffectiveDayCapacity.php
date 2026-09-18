<?php
declare(strict_types=1);
namespace GeoFort\Booking\Capacity;

use GeoFort\Booking\BookingPolicy;

final readonly class EffectiveDayCapacity
{
    public function __construct(
        public int $standardMaxSchools,
        public int $standardMaxStudents,
        public ?int $overrideMaxSchools,
        public ?int $overrideMaxStudents,
        public int $effectiveMaxSchools,
        public int $effectiveMaxStudents,
    ) {}

    public function studentsForProgram(string $program): int
    {
        return $program === BookingPolicy::PROGRAM_MORNING
            ? min($this->effectiveMaxStudents, BookingPolicy::getMaxStudentsOfProgram($program))
            : $this->effectiveMaxStudents;
    }
}

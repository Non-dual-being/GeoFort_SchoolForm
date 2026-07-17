<?php
declare(strict_types=1);
namespace GeoFort\Booking\Capacity;

final readonly class CapacityValidationResult
{
    public function __construct(
        public CapacityValidationCode $code,
        public bool $allowed,
        public int $projectedSchools,
        public ?int $projectedStudents,
    ) {}
}

<?php
declare(strict_types=1);
namespace GeoFort\Booking\Capacity;

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
}

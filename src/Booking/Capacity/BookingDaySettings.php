<?php
declare(strict_types=1);
namespace GeoFort\Booking\Capacity;

use InvalidArgumentException;

final readonly class BookingDaySettings
{
    public string $visitDate;
    public ?int $maxSchoolsOverride;
    public ?int $maxStudentsOverride;

    public function __construct(string $visitDate, ?int $maxSchoolsOverride, ?int $maxStudentsOverride)
    {
        if ($maxSchoolsOverride !== null && $maxSchoolsOverride <= 0) {
            throw new InvalidArgumentException('Maximaal aantal scholen moet groter dan nul zijn.');
        }
        if ($maxStudentsOverride !== null && $maxStudentsOverride <= 0) {
            throw new InvalidArgumentException('Maximaal aantal leerlingen moet groter dan nul zijn.');
        }

        $this->visitDate = $visitDate;
        $this->maxSchoolsOverride = $maxSchoolsOverride;
        $this->maxStudentsOverride = $maxStudentsOverride;
    }
}

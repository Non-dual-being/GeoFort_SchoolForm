<?php
declare(strict_types=1);
namespace GeoFort\Booking\Capacity;

final readonly class StandardDayCapacity
{
    public function __construct(public int $maxSchools, public int $maxStudents) {}
}

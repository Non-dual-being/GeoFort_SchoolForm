<?php
declare(strict_types=1);
namespace GeoFort\Booking\Capacity;

final readonly class DayCapacityTotals
{
    public function __construct(public int $confirmedSchools, public int $confirmedStudents) {}
}

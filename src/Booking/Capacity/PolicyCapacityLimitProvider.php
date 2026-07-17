<?php
declare(strict_types=1);
namespace GeoFort\Booking\Capacity;

use GeoFort\Booking\BookingPolicy;

final class PolicyCapacityLimitProvider implements CapacityLimitProvider
{
    public function standard(): StandardDayCapacity
    {
        return new StandardDayCapacity(BookingPolicy::MAX_SCHOOLS_PER_DAY, BookingPolicy::MAX_STUDENTS_TOTAL_PER_DAY);
    }

    public function forDate(string $visitDate): EffectiveDayCapacity
    {
        $standard = $this->standard();
        return new EffectiveDayCapacity($standard->maxSchools, $standard->maxStudents, null, null, $standard->maxSchools, $standard->maxStudents);
    }
}

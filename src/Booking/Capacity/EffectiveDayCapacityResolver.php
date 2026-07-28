<?php

declare(strict_types=1);

namespace GeoFort\Booking\Capacity;

final class EffectiveDayCapacityResolver
{
    public function resolve(EffectiveDayCapacity $standard, ?BookingDaySettings $settings): EffectiveDayCapacity
    {
        return new EffectiveDayCapacity(
            $standard->standardMaxSchools,
            $standard->standardMaxStudents,
            $settings?->maxSchoolsOverride,
            $settings?->maxStudentsOverride,
            $settings?->maxSchoolsOverride ?? $standard->effectiveMaxSchools,
            $settings?->maxStudentsOverride ?? $standard->effectiveMaxStudents,
        );
    }
}

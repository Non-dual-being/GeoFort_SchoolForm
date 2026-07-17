<?php
declare(strict_types=1);
namespace GeoFort\Booking\Capacity;

interface CapacityLimitProvider
{
    public function forDate(string $visitDate): EffectiveDayCapacity;
}

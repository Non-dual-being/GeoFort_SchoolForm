<?php
declare(strict_types=1);
namespace GeoFort\Booking\Rules;

enum BookingRuleOverrideScope: string
{
    case SingleOperation = 'single_operation';
}

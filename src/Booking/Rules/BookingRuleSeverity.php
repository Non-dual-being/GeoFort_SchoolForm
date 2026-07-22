<?php
declare(strict_types=1);
namespace GeoFort\Booking\Rules;

enum BookingRuleSeverity: string
{
    case Error = 'error';
    case Warning = 'warning';
    case Information = 'information';
}

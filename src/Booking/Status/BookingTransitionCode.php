<?php
declare(strict_types=1);
namespace GeoFort\Booking\Status;

enum BookingTransitionCode: string
{
    case Allowed = 'ALLOWED';
    case InvalidCurrentStatus = 'INVALID_CURRENT_STATUS';
    case InvalidTargetStatus = 'INVALID_TARGET_STATUS';
    case NoStatusChange = 'NO_STATUS_CHANGE';
}

<?php

declare(strict_types=1);

namespace GeoFort\Booking\Status;

enum BookingStatusMailMode: string
{
    case None = 'none';
    case Send = 'send';
}

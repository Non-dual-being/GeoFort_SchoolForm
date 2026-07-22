<?php
declare(strict_types=1);
namespace GeoFort\Booking\Rules;

use InvalidArgumentException;

final readonly class BookingRuleOverrideRequest
{
    public string $reason;

    public function __construct(public string $ruleCode, string $reason)
    {
        $reason = trim($reason);
        $length = mb_strlen($reason, 'UTF-8');
        if ($length < 15 || $length > 500) {
            throw new InvalidArgumentException('Override reason must contain between 15 and 500 characters.');
        }
        $this->reason = $reason;
    }
}

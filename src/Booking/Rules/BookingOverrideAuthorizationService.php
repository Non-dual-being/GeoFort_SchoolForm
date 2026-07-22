<?php
declare(strict_types=1);
namespace GeoFort\Booking\Rules;

interface BookingOverrideAuthorizationService
{
    public function isAllowed(int $actingAdminId, BookingRuleDefinition $rule): bool;
}

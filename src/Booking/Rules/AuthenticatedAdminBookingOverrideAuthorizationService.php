<?php
declare(strict_types=1);
namespace GeoFort\Booking\Rules;

/** Temporary adapter until the admin application has fine-grained permissions. */
final class AuthenticatedAdminBookingOverrideAuthorizationService implements BookingOverrideAuthorizationService
{
    public function isAllowed(int $actingAdminId, BookingRuleDefinition $rule): bool
    {
        return $actingAdminId > 0 && $rule->overridable && $rule->requiredPermission === BookingRuleOverridePolicy::PERMISSION_OVERRIDE;
    }
}

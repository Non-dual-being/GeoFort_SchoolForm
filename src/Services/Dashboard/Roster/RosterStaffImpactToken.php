<?php

declare(strict_types=1);

namespace GeoFort\Services\Dashboard\Roster;

use LogicException;

/** Signed with a private session secret, never with the client-visible CSRF token. */
final class RosterStaffImpactToken
{
    public static function issue(array $context, string $secret, ?int $now = null): string
    {
        $expiry = ($now ?? time()) + 300;
        return $expiry . '.' . self::signature($context, $secret, $expiry);
    }

    public static function verify(?string $token, array $context, string $secret, ?int $now = null): void
    {
        $now ??= time();
        if ($token === null || preg_match('/^([0-9]{10})\.([a-f0-9]{64})$/D', $token, $parts) !== 1
            || (int) $parts[1] < $now || (int) $parts[1] > $now + 300
            || !hash_equals(self::signature($context, $secret, (int) $parts[1]), $parts[2])) {
            throw new RosterPlanException('STALE_STAFF_IMPACT', 409, 'De impactcontrole is verlopen of de personeels- of roostergegevens zijn gewijzigd. Controleer de gevolgen opnieuw.');
        }
    }

    private static function signature(array $context, string $secret, int $expiry): string
    {
        if (strlen($secret) < 32) throw new LogicException('A private impact signing secret is required.');
        return hash_hmac('sha256', $expiry . ':' . json_encode($context, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $secret);
    }
}

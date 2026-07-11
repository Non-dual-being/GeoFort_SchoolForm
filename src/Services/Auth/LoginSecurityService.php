<?php
declare(strict_types=1);

namespace GeoFort\Services\Auth;

use GeoFort\Services\Sql\LoginAttemptsSqlService;

final class LoginSecurityService
{
    public function __construct(
        private readonly LoginAttemptsSqlService $attempts,
        private readonly int $threshold,
        private readonly int $windowSeconds,
        private readonly int $baseLockoutSeconds,
        private readonly int $maxLockoutSeconds,
    ) {}

    public function isLockedOut(string $email, string $ip): bool
    {
        return $this->attempts->hasActiveLockout($email, $ip);
    }

    public function registerFailure(string $email, string $ip, ?string $userAgent): void
    {
        $failures = max(
            $this->attempts->countRecentFailuresByEmail($email, $this->windowSeconds),
            $this->attempts->countRecentFailuresByIp($ip, $this->windowSeconds),
        ) + 1;
        $lockout = null;
        if ($failures >= $this->threshold) {
            $tier = intdiv($failures - $this->threshold, $this->threshold);
            $lockout = min($this->maxLockoutSeconds, $this->baseLockoutSeconds * (5 ** $tier));
        }
        $this->attempts->recordFailure($email, $ip, $userAgent, $lockout);
    }

    public function registerSuccess(string $email, string $ip, ?string $userAgent): void
    {
        $this->attempts->recordSuccess($email, $ip, $userAgent);
    }
}

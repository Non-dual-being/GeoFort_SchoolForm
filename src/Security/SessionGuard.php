<?php
declare(strict_types=1);

namespace GeoFort\Security;

use GeoFort\Services\Sql\AdminUsersSqlService;

final class SessionGuard
{
    private ?string $failureReason = null;

    public function __construct(
        private readonly AdminUsersSqlService $users,
        private readonly int $inactivityTimeout,
        private readonly int $revalidationSeconds,
    ) {}

    public function validate(string $currentUserAgent): bool
    {
        $required = [
            'loggedin' => static fn(mixed $v): bool => $v === true,
            'user_id' => static fn(mixed $v): bool => is_int($v) && $v > 0,
            'user_email' => static fn(mixed $v): bool => is_string($v) && $v !== '',
            'user_name' => 'is_string',
            'user_role' => 'is_string',
            'LAST_ACTIVITY' => 'is_int',
            'last_revalidation_time' => 'is_int',
            'user_agent' => 'is_string',
        ];
        foreach ($required as $key => $validator) {
            if (!array_key_exists($key, $_SESSION) || !$validator($_SESSION[$key])) return $this->fail('invalid session shape');
        }
        if (!hash_equals($_SESSION['user_agent'], $currentUserAgent)) return $this->fail('user agent changed');
        $now = time();
        if (($now - $_SESSION['LAST_ACTIVITY']) > $this->inactivityTimeout) return $this->fail('inactivity timeout');
        if (($now - $_SESSION['last_revalidation_time']) >= $this->revalidationSeconds) {
            try {
                $user = $this->users->findValidUserByIdAndEmail($_SESSION['user_id'], $_SESSION['user_email']);
            } catch (\Throwable $e) {
                error_log('[SessionGuard] Revalidation query failed: ' . $e->getMessage());
                return $this->fail('revalidation error');
            }
            if ($user === null) return $this->fail('admin no longer valid');
            $_SESSION['user_name'] = (string) $user['name'];
            $_SESSION['user_role'] = (string) $user['role'];
            $_SESSION['last_revalidation_time'] = $now;
        }
        $_SESSION['LAST_ACTIVITY'] = $now;
        $this->failureReason = null;
        return true;
    }

    public function failureReason(): ?string
    {
        return $this->failureReason;
    }

    private function fail(string $reason): bool
    {
        $this->failureReason = $reason;
        return false;
    }
}

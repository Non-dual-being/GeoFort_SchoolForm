<?php
declare(strict_types=1);

namespace GeoFort\Services\Auth;

use GeoFort\Services\Sql\AdminUsersSqlService;

final class LoginService
{
    public function __construct(private readonly AdminUsersSqlService $users, private readonly LoginSecurityService $security) {}

    public function login(string $email, string $password, string $ip, ?string $userAgent): LoginResult
    {
        $email = strtolower(trim($email));
        if ($this->security->isLockedOut($email, $ip)) {
            return new LoginResult(LoginStatus::LockedOut);
        }
        $user = $this->users->findByEmail($email);
        if ($user === null || (int) ($user['is_active'] ?? 0) !== 1 || !password_verify($password, (string) ($user['password_hash'] ?? ''))) {
            $this->security->registerFailure($email, $ip, $userAgent);
            return new LoginResult(LoginStatus::InvalidCredentials);
        }
        $previousLoginAt = isset($user['last_login_at']) ? (string) $user['last_login_at'] : null;
        $this->security->registerSuccess($email, $ip, $userAgent);
        $this->users->updateLastLoginAt((int) $user['id']);
        unset($user['password_hash'], $user['is_active'], $user['last_login_at']);
        return new LoginResult(LoginStatus::Success, $user, $previousLoginAt ?: null);
    }
}

<?php
declare(strict_types=1);

namespace GeoFort\Security;

final class AuthMiddleware
{
    public function __construct(
        private readonly string $cookieName,
        private readonly string $sameSite,
        private readonly bool $secureCookie,
    ) {}

    public function startPublicSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) return;
        ini_set('session.use_only_cookies', '1');
        ini_set('session.use_strict_mode', '1');
        ini_set('session.cookie_httponly', '1');
        session_name($this->cookieName);
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'secure' => $this->secureCookie,
            'httponly' => true,
            'samesite' => $this->sameSite,
        ]);
        if (!session_start()) throw new \RuntimeException('Unable to start session');
    }

    public function establishAuthenticatedSession(array $user, ?string $previousLoginAt, string $userAgent): void
    {
        session_regenerate_id(true);
        $_SESSION['loggedin'] = true;
        $_SESSION['user_id'] = (int) $user['id'];
        $_SESSION['user_email'] = (string) $user['email'];
        $_SESSION['user_name'] = (string) $user['name'];
        $_SESSION['user_role'] = (string) $user['role'];
        $_SESSION['LAST_ACTIVITY'] = time();
        $_SESSION['last_revalidation_time'] = time();
        $_SESSION['user_agent'] = $userAgent;
        $_SESSION['previous_login_at'] = $previousLoginAt;
    }

    public function clearAuthentication(): void
    {
        foreach (['loggedin', 'user_id', 'user_email', 'user_name', 'user_role', 'LAST_ACTIVITY', 'last_revalidation_time', 'user_agent', 'previous_login_at'] as $key) {
            unset($_SESSION[$key]);
        }
    }

    public function invalidateAuthentication(): void
    {
        $this->clearAuthentication();
        session_regenerate_id(true);
    }

    public function destroyAndRestartAnonymousSession(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires' => time() - 42000,
                'path' => $params['path'],
                'domain' => $params['domain'],
                'secure' => $params['secure'],
                'httponly' => $params['httponly'],
                'samesite' => $params['samesite'] ?? $this->sameSite,
            ]);
        }
        session_destroy();
        $this->startPublicSession();
    }
}

<?php
declare(strict_types=1);

namespace GeoFort\Services\Http;

use PDO;
use GeoFort\Security\AuthMiddleware;
use GeoFort\Security\SessionGuard;
use GeoFort\Support\FlashStore;
use GeoFort\Services\Http\Redirect\HeaderRedirector;

final class PrivatePageBootstrapper
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly AuthMiddleware $auth,
        private readonly SessionGuard $guard,
        private readonly HeaderRedirector $redirector,
    ) {}

    public function init(): PDO
    {
        $this->auth->startPublicSession();
        if (!$this->guard->validate((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''))) {
            error_log('[SessionGuard] Session rejected: ' . ($this->guard->failureReason() ?? 'unknown'));
            $this->auth->invalidateAuthentication();
            FlashStore::add('login', 'Uw sessie is verlopen. Log opnieuw in.', 'error');
            $this->redirector->to('/auth/login-page.php');
        }
        return $this->pdo;
    }
}

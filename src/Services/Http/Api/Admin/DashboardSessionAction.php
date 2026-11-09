<?php
declare(strict_types=1);

namespace GeoFort\Services\Http\Api\Admin;

use GeoFort\Security\{AuthMiddleware, SessionGuard};
use GeoFort\Services\Auth\CsrfTokenService;
use GeoFort\Services\Http\Response\JsonResponse;
use GeoFort\Support\FlashStore;

final readonly class DashboardSessionAction
{
    public const CSRF_SCOPE = 'dashboard-activity';

    public function __construct(
        private AuthMiddleware $auth,
        private SessionGuard $guard,
        private CsrfTokenService $csrf,
        private JsonResponse $response,
    ) {}

    public function send(string $method, ?string $token, string $userAgent): void
    {
        if (!in_array($method, ['GET', 'POST'], true)) {
            $this->response->json(['ok' => false, 'code' => 'METHOD_NOT_ALLOWED'], 405)->header('Allow', 'GET, POST')->send();
            return;
        }
        $this->auth->startPublicSession();
        // Background status checks must never extend the inactivity deadline.
        if (!$this->guard->validate($userAgent, false)) {
            $this->auth->invalidateAuthentication();
            FlashStore::add('login', 'Je sessie is verlopen. Log opnieuw in.', 'error');
            $this->response->json(['ok' => false, 'code' => 'UNAUTHENTICATED'], 401)->send();
            return;
        }
        if ($method === 'POST') {
            if (!$this->csrf->validate(self::CSRF_SCOPE, $token)) {
                $this->response->json(['ok' => false, 'code' => 'INVALID_CSRF'], 403)->send();
                return;
            }
            $this->guard->recordActivity();
        }
        $this->response->json([
            'ok' => true,
            'remainingSeconds' => $this->guard->remainingSeconds(),
            'csrfToken' => $this->csrf->getOrCreate(self::CSRF_SCOPE),
        ])->header('Cache-Control', 'private, no-store')->send();
    }
}

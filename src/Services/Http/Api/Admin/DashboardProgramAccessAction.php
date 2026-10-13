<?php

declare(strict_types=1);

namespace GeoFort\Services\Http\Api\Admin;

use GeoFort\Security\AuthMiddleware;
use GeoFort\Security\SessionGuard;
use GeoFort\Services\Auth\CsrfTokenService;
use GeoFort\Services\Booking\Program\BookingProgramAccessService;
use GeoFort\Services\Http\Response\JsonResponse;

final readonly class DashboardProgramAccessAction
{
    public const CSRF_SCOPE = 'update-program-access';

    public function __construct(
        private AuthMiddleware $auth,
        private SessionGuard $guard,
        private CsrfTokenService $csrf,
        private BookingProgramAccessService $service,
        private JsonResponse $response,
    ) {}

    public function send(string $method, string $contentType, ?string $token, string $body, string $userAgent): void
    {
        $this->auth->startPublicSession();
        if (!$this->guard->validate($userAgent)) {
            $this->auth->invalidateAuthentication();
            $this->error('UNAUTHENTICATED', 401);
            return;
        }
        if (!BookingProgramAccessService::canManage((string) ($_SESSION['user_role'] ?? ''))) {
            $this->error('FORBIDDEN', 403);
            return;
        }
        if (!in_array($method, ['GET', 'POST'], true)) {
            $this->response->json(['ok' => false, 'code' => 'METHOD_NOT_ALLOWED'], 405)->header('Allow', 'GET, POST')->send();
            return;
        }
        try {
            if ($method === 'GET') {
                $this->response->json(['ok' => true, 'data' => $this->service->get(),
                    'csrfToken' => $this->csrf->getOrCreate(self::CSRF_SCOPE)])
                    ->header('Cache-Control', 'private, no-store')->send();
                return;
            }
            if (!$this->csrf->validate(self::CSRF_SCOPE, $token)) {
                $this->error('INVALID_CSRF', 403);
                return;
            }
            if (strtolower(trim(explode(';', $contentType, 2)[0])) !== 'application/json' || strlen($body) > 1024) {
                $this->error('INVALID_REQUEST', 422);
                return;
            }
            $input = json_decode($body, true, 8, JSON_THROW_ON_ERROR);
            if (!is_array($input) || array_diff(array_keys($input), ['fiveModuleOpen', 'revision']) !== []) {
                $this->error('INVALID_REQUEST', 422);
                return;
            }
            $result = $this->service->save($input['fiveModuleOpen'] ?? null, $input['revision'] ?? null,
                (int) ($_SESSION['user_id'] ?? 0), (string) $_SESSION['user_role']);
            $status = $result['ok'] ? 200 : match ($result['code']) {
                'CONFLICT' => 409, 'FORBIDDEN' => 403, default => 422,
            };
            $this->response->json($result, $status)->header('Cache-Control', 'private, no-store')->send();
        } catch (\JsonException) {
            $this->error('INVALID_REQUEST', 422);
        } catch (\Throwable $exception) {
            error_log('[DashboardProgramAccessAction] ' . $exception::class);
            $this->error('DATABASE_ERROR', 500);
        }
    }

    private function error(string $code, int $status): void
    {
        $this->response->json(['ok' => false, 'code' => $code], $status)->header('Cache-Control', 'no-store')->send();
    }
}

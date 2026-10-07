<?php

declare(strict_types=1);

namespace GeoFort\Services\Http\Api\Admin;

use GeoFort\Services\Dashboard\Roster\RosterStaffManagementService;
use GeoFort\Security\AuthMiddleware;
use GeoFort\Security\SessionGuard;
use GeoFort\Services\Http\Response\JsonResponse;
use Throwable;

final readonly class DashboardRosterStaffListAction
{
    public function __construct(
        private AuthMiddleware $auth,
        private SessionGuard $guard,
        private RosterStaffManagementService $service,
        private JsonResponse $response,
    ) {}

    public function send(string $method, string $userAgent): void
    {
        try {
            $this->auth->startPublicSession();
            if (!$this->guard->validate($userAgent)) {
                $this->auth->invalidateAuthentication();
                $this->response->json(['ok' => false, 'code' => 'UNAUTHENTICATED'], 401)->send();
                return;
            }

            if ($method !== 'GET') {
                $this->response->methodNotAllowed()->header('Allow', 'GET')->send();
                return;
            }

            $this->response->json([
                'ok' => true,
                'data' => $this->service->catalog(),
            ])->header('Cache-Control', 'no-store')->send();
        } catch (Throwable $exception) {
            error_log('[DashboardRosterStaffListAction] ' . $exception::class);
            $this->response->serverError(
                'Personeelsconfiguratie kon niet worden geladen.',
                500,
                false,
            )->send();
        }
    }
}

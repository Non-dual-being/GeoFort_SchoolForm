<?php

declare(strict_types=1);

namespace GeoFort\Services\Http\Api\Admin;

use GeoFort\Security\AuthMiddleware;
use GeoFort\Security\SessionGuard;
use GeoFort\Services\Dashboard\Roster\{RosterPlanException, RosterStaffManagementService};
use GeoFort\Services\Http\Response\JsonResponse;
use Throwable;

final readonly class DashboardRosterStaffShowAction
{
    public function __construct(private AuthMiddleware $auth, private SessionGuard $guard, private RosterStaffManagementService $service, private JsonResponse $response) {}

    public function send(string $method, array $query, string $userAgent): void
    {
        $this->auth->startPublicSession();
        if (!$this->guard->validate($userAgent)) {
            $this->auth->invalidateAuthentication();
            $this->response->json(['ok' => false, 'code' => 'UNAUTHENTICATED'], 401)->send();
            return;
        }
        if ($method !== 'GET') {
            $this->response->json(['ok' => false, 'code' => 'METHOD_NOT_ALLOWED'], 405)->header('Allow', 'GET')->send();
            return;
        }
        try {
            $id = $query['staffId'] ?? null;
            if (!is_string($id) || preg_match('/^[1-9][0-9]*$/D', $id) !== 1 || (string) (int) $id !== $id) {
                throw new RosterPlanException('INVALID_REQUEST', 422, 'Een geldig personeels-ID is verplicht.');
            }
            $this->response->json(['ok' => true, 'data' => $this->service->show((int) $id)])->header('Cache-Control', 'no-store')->send();
        } catch (RosterPlanException $exception) {
            $this->response->json(['ok' => false, 'code' => $exception->publicCode, 'message' => $exception->getMessage()], $exception->httpStatus)->send();
        } catch (Throwable $exception) {
            error_log('[DashboardRosterStaffShowAction] ' . $exception::class);
            $this->response->serverError('De medewerker kon niet worden geladen.', 500, false)->send();
        }
    }
}

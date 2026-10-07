<?php

declare(strict_types=1);

namespace GeoFort\Services\Http\Api\Admin;

use GeoFort\Security\AuthMiddleware;
use GeoFort\Security\SessionGuard;
use GeoFort\Services\Dashboard\Roster\RosterLifecycleService;
use GeoFort\Services\Dashboard\Roster\RosterPlanException;
use GeoFort\Services\Http\Response\JsonResponse;
use Throwable;

final readonly class DashboardRosterValidateAction
{
    public function __construct(
        private AuthMiddleware $auth,
        private SessionGuard $guard,
        private RosterLifecycleService $service,
        private JsonResponse $response,
    ) {}

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
            $request = DashboardRosterRevisionRequest::fromQuery($query);
            $data = $this->service->validate($request->planId, $request->expectedRevision);
            $this->response->json(['ok' => true, 'data' => ['validation' => $data]])->send();
        } catch (RosterPlanException $exception) {
            $this->response->json(['ok' => false, 'code' => $exception->publicCode, 'message' => $exception->getMessage(),
                'validation' => $exception->validation], $exception->httpStatus)->send();
        } catch (Throwable $exception) {
            error_log('[DashboardRosterValidateAction] ' . $exception::class);
            $this->response->serverError('De roosteractie kon niet worden uitgevoerd.', 500, false)->send();
        }
    }
}

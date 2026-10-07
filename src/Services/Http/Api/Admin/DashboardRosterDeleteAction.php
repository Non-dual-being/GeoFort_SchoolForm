<?php

declare(strict_types=1);

namespace GeoFort\Services\Http\Api\Admin;

use GeoFort\Security\AuthMiddleware;
use GeoFort\Security\SessionGuard;
use GeoFort\Services\Auth\CsrfTokenService;
use GeoFort\Services\Dashboard\Roster\RosterPlanException;
use GeoFort\Services\Dashboard\Roster\RosterPlanService;
use GeoFort\Services\Http\Response\JsonResponse;
use JsonException;
use Throwable;

final readonly class DashboardRosterDeleteAction
{
    public const CSRF_SCOPE = DashboardRosterCreateAction::CSRF_SCOPE;

    public function __construct(
        private AuthMiddleware $auth,
        private SessionGuard $guard,
        private CsrfTokenService $csrf,
        private RosterPlanService $service,
        private JsonResponse $response,
    ) {}

    public function send(
        string $method,
        string $contentType,
        ?string $token,
        string $body,
        string $userAgent,
    ): void {
        $this->auth->startPublicSession();

        if (!$this->guard->validate($userAgent)) {
            $this->auth->invalidateAuthentication();
            $this->response->json(['ok' => false, 'code' => 'UNAUTHENTICATED'], 401)->send();
            return;
        }

        if ($method !== 'POST') {
            $this->response
                ->json(['ok' => false, 'code' => 'METHOD_NOT_ALLOWED'], 405)
                ->header('Allow', 'POST')
                ->send();
            return;
        }

        if (!str_starts_with(strtolower(trim($contentType)), 'application/json')) {
            $this->response->json(['ok' => false, 'code' => 'INVALID_REQUEST'], 422)->send();
            return;
        }

        if (!$this->csrf->validate(self::CSRF_SCOPE, $token)) {
            $this->response->json(['ok' => false, 'code' => 'INVALID_CSRF'], 403)->send();
            return;
        }

        try {
            $payload = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $this->response->json(['ok' => false, 'code' => 'INVALID_REQUEST'], 422)->send();
            return;
        }

        $planId = is_array($payload) ? ($payload['planId'] ?? null) : null;
        if (!is_int($planId) || $planId <= 0) {
            $this->response->json(['ok' => false, 'code' => 'INVALID_REQUEST'], 422)->send();
            return;
        }

        try {
            $this->service->deleteConcept($planId);

            $this->response->json([
                'ok' => true,
                'data' => [
                    'deleted' => true,
                    'planId' => $planId,
                ],
            ])->send();
        } catch (RosterPlanException $exception) {
            $this->response->json([
                'ok' => false,
                'code' => $exception->publicCode,
                'message' => $exception->getMessage(),
            ], $exception->httpStatus)->send();
        } catch (Throwable $exception) {
            error_log(
                '[DashboardRosterDeleteAction] Roosterplan verwijderen mislukt: '
                . $exception::class,
            );
            $this->response->serverError(
                'Rooster kon niet worden verwijderd.',
                500,
                false,
            )->send();
        }
    }
}

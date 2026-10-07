<?php

declare(strict_types=1);

namespace GeoFort\Services\Http\Api\Admin;

use GeoFort\Security\AuthMiddleware;
use GeoFort\Security\SessionGuard;
use GeoFort\Services\Auth\CsrfTokenService;
use GeoFort\Services\Dashboard\Roster\RosterPlanException;
use GeoFort\Services\Dashboard\Roster\RosterSessionService;
use GeoFort\Services\Http\Response\JsonResponse;
use JsonException;
use Throwable;

final readonly class DashboardRosterSessionDeleteAction
{
    public const CSRF_SCOPE = 'manage-roster-session';

    public function __construct(
        private AuthMiddleware $auth,
        private SessionGuard $guard,
        private CsrfTokenService $csrf,
        private RosterSessionService $service,
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
            $this->response->json(['ok' => false, 'code' => 'METHOD_NOT_ALLOWED'], 405)
                ->header('Allow', 'POST')->send();
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
        $sessionId = is_array($payload) ? ($payload['sessionId'] ?? null) : null;
        $expectedRevision = is_array($payload) ? ($payload['expectedRevision'] ?? null) : null;

        if (
            !is_int($planId) || $planId <= 0
            || !is_int($sessionId) || $sessionId <= 0
            || !is_int($expectedRevision) || $expectedRevision <= 0
        ) {
            $this->response->json(['ok' => false, 'code' => 'INVALID_REQUEST'], 422)->send();
            return;
        }

        try {
            $result = $this->service->deleteActivity(
                $planId,
                $sessionId,
                $expectedRevision,
                (int) $_SESSION['user_id'],
            );

            $this->response->json(['ok' => true, 'data' => $result])->send();
        } catch (RosterPlanException $exception) {
            $this->response->json([
                'ok' => false,
                'code' => $exception->publicCode,
                'message' => $exception->getMessage(),
            ], $exception->httpStatus)->send();
        } catch (Throwable $exception) {
            error_log('[DashboardRosterSessionDeleteAction] delete failed: ' . $exception::class);
            $this->response->serverError('Roostersessie kon niet worden verwijderd.', 500, false)->send();
        }
    }
}
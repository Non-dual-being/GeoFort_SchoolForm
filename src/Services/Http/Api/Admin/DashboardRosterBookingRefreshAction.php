<?php

declare(strict_types=1);

namespace GeoFort\Services\Http\Api\Admin;

use GeoFort\Security\AuthMiddleware;
use GeoFort\Security\SessionGuard;
use GeoFort\Services\Auth\CsrfTokenService;
use GeoFort\Services\Dashboard\Roster\RosterPlanException;
use GeoFort\Services\Dashboard\Roster\RosterBookingRefreshService;
use GeoFort\Services\Http\Response\JsonResponse;
use JsonException;
use Throwable;

final readonly class DashboardRosterBookingRefreshAction
{
    public const CSRF_SCOPE = 'refresh-roster-booking';

    public function __construct(
        private AuthMiddleware $auth,
        private SessionGuard $guard,
        private CsrfTokenService $csrf,
        private RosterBookingRefreshService $service,
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

        if (!is_array($payload)) {
            $this->response->json(['ok' => false, 'code' => 'INVALID_REQUEST'], 422)->send();
            return;
        }

        $mode = $payload['mode'] ?? null;
        if (!in_array($mode, ['preview', 'apply'], true) || ($mode === 'apply' && (!is_string($payload['signature'] ?? null) || ($payload['accepted'] ?? false) !== true))) {
            $this->response->json(['ok' => false, 'code' => 'INVALID_REQUEST'], 422)->send();
            return;
        }
        $planId = $payload['planId'] ?? null;
        $expectedRevision = $payload['expectedRevision'] ?? null;

        if (
            !is_int($planId)
            || $planId <= 0
            || !is_int($expectedRevision)
            || $expectedRevision <= 0
        ) {
            $this->response->json(['ok' => false, 'code' => 'INVALID_REQUEST'], 422)->send();
            return;
        }

        try {
            $data = $mode === 'preview'
                ? ['preview' => $this->service->preview($planId, $expectedRevision)]
                : ['plan' => $this->service->apply($planId, $expectedRevision, $payload['signature'], true, (int) $_SESSION['user_id'])];
            $this->response->json(['ok' => true, 'data' => $data])->send();
        } catch (RosterPlanException $exception) {
            $this->response->json([
                'ok' => false,
                'code' => $exception->publicCode,
                'message' => $exception->getMessage(),
            ], $exception->httpStatus)->send();
        } catch (Throwable $exception) {
            error_log(
                '[DashboardRosterBookingRefreshAction] Verwerken aanvraagwijzigingen mislukt: '
                . $exception::class,
            );
            $this->response->serverError(
                'Aanvraagwijzigingen konden niet worden verwerkt.',
                500,
                false,
            )->send();
        }
    }
}
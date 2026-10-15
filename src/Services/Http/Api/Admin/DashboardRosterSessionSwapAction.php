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

final readonly class DashboardRosterSessionSwapAction
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
        $expectedRevision = is_array($payload) ? ($payload['expectedRevision'] ?? null) : null;
        $mode = is_array($payload) ? ($payload['mode'] ?? null) : null;
        $first = is_array($payload) ? ($payload['first'] ?? null) : null;
        $second = is_array($payload) ? ($payload['second'] ?? null) : null;
        $ids = [$planId, $expectedRevision, is_array($first) ? ($first['sessionId'] ?? null) : null,
            is_array($first) ? ($first['groupId'] ?? null) : null,
            is_array($second) ? ($second['sessionId'] ?? null) : null, is_array($second) ? ($second['groupId'] ?? null) : null];
        if (!in_array($mode, ['lesson', 'staff'], true) || array_filter($ids, static fn($id): bool => !is_int($id) || $id <= 0) !== []) {
            $this->response->json(['ok' => false, 'code' => 'INVALID_REQUEST'], 422)->send();
            return;
        }

        try {
            $result = $this->service->swap($planId, $expectedRevision, $mode,
                $first['sessionId'], $first['groupId'], $second['sessionId'], $second['groupId'], (int) $_SESSION['user_id']);

            $this->response->json(['ok' => true, 'data' => $result])->send();
        } catch (RosterPlanException $exception) {
            $this->response->json([
                'ok' => false,
                'code' => $exception->publicCode,
                'message' => $exception->getMessage(),
            ], $exception->httpStatus)->send();
        } catch (Throwable $exception) {
            error_log('[DashboardRosterSessionSwapAction] swap failed: ' . $exception::class);
            $this->response->serverError('Wisselen kon niet worden opgeslagen.', 500, false)->send();
        }
    }
}
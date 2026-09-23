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

final readonly class DashboardRosterSessionSaveAction
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
            $payload = json_decode($body, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $this->response->json(['ok' => false, 'code' => 'INVALID_REQUEST'], 422)->send();
            return;
        }

        if (!is_array($payload)) {
            $this->response->json(['ok' => false, 'code' => 'INVALID_REQUEST'], 422)->send();
            return;
        }

        $planId = $payload['planId'] ?? null;
        $sessionId = $payload['sessionId'] ?? null;
        $expectedRevision = $payload['expectedRevision'] ?? null;
        $moduleKey = $payload['moduleKey'] ?? null;
        $startTime = $payload['startTime'] ?? null;
        $endTime = $payload['endTime'] ?? null;
        $location = $payload['location'] ?? null;
        $groupIds = $payload['groupIds'] ?? null;
        $staffIds = $payload['staffIds'] ?? [];
        $cookStaffId = $payload['cookStaffId'] ?? null;

        if (
            !is_int($planId) || $planId <= 0
            || ($sessionId !== null && (!is_int($sessionId) || $sessionId <= 0))
            || !is_int($expectedRevision) || $expectedRevision <= 0
            || !is_string($moduleKey) || trim($moduleKey) === ''
            || !is_string($startTime)
            || !is_string($endTime)
            || ($location !== null && !is_string($location))
            || !is_array($groupIds)
            || !is_array($staffIds)
            || ($cookStaffId !== null && (!is_int($cookStaffId) || $cookStaffId <= 0))
        ) {
            $this->response->json(['ok' => false, 'code' => 'INVALID_REQUEST'], 422)->send();
            return;
        }

        $normalizedGroupIds = $this->positiveIntegerList($groupIds);
        $normalizedStaffIds = $this->positiveIntegerList($staffIds);
        if ($normalizedGroupIds === null || $normalizedStaffIds === null) {
            $this->response->json(['ok' => false, 'code' => 'INVALID_REQUEST'], 422)->send();
            return;
        }

        try {
            $result = $this->service->saveActivity(
                planId: $planId,
                sessionId: $sessionId,
                expectedRevision: $expectedRevision,
                moduleKey: trim($moduleKey),
                startTime: $startTime,
                endTime: $endTime,
                location: $location,
                groupIds: $normalizedGroupIds,
                actingAdminId: (int) $_SESSION['user_id'],
                staffIds: $normalizedStaffIds,
                cookStaffId: $cookStaffId,
            );

            $this->response->json(['ok' => true, 'data' => $result])->send();
        } catch (RosterPlanException $exception) {
            $this->response->json([
                'ok' => false,
                'code' => $exception->publicCode,
                'message' => $exception->getMessage(),
            ], $exception->httpStatus)->send();
        } catch (Throwable $exception) {
            error_log('[DashboardRosterSessionSaveAction] save failed: ' . $exception::class);
            $this->response->serverError('Roostersessie kon niet worden opgeslagen.', 500, false)->send();
        }
    }

    /** @return list<int>|null */
    private function positiveIntegerList(array $values): ?array
    {
        $normalized = [];
        foreach ($values as $value) {
            if (!is_int($value) || $value <= 0) {
                return null;
            }
            $normalized[] = $value;
        }

        return array_values(array_unique($normalized));
    }
}

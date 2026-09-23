<?php

declare(strict_types=1);

namespace GeoFort\Services\Http\Api\Admin;

use GeoFort\Security\AuthMiddleware;
use GeoFort\Security\SessionGuard;
use GeoFort\Services\Auth\CsrfTokenService;
use GeoFort\Services\Dashboard\Roster\RosterAutoGenerator;
use GeoFort\Services\Dashboard\Roster\RosterPlanException;
use GeoFort\Services\Http\Response\JsonResponse;
use JsonException;
use Throwable;

final readonly class DashboardRosterGenerationPreviewAction
{
    public const CSRF_SCOPE = 'generate-roster-plan';

    public function __construct(
        private AuthMiddleware $auth,
        private SessionGuard $guard,
        private CsrfTokenService $csrf,
        private RosterAutoGenerator $generator,
        private JsonResponse $response,
    ) {}

    public function send(string $method, string $contentType, ?string $token, string $body, string $userAgent): void
    {
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
            if (!is_array($payload)) throw new JsonException();

            $planId = $payload['planId'] ?? null;
            $rounds = $payload['rounds'] ?? null;
            $staffIds = $payload['staffIds'] ?? [];
            $staffingMode = $payload['staffingMode'] ?? 'with_staff';
            $preferGeoFortKe = $payload['preferGeoFortKe'] ?? true;
            $cookStaffId = $payload['cookStaffId'] ?? null;

            if (
                !is_int($planId) || $planId <= 0
                || !is_array($rounds)
                || !is_array($staffIds)
                || !is_string($staffingMode)
                || !is_bool($preferGeoFortKe)
                || ($cookStaffId !== null && (!is_int($cookStaffId) || $cookStaffId <= 0))
            ) {
                $this->response->json(['ok' => false, 'code' => 'INVALID_REQUEST'], 422)->send();
                return;
            }

            $normalizedStaffIds = $this->positiveIntegerList($staffIds);
            if ($normalizedStaffIds === null) {
                $this->response->json(['ok' => false, 'code' => 'INVALID_REQUEST'], 422)->send();
                return;
            }

            $this->response->json([
                'ok' => true,
                'data' => $this->generator->preview(
                    $planId,
                    $rounds,
                    $normalizedStaffIds,
                    $staffingMode,
                    $preferGeoFortKe,
                    $cookStaffId,
                ),
            ])->send();
        } catch (JsonException) {
            $this->response->json(['ok' => false, 'code' => 'INVALID_REQUEST'], 422)->send();
        } catch (RosterPlanException $exception) {
            $this->response->json([
                'ok' => false,
                'code' => $exception->publicCode,
                'message' => $exception->getMessage(),
            ], $exception->httpStatus)->send();
        } catch (Throwable $exception) {
            error_log('[DashboardRosterGenerationPreviewAction] preview failed: ' . $exception::class);
            $this->response->serverError('Roostervoorstel kon niet worden gemaakt.', 500, false)->send();
        }
    }

    /** @return list<int>|null */
    private function positiveIntegerList(array $values): ?array
    {
        $normalized = [];
        foreach ($values as $value) {
            if (!is_int($value) || $value <= 0) return null;
            $normalized[] = $value;
        }
        return array_values(array_unique($normalized));
    }
}

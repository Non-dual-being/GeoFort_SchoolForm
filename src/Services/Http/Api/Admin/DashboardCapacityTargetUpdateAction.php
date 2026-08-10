<?php
declare(strict_types=1);

namespace GeoFort\Services\Http\Api\Admin;

use GeoFort\Security\AuthMiddleware;
use GeoFort\Security\SessionGuard;
use GeoFort\Services\Auth\CsrfTokenService;
use GeoFort\Services\Dashboard\Booking\Analytics\CapacityTargetManagementService;
use GeoFort\Services\Http\Response\JsonResponse;

final readonly class DashboardCapacityTargetUpdateAction
{
    public const CSRF_SCOPE = 'update-capacity-target';

    public function __construct(
        private AuthMiddleware $auth,
        private SessionGuard $guard,
        private CsrfTokenService $csrf,
        private CapacityTargetManagementService $service,
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
        if ($method !== 'POST') {
            $this->response->json(['ok' => false, 'code' => 'METHOD_NOT_ALLOWED', 'issues' => []], 405)->header('Allow', 'POST')->send();
            return;
        }
        if (preg_match('/^application\/json(?:\s*;\s*charset=(?:utf-8|"utf-8"))?$/i', trim($contentType)) !== 1) {
            $this->error('INVALID_REQUEST', 422);
            return;
        }
        if (!$this->csrf->validate(self::CSRF_SCOPE, $token)) {
            $this->error('INVALID_CSRF', 403);
            return;
        }
        try {
            $request = CapacityTargetUpdateRequest::fromJson($body);
        } catch (CapacityTargetUpdateRequestException $exception) {
            $this->error($exception->publicCode, 422);
            return;
        }
        $result = $this->service->save(
            $request->effectiveDate,
            $request->studentsPerAvailableDay,
            $request->bookingsPerAvailableDay,
            $request->expectedUpdatedAt,
            (int) $_SESSION['user_id'],
            (string) $_SESSION['user_role'],
        );
        $status = match ($result['code']) {
            'SUCCESS' => 200,
            'TARGET_CONFLICT' => 409,
            'FORBIDDEN', 'INVALID_CSRF' => 403,
            'DATABASE_ERROR' => 500,
            default => 422,
        };
        $this->response->json([
            'ok' => $result['ok'],
            'code' => $result['code'],
            'data' => isset($result['target']) ? ['target' => $result['target']] : null,
            'issues' => array_map(
                static fn (string $field, string $description): array => [
                    'code' => 'INVALID_TARGET', 'field' => $field, 'title' => 'Ongeldig target',
                    'description' => $description, 'metadata' => [],
                ],
                array_keys($result['issues'] ?? []),
                array_values($result['issues'] ?? []),
            ),
        ], $status)->send();
    }

    private function error(string $code, int $status): void
    {
        $description = match ($code) {
            'UNAUTHENTICATED' => 'Log opnieuw in om targets te beheren.',
            'INVALID_CSRF' => 'Vernieuw de pagina en probeer opnieuw.',
            default => 'Het verzoek heeft niet de verwachte structuur.',
        };
        $this->response->json(['ok' => false, 'code' => $code, 'data' => null, 'issues' => [[
            'code' => $code, 'field' => 'request', 'title' => 'Verzoek mislukt', 'description' => $description, 'metadata' => [],
        ]]], $status)->send();
    }
}

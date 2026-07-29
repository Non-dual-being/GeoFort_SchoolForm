<?php
declare(strict_types=1);

namespace GeoFort\Services\Http\Api\Admin;

use GeoFort\Security\AuthMiddleware;
use GeoFort\Security\SessionGuard;
use GeoFort\Services\Auth\CsrfTokenService;
use GeoFort\Services\Dashboard\Calendar\CalendarDateManagementPreviewService;
use GeoFort\Services\Http\Response\JsonResponse;
use Throwable;

final readonly class DashboardCalendarDateManagementPreviewAction
{
    public function __construct(
        private AuthMiddleware $auth,
        private SessionGuard $guard,
        private CsrfTokenService $csrf,
        private CalendarDateManagementPreviewService $service,
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
        if (!$this->csrf->validate(DashboardCalendarDateManagementAction::CSRF_SCOPE, $token)) {
            $this->error('INVALID_CSRF', 403);
            return;
        }
        try {
            $request = CalendarDateManagementPreviewRequest::fromJson($body);
            $result = $this->service->preview($request->action, $request->startDate, $request->endDate);
            $status = $result->success ? 200 : ($result->code === 'DATABASE_ERROR' ? 500 : 422);
            $this->response->json([
                'ok' => $result->success,
                'code' => $result->code,
                'data' => $result->preview?->toArray(),
                'issues' => array_map(static fn ($issue): array => $issue->toArray(), $result->issues),
            ], $status)->send();
        } catch (CalendarDateManagementRequestException $exception) {
            $this->error($exception->publicCode, 422);
        } catch (Throwable $exception) {
            error_log('[DashboardCalendarDateManagementPreviewAction] Preview mislukt: ' . $exception::class);
            $this->error('DATABASE_ERROR', 500);
        }
    }

    private function error(string $code, int $status): void
    {
        $this->response->json(['ok' => false, 'code' => $code, 'issues' => [[
            'code' => $code,
            'field' => $code === 'INVALID_CALENDAR_DATE_ACTION' ? 'action' : 'request',
            'title' => 'Preview mislukt',
            'description' => $code === 'UNAUTHENTICATED' ? 'Log opnieuw in om de kalender te beheren.' : 'De kalenderpreview kon niet worden verwerkt.',
            'metadata' => [],
        ]]], $status)->send();
    }
}

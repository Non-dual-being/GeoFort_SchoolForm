<?php
declare(strict_types=1);

namespace GeoFort\Services\Http\Api\Admin;

use GeoFort\Dashboard\Calendar\CalendarDateManagementCommand;
use GeoFort\Security\AuthMiddleware;
use GeoFort\Security\SessionGuard;
use GeoFort\Services\Auth\CsrfTokenService;
use GeoFort\Services\Dashboard\Calendar\CalendarDateManagementService;
use GeoFort\Services\Http\Response\JsonResponse;
use Throwable;

final readonly class DashboardCalendarDateManagementAction
{
    public const CSRF_SCOPE = 'manage-calendar-date';

    public function __construct(
        private AuthMiddleware $auth,
        private SessionGuard $guard,
        private CsrfTokenService $csrf,
        private CalendarDateManagementService $service,
        private JsonResponse $response,
    ) {}

    public function send(string $method, string $contentType, ?string $token, string $body, string $userAgent): void
    {
        if (!$this->authorize($method, $contentType, $token, $userAgent)) return;
        try {
            $request = CalendarDateManagementRequest::fromJson($body);
            $result = $this->service->change(new CalendarDateManagementCommand(
                $request->startDate,
                $request->endDate,
                $request->action,
                $request->reason,
                $request->confirmed,
                $request->existingBookingsAccepted,
                $request->previewFingerprint,
                $request->activeBookingsFingerprint,
                (int) $_SESSION['user_id'],
            ));
            $status = match ($result->code) {
                'SUCCESS', 'NO_CHANGE', 'NO_ELIGIBLE_DATES' => 200,
                'CALENDAR_DATE_CONFLICT' => 409,
                'DATABASE_ERROR' => 500,
                default => 422,
            };
            $this->response->json([
                'ok' => $result->success,
                'code' => $result->code,
                'data' => [
                    'startDate' => $result->startDate,
                    'endDate' => $result->endDate,
                    'affectedCount' => $result->affectedCount,
                    'preview' => $result->preview?->toArray(),
                ],
                'issues' => array_map(static fn ($issue): array => $issue->toArray(), $result->issues),
            ], $status)->send();
        } catch (CalendarDateManagementRequestException $exception) {
            $this->error($exception->publicCode, 422);
        } catch (Throwable $exception) {
            error_log('[DashboardCalendarDateManagementAction] Mutatie mislukt: ' . $exception::class);
            $this->error('DATABASE_ERROR', 500);
        }
    }

    private function authorize(string $method, string $contentType, ?string $token, string $userAgent): bool
    {
        $this->auth->startPublicSession();
        if (!$this->guard->validate($userAgent)) {
            $this->auth->invalidateAuthentication();
            $this->error('UNAUTHENTICATED', 401);
            return false;
        }
        if ($method !== 'POST') {
            $this->response->json(['ok' => false, 'code' => 'METHOD_NOT_ALLOWED', 'issues' => []], 405)->header('Allow', 'POST')->send();
            return false;
        }
        if (preg_match('/^application\/json(?:\s*;\s*charset=(?:utf-8|"utf-8"))?$/i', trim($contentType)) !== 1) {
            $this->error('INVALID_REQUEST', 422);
            return false;
        }
        if (!$this->csrf->validate(self::CSRF_SCOPE, $token)) {
            $this->error('INVALID_CSRF', 403);
            return false;
        }
        return true;
    }

    private function error(string $code, int $status): void
    {
        $descriptions = [
            'INVALID_REQUEST' => ['request', 'Ongeldig verzoek', 'De aanvraag heeft niet de verwachte structuur.'],
            'INVALID_CALENDAR_DATE_ACTION' => ['action', 'Ongeldige actie', 'Kies een ondersteunde kalenderbeheeractie.'],
            'INVALID_CSRF' => ['request', 'Beveiligingscontrole mislukt', 'Vernieuw de pagina en probeer opnieuw.'],
            'UNAUTHENTICATED' => ['request', 'Sessie verlopen', 'Log opnieuw in om de kalender te beheren.'],
            'DATABASE_ERROR' => ['dateRange', 'Wijziging mislukt', 'De kalenderwijziging kon niet worden verwerkt.'],
        ];
        [$field, $title, $description] = $descriptions[$code] ?? ['request', 'Verzoek mislukt', 'Het verzoek kon niet worden verwerkt.'];
        $this->response->json(['ok' => false, 'code' => $code, 'issues' => [[
            'code' => $code, 'field' => $field, 'title' => $title, 'description' => $description, 'metadata' => [],
        ]]], $status)->send();
    }
}

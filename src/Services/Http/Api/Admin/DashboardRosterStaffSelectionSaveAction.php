<?php

declare(strict_types=1);

namespace GeoFort\Services\Http\Api\Admin;

use GeoFort\Security\AuthMiddleware;
use GeoFort\Security\SessionGuard;
use GeoFort\Services\Auth\CsrfTokenService;
use GeoFort\Services\Dashboard\Roster\{RosterPlanException, RosterPlanStaffSelectionService};
use GeoFort\Services\Http\Response\JsonResponse;
use JsonException;
use Throwable;

final readonly class DashboardRosterStaffSelectionSaveAction
{
    public const CSRF_SCOPE = 'update-roster-staff-selection';

    public function __construct(private AuthMiddleware $auth, private SessionGuard $guard, private CsrfTokenService $csrf, private RosterPlanStaffSelectionService $service, private JsonResponse $response) {}

    public function send(string $method, string $contentType, ?string $token, string $body, string $userAgent): void
    {
        $this->auth->startPublicSession();
        if (!$this->guard->validate($userAgent)) {
            $this->auth->invalidateAuthentication();
            $this->response->json(['ok' => false, 'code' => 'UNAUTHENTICATED'], 401)->send();
            return;
        }
        if ($method !== 'POST') {
            $this->response->json(['ok' => false, 'code' => 'METHOD_NOT_ALLOWED'], 405)->header('Allow', 'POST')->send();
            return;
        }
        if (!$this->csrf->validate(self::CSRF_SCOPE, $token)) {
            $this->response->json(['ok' => false, 'code' => 'INVALID_CSRF'], 403)->send();
            return;
        }
        if (strtolower(trim(explode(';', $contentType, 2)[0])) !== 'application/json' || strlen($body) > 32768) {
            $this->response->json(['ok' => false, 'code' => 'INVALID_REQUEST'], 422)->send();
            return;
        }
        try {
            $request = DashboardRosterStaffSelectionRequest::fromArray(json_decode($body, true, 32, JSON_THROW_ON_ERROR));
            $data = $this->service->save($request->planId, $request->expectedRevision, $request->expectedCatalogRevision, $request->staffIds, (int) $_SESSION['user_id']);
            $this->response->json(['ok' => true, 'data' => $data])->send();
        } catch (RosterPlanException $exception) {
            $this->response->json(['ok' => false, 'code' => $exception->publicCode, 'message' => $exception->getMessage()], $exception->httpStatus)->send();
        } catch (JsonException) {
            $this->response->json(['ok' => false, 'code' => 'INVALID_REQUEST'], 422)->send();
        } catch (Throwable $exception) {
            error_log('[DashboardRosterStaffSelectionSaveAction] ' . $exception::class);
            $this->response->serverError('De dagselectie kon niet worden opgeslagen.', 500, false)->send();
        }
    }
}

<?php

declare(strict_types=1);

namespace GeoFort\Services\Http\Api\Admin;

use GeoFort\Security\AuthMiddleware;
use GeoFort\Security\SessionGuard;
use GeoFort\Services\Auth\CsrfTokenService;
use GeoFort\Services\Dashboard\Roster\{RosterPlanException, RosterStaffManagementService};
use GeoFort\Services\Http\Response\JsonResponse;
use JsonException;
use Throwable;

final readonly class DashboardRosterStaffDeactivateAction
{
    public const CSRF_SCOPE = 'deactivate-roster-staff';

    public function __construct(
        private AuthMiddleware $auth,
        private SessionGuard $guard,
        private CsrfTokenService $csrf,
        private RosterStaffManagementService $service,
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
            $this->response->json(['ok' => false, 'code' => 'METHOD_NOT_ALLOWED'], 405)->header('Allow', 'POST')->send();
            return;
        }
        if (!$this->csrf->validate(self::CSRF_SCOPE, $token)) {
            $this->response->json(['ok' => false, 'code' => 'INVALID_CSRF'], 403)->send();
            return;
        }
        if (strtolower(trim(explode(';', $contentType, 2)[0])) !== 'application/json' || strlen($body) > 2048) {
            $this->response->json(['ok' => false, 'code' => 'INVALID_REQUEST'], 422)->send();
            return;
        }
        try {
            $request = DashboardRosterStaffDeactivateRequest::fromArray(json_decode($body, true, 16, JSON_THROW_ON_ERROR));
            $secret = $_SESSION['_roster_staff_impact_secret'] ?? null;
            if (!is_string($secret) || strlen($secret) < 32) throw new RosterPlanException('STALE_STAFF_IMPACT', 409, 'Controleer eerst opnieuw de gevolgen van deactiveren.');
            $data = $this->service->deactivate($request->staffId, $request->expectedVersion, $request->expectedCatalogRevision,
                $request->impactToken, (int) $_SESSION['user_id'], $secret);
            $this->response->json(['ok' => true, 'data' => $data])->header('Cache-Control', 'no-store')->send();
        } catch (RosterPlanException $exception) {
            $this->response->json(['ok' => false, 'code' => $exception->publicCode, 'message' => $exception->getMessage()], $exception->httpStatus)->send();
        } catch (JsonException) {
            $this->response->json(['ok' => false, 'code' => 'INVALID_REQUEST'], 422)->send();
        } catch (Throwable $exception) {
            error_log('[DashboardRosterStaffDeactivateAction] ' . $exception::class);
            $this->response->serverError('De medewerker kon niet worden gedeactiveerd.', 500, false)->send();
        }
    }
}

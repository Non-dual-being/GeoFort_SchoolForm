<?php

declare(strict_types=1);

namespace GeoFort\Services\Http\Api\Admin;

use GeoFort\Security\AuthMiddleware;
use GeoFort\Security\SessionGuard;
use GeoFort\Services\Auth\CsrfTokenService;
use GeoFort\Services\Dashboard\Roster\{RosterPlanException, RosterStaffChange, RosterStaffManagementService};
use GeoFort\Services\Http\Response\JsonResponse;
use JsonException;
use Throwable;

final readonly class DashboardRosterStaffSaveAction
{
    public const CSRF_SCOPES = ['create' => 'create-roster-staff', 'update' => 'update-roster-staff', 'preview-impact' => 'preview-roster-staff-impact'];

    public function __construct(
        private AuthMiddleware $auth,
        private SessionGuard $guard,
        private CsrfTokenService $csrf,
        private RosterStaffManagementService $service,
        private JsonResponse $response,
    ) {}

    /** Operation is supplied by a fixed endpoint, never by request input. */
    public function send(string $operation, string $method, string $contentType, ?string $token, string $body, string $userAgent): void
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
        if (!isset(self::CSRF_SCOPES[$operation]) || !$this->csrf->validate(self::CSRF_SCOPES[$operation], $token)) {
            $this->response->json(['ok' => false, 'code' => 'INVALID_CSRF'], 403)->send();
            return;
        }
        if (strtolower(trim(explode(';', $contentType, 2)[0])) !== 'application/json' || strlen($body) > 32768) {
            $this->response->json(['ok' => false, 'code' => 'INVALID_REQUEST'], 422)->send();
            return;
        }
        try {
            $request = RosterStaffChange::fromArray(json_decode($body, true, 32, JSON_THROW_ON_ERROR), $operation === 'create');
            $_SESSION['_roster_staff_impact_secret'] ??= bin2hex(random_bytes(32));
            $secret = (string) $_SESSION['_roster_staff_impact_secret'];
            $actorId = (int) $_SESSION['user_id'];
            $data = $operation === 'preview-impact'
                ? $this->service->preview($request, $actorId, $secret)
                : $this->service->save($request, $actorId, $secret);
            $this->response->json(['ok' => true, 'data' => $data], $operation === 'create' ? 201 : 200)->header('Cache-Control', 'no-store')->send();
        } catch (RosterPlanException $exception) {
            $this->response->json(['ok' => false, 'code' => $exception->publicCode, 'message' => $exception->getMessage()], $exception->httpStatus)->send();
        } catch (JsonException) {
            $this->response->json(['ok' => false, 'code' => 'INVALID_REQUEST'], 422)->send();
        } catch (Throwable $exception) {
            error_log('[DashboardRosterStaffSaveAction] ' . $exception::class);
            $this->response->serverError('De personeelswijziging kon niet worden verwerkt.', 500, false)->send();
        }
    }
}

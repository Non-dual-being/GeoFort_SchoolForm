<?php

declare(strict_types=1);

namespace GeoFort\Services\Http\Api\Admin;

use GeoFort\Security\AuthMiddleware;
use GeoFort\Security\SessionGuard;
use GeoFort\Services\Auth\CsrfTokenService;
use GeoFort\Services\Dashboard\Roster\RosterLifecycleService;
use GeoFort\Services\Dashboard\Roster\RosterPlanException;
use GeoFort\Services\Http\Response\JsonResponse;
use JsonException;
use Throwable;

final readonly class DashboardRosterReopenAction
{
    public const CSRF_SCOPE = 'reopen-roster-plan';

    public function __construct(
        private AuthMiddleware $auth,
        private SessionGuard $guard,
        private CsrfTokenService $csrf,
        private RosterLifecycleService $service,
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
        if (!str_starts_with(strtolower(trim($contentType)), 'application/json')) {
            $this->response->json(['ok' => false, 'code' => 'INVALID_REQUEST'], 422)->send();
            return;
        }
        try {
            $request = DashboardRosterRevisionRequest::fromArray(json_decode($body, true, 32, JSON_THROW_ON_ERROR));
            $data = $this->service->reopen($request->planId, $request->expectedRevision, (int) $_SESSION['user_id']);
            $this->response->json(['ok' => true, 'data' => ['plan' => $data]])->send();
        } catch (RosterPlanException $exception) {
            $this->response->json(['ok' => false, 'code' => $exception->publicCode, 'message' => $exception->getMessage(),
                'validation' => $exception->validation], $exception->httpStatus)->send();
        } catch (JsonException) {
            $this->response->json(['ok' => false, 'code' => 'INVALID_REQUEST'], 422)->send();
        } catch (Throwable $exception) {
            error_log('[DashboardRosterReopenAction] ' . $exception::class);
            $this->response->serverError('De roosteractie kon niet worden uitgevoerd.', 500, false)->send();
        }
    }
}

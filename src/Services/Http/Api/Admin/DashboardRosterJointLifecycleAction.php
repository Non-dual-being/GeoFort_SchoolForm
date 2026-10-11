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

final readonly class DashboardRosterJointLifecycleAction
{
    public const CSRF_SCOPE = 'roster-joint-lifecycle';

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
        try {
            if (!str_starts_with(strtolower(trim($contentType)), 'application/json')) throw new JsonException();
            $request = DashboardRosterDayLessonsApplyRequest::fromBody($body);
            $payload=json_decode($body,true,16,JSON_THROW_ON_ERROR);
            $mode = $payload['mode'] ?? null;
            if (!in_array($mode, ['preview', 'finalize', 'reopen'], true) || count($request->expectedRevisions) !== 2) throw new JsonException();
            $signature = $payload['signature'] ?? null;
            if ($signature !== null && (!is_string($signature) || preg_match('/^[a-f0-9]{64}$/D', $signature) !== 1)) throw new JsonException();
            $acceptedWarnings = $payload['acceptedWarnings'] ?? false;
            if (!is_bool($acceptedWarnings)) throw new JsonException();
            $result = $this->service->joint($request->expectedRevisions, $mode, $signature, $acceptedWarnings, (int) $_SESSION['user_id']);
            $this->response->json(['ok' => true, 'data' => $result])->send();
        } catch (JsonException) {
            $this->response->json(['ok' => false, 'code' => 'INVALID_REQUEST'], 422)->send();
        } catch (RosterPlanException $exception) {
            $this->response->json(['ok' => false, 'code' => $exception->publicCode, 'message' => $exception->getMessage()], $exception->httpStatus)->send();
        } catch (Throwable $exception) {
            error_log('[DashboardRosterJointLifecycleAction] ' . $exception::class);
            $this->response->serverError('De gezamenlijke planning kon niet worden gewijzigd.', 500, false)->send();
        }
    }
}

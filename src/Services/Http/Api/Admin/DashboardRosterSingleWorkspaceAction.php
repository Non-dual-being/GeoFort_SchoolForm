<?php
declare(strict_types=1);

namespace GeoFort\Services\Http\Api\Admin;

use GeoFort\Security\AuthMiddleware;
use GeoFort\Security\SessionGuard;
use GeoFort\Services\Auth\CsrfTokenService;
use GeoFort\Services\Dashboard\Roster\RosterJointWorkspaceService;
use GeoFort\Services\Dashboard\Roster\RosterPlanException;
use GeoFort\Services\Http\Response\JsonResponse;
use JsonException;
use Throwable;

final readonly class DashboardRosterSingleWorkspaceAction
{
    public const CSRF_SCOPE = 'roster-single-workspace';

    public function __construct(
        private AuthMiddleware $auth,
        private SessionGuard $guard,
        private CsrfTokenService $csrf,
        private RosterJointWorkspaceService $service,
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
            if (strlen($body)>150000 || !str_starts_with(strtolower(trim($contentType)), 'application/json')) throw new JsonException();
            $request = DashboardRosterJointWorkspaceRequest::fromBody($body,true);
            // Release the PHP session lock before a bounded optimization search.
            $actor=(int)$_SESSION['user_id']; session_write_close();
            $result=$this->service->run($request->expectedRevisions,$request->mode,$request->state,$request->options,$request->signature,$actor);
            $this->response->json(['ok' => true, 'data' => $result])->send();
        } catch (JsonException) {
            $this->response->json(['ok' => false, 'code' => 'INVALID_REQUEST'], 422)->send();
        } catch (RosterPlanException $exception) {
            $this->response->json(['ok' => false, 'code' => $exception->publicCode, 'message' => $exception->getMessage()], $exception->httpStatus)->send();
        } catch (Throwable $exception) {
            error_log('[DashboardRosterSingleWorkspaceAction] ' . $exception::class);
            $this->response->serverError('Het rooster kon niet worden gewijzigd.', 500, false)->send();
        }
    }
}

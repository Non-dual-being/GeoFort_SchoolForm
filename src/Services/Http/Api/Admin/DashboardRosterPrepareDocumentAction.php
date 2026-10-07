<?php

declare(strict_types=1);

namespace GeoFort\Services\Http\Api\Admin;

use GeoFort\Security\AuthMiddleware;
use GeoFort\Security\SessionGuard;
use GeoFort\Services\Auth\CsrfTokenService;
use GeoFort\Services\Dashboard\Roster\RosterPlanException;
use GeoFort\Services\Dashboard\Roster\RosterSchoolDocument;
use GeoFort\Services\Dashboard\Roster\RosterSchoolDocumentService;
use GeoFort\Services\Http\Response\JsonResponse;
use Throwable;

final readonly class DashboardRosterPrepareDocumentAction
{
    public const CSRF_SCOPE = 'prepare-roster-document';

    public function __construct(
        private AuthMiddleware $auth,
        private SessionGuard $sessionGuard,
        private CsrfTokenService $csrf,
        private RosterSchoolDocumentService $documents,
        private JsonResponse $response,
    ) {}

    public function send(string $method, string $contentType, ?string $token, string $body, string $userAgent): void
    {
        $this->auth->startPublicSession();
        if (!$this->sessionGuard->validate($userAgent)) {
            $this->auth->invalidateAuthentication();
            $this->response->json(['ok' => false, 'code' => 'UNAUTHENTICATED'], 401)->send();
            return;
        }
        if ($method !== 'POST') {
            $this->response->methodNotAllowed()->header('Allow', 'POST')->send();
            return;
        }
        if (!$this->csrf->validate(self::CSRF_SCOPE, $token)) {
            $this->response->json(['ok' => false, 'code' => 'INVALID_CSRF'], 403)->send();
            return;
        }
        if (strtolower(trim(explode(';', $contentType, 2)[0])) !== 'application/json') {
            $this->response->json(['ok' => false, 'code' => 'INVALID_CONTENT_TYPE'], 400)->send();
            return;
        }
        try {
            $request = DashboardRosterPrepareDocumentRequest::fromJson($body);
            $document = $this->documents->ensurePrepared($request->bookingId, $request->snapshotId);
            $this->response->json(['ok' => true, 'data' => ['document' => [
                'snapshotId' => $document->snapshotId, 'filename' => $document->filename,
                'mimeType' => RosterSchoolDocument::MIME, 'sha256' => $document->hash,
                'byteLength' => strlen($document->bytes),
            ]]])->send();
        } catch (RosterPlanException $exception) {
            $this->response->json(['ok' => false, 'code' => $exception->publicCode, 'message' => $exception->getMessage()], $exception->httpStatus)->send();
        } catch (Throwable $exception) {
            error_log('[DashboardRosterPrepareDocumentAction] ' . $exception::class);
            $this->response->json(['ok' => false, 'code' => 'ROSTER_ATTACHMENT_FAILED',
                'message' => 'Het definitieve schoolrooster kon niet worden voorbereid. Probeer het opnieuw.'], 500)->send();
        }
    }
}

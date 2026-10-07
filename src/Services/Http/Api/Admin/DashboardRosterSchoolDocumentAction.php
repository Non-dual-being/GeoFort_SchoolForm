<?php

declare(strict_types=1);

namespace GeoFort\Services\Http\Api\Admin;

use GeoFort\Security\AuthMiddleware;
use GeoFort\Security\SessionGuard;
use GeoFort\Services\Dashboard\Roster\RosterPlanException;
use GeoFort\Services\Dashboard\Roster\RosterSchoolDocumentService;
use GeoFort\Services\Http\Response\JsonResponse;
use Throwable;

final readonly class DashboardRosterSchoolDocumentAction
{
    public function __construct(
        private AuthMiddleware $auth,
        private SessionGuard $sessionGuard,
        private RosterSchoolDocumentService $documents,
        private JsonResponse $response,
    ) {}

    /** $download is fixed by the endpoint, never by client input. */
    public function send(string $method, array $query, bool $download, string $userAgent): void
    {
        $this->auth->startPublicSession();
        header('Cache-Control: no-store, private');
        header('Pragma: no-cache');
        header('X-Content-Type-Options: nosniff');
        if (!$this->sessionGuard->validate($userAgent)) {
            $this->auth->invalidateAuthentication();
            $this->response->json(['ok' => false, 'code' => 'UNAUTHENTICATED'], 401)->send();
            return;
        }
        if ($method !== 'GET') {
            $this->response->methodNotAllowed()->header('Allow', 'GET')->send();
            return;
        }
        $id = $this->id($query['bookingId'] ?? null);
        $snapshotId = $this->id($query['snapshotId'] ?? null);
        if ($id === null || ($download && $snapshotId === null)
            || array_diff(array_keys($query), $download ? ['bookingId', 'snapshotId'] : ['bookingId']) !== []) {
            $this->response->json(['ok' => false, 'code' => 'INVALID_ROSTER_ATTACHMENT', 'message' => 'Ongeldige roosterkeuze.'], 422)->send();
            return;
        }
        try {
            if (!$download) {
                $this->response->json(['ok' => true, 'data' => ['plannerFinal' => $this->documents->available($id)]])->send();
                return;
            }
            $document = $this->documents->readPrepared($id, $snapshotId);
            header('Content-Type: application/pdf');
            header('Content-Disposition: attachment; filename="' . $document->filename . '"');
            header('Content-Length: ' . strlen($document->bytes));
            echo $document->bytes;
        } catch (RosterPlanException $exception) {
            $this->response->json(['ok' => false, 'code' => $exception->publicCode, 'message' => $exception->getMessage()], $exception->httpStatus)->send();
        } catch (Throwable $exception) {
            error_log('[DashboardRosterSchoolDocumentAction] ' . $exception::class);
            $this->response->json(['ok' => false, 'code' => 'ROSTER_ATTACHMENT_FAILED',
                'message' => 'Het definitieve schoolrooster kon niet worden voorbereid. Probeer het opnieuw.'], 500)->send();
        }
    }

    private function id(mixed $value): ?int
    {
        if (!is_string($value) || !preg_match('/^[1-9][0-9]*$/D', $value)) return null;
        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        return $id === false ? null : $id;
    }
}

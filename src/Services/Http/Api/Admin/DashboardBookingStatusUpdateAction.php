<?php
declare(strict_types=1);

namespace GeoFort\Services\Http\Api\Admin;

use GeoFort\Booking\Status\BookingStatusChangeCode;
use GeoFort\Booking\Status\BookingStatusChangeCommand;
use GeoFort\Booking\Status\BookingStatusChangeResult;
use GeoFort\Security\AuthMiddleware;
use GeoFort\Security\SessionGuard;
use GeoFort\Services\Auth\CsrfTokenService;
use GeoFort\Services\Booking\Status\BookingStatusChangeService;
use GeoFort\Services\Http\Response\JsonResponse;
use Throwable;

final readonly class DashboardBookingStatusUpdateAction
{
    public const CSRF_SCOPE = 'update-booking-status';

    public function __construct(
        private AuthMiddleware $auth,
        private SessionGuard $sessionGuard,
        private CsrfTokenService $csrf,
        private BookingStatusChangeService $statusChanges,
        private BookingStatusUpdateResponseMapper $responseMapper,
        private JsonResponse $response,
    ) {}

    public function send(string $method, string $contentType, ?string $csrfToken, string $rawBody, string $userAgent): void
    {
        $this->auth->startPublicSession();
        if (!$this->sessionGuard->validate($userAgent)) {
            $this->auth->invalidateAuthentication();
            $this->error('UNAUTHENTICATED', 0, 401);
            return;
        }
        if ($method !== 'POST') {
            $this->response->json($this->payload(false, 'METHOD_NOT_ALLOWED', 0), 405)->header('Allow', 'POST')->send();
            return;
        }
        if (!str_starts_with(strtolower(trim($contentType)), 'application/json')) {
            $this->error('INVALID_CONTENT_TYPE', 0, 400);
            return;
        }
        if (!$this->csrf->validate(self::CSRF_SCOPE, $csrfToken)) {
            $this->error('INVALID_CSRF', 0, 403);
            return;
        }

        try {
            $request = BookingStatusUpdateRequest::fromJson($rawBody);
        } catch (BookingStatusUpdateRequestException $exception) {
            $status = in_array($exception->publicCode, ['INVALID_CURRENT_STATUS', 'INVALID_TARGET_STATUS'], true) ? 422 : 400;
            $this->error($exception->publicCode, 0, $status);
            return;
        }

        try {
            $result = $this->statusChanges->change(new BookingStatusChangeCommand(
                $request->bookingId,
                $request->expectedCurrentStatus,
                $request->targetStatus,
                (int) $_SESSION['user_id'],
            ));
            $mapped = $this->responseMapper->map($result);
            $this->response->json($mapped['payload'], $mapped['status'])->send();
        } catch (Throwable) {
            error_log('[DashboardBookingStatusUpdateAction] Statuswijziging kon niet worden uitgevoerd.');
            $this->error('DATABASE_ERROR', $request->bookingId, 500);
        }
    }

    private function error(string $code, int $bookingId, int $status): void
    {
        $this->response->json($this->payload(false, $code, $bookingId), $status)->send();
    }

    /** @param list<array{code: string, category: string, field: string}> $issues @param array<string, mixed>|null $capacity */
    private function payload(bool $ok, string $code, int $bookingId, ?string $previous = null, ?string $current = null, array $issues = [], ?array $capacity = null): array
    {
        return ['ok' => $ok, 'code' => $code, 'bookingId' => $bookingId, 'previousStatus' => $previous,
            'currentStatus' => $current, 'validationIssues' => $issues, 'capacity' => $capacity];
    }
}

<?php

declare(strict_types=1);

namespace GeoFort\Services\Http\Api\Admin;

use GeoFort\Security\AuthMiddleware;
use GeoFort\Security\SessionGuard;
use GeoFort\Services\Auth\CsrfTokenService;
use GeoFort\Services\Dashboard\Roster\RosterPlanException;
use GeoFort\Services\Dashboard\Roster\RosterPlanService;
use GeoFort\Services\Http\Response\JsonResponse;
use JsonException;
use Throwable;

final readonly class DashboardRosterCreateAction
{
    public const CSRF_SCOPE = 'create-roster-plan';

    public function __construct(
        private AuthMiddleware $auth,
        private SessionGuard $guard,
        private CsrfTokenService $csrf,
        private RosterPlanService $service,
        private JsonResponse $response,
    ) {}

    public function send(
        string $method,
        string $contentType,
        ?string $token,
        string $body,
        string $userAgent,
    ): void {
        $this->auth->startPublicSession();

        if (!$this->guard->validate($userAgent)) {
            $this->auth->invalidateAuthentication();
            $this->response->json(['ok' => false, 'code' => 'UNAUTHENTICATED'], 401)->send();
            return;
        }

        if ($method !== 'POST') {
            $this->response
                ->json(['ok' => false, 'code' => 'METHOD_NOT_ALLOWED'], 405)
                ->header('Allow', 'POST')
                ->send();
            return;
        }

        if (!str_starts_with(strtolower(trim($contentType)), 'application/json')) {
            $this->response->json(['ok' => false, 'code' => 'INVALID_REQUEST'], 422)->send();
            return;
        }

        if (!$this->csrf->validate(self::CSRF_SCOPE, $token)) {
            $this->response->json(['ok' => false, 'code' => 'INVALID_CSRF'], 403)->send();
            return;
        }

        try {
            $payload = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $this->response->json(['ok' => false, 'code' => 'INVALID_REQUEST'], 422)->send();
            return;
        }

        if (!is_array($payload)) {
            $this->response->json(['ok' => false, 'code' => 'INVALID_REQUEST'], 422)->send();
            return;
        }

        $source = $payload['source'] ?? null;
        if ($source !== null && !in_array($source, ['booking', 'manual'], true)) {
            $this->response->json(['ok' => false, 'code' => 'INVALID_REQUEST'], 422)->send();
            return;
        }

        try {
            if ($source === 'manual') {
                $schoolName = $payload['schoolName'] ?? null;
                $city = $payload['city'] ?? '';
                $visitDate = $payload['visitDate'] ?? null;
                $sector = $payload['sector'] ?? null;
                $program = $payload['program'] ?? null;
                $studentCount = $payload['studentCount'] ?? null;
                $groupCount = $payload['groupCount'] ?? null;
                $bookingId = $payload['bookingId'] ?? null;

                if (
                    !is_string($schoolName)
                    || !is_string($city)
                    || !is_string($visitDate)
                    || !is_string($sector)
                    || !is_string($program)
                    || !is_int($studentCount)
                    || !is_int($groupCount)
                    || ($bookingId !== null && (!is_int($bookingId) || $bookingId <= 0))
                ) {
                    $this->response->json(['ok' => false, 'code' => 'INVALID_REQUEST'], 422)->send();
                    return;
                }

                $result = $this->service->createManual(
                    schoolName: $schoolName,
                    city: $city,
                    visitDate: $visitDate,
                    sector: $sector,
                    program: $program,
                    studentCount: $studentCount,
                    groupCount: $groupCount,
                    actingAdminId: (int) $_SESSION['user_id'],
                    bookingId: $bookingId,
                );
            } else {
                $bookingId = $payload['bookingId'] ?? null;
                if (!is_int($bookingId) || $bookingId <= 0) {
                    $this->response->json(['ok' => false, 'code' => 'INVALID_REQUEST'], 422)->send();
                    return;
                }

                $result = $this->service->createFromBooking(
                    $bookingId,
                    (int) $_SESSION['user_id'],
                );
            }

            $this->response->json(
                ['ok' => true, 'data' => $result],
                $result['created'] ? 201 : 200,
            )->send();
        } catch (RosterPlanException $exception) {
            $this->response->json([
                'ok' => false,
                'code' => $exception->publicCode,
                'message' => $exception->getMessage(),
            ], $exception->httpStatus)->send();
        } catch (Throwable $exception) {
            error_log(
                '[DashboardRosterCreateAction] Roosterplan aanmaken mislukt: '
                . $exception::class,
            );
            $this->response->serverError(
                'Rooster kon niet worden aangemaakt.',
                500,
                false,
            )->send();
        }
    }
}

<?php
declare(strict_types=1);
namespace GeoFort\Services\Http\Api\Admin;

use GeoFort\Services\Dashboard\Booking\DashboardBookingVisitDateCalendarService;
use GeoFort\Services\Http\PrivatePageBootstrapper;
use GeoFort\Services\Http\Response\JsonResponse;
use Throwable;

final readonly class DashboardBookingVisitDateCalendarAction
{
    public function __construct(private PrivatePageBootstrapper $privatePageBootstrapper, private DashboardBookingVisitDateCalendarService $service, private JsonResponse $response) {}
    /** @param array<string, mixed> $query */
    public function send(string $method, array $query): void
    {
        try {
            $this->privatePageBootstrapper->init();
            if ($method !== 'GET') {$this->response->methodNotAllowed()->header('Allow', 'GET')->send();return;}
            $request = BookingVisitDateCalendarRequest::fromQuery($query);
            if ($request === null) {$this->response->json(['ok' => false, 'type' => 'validation', 'code' => 400], 400)->send();return;}
            $calendar = $this->service->get($request->bookingId, $request->startDate, $request->endDate);
            if ($calendar === null) {$this->response->json(['ok' => false, 'type' => 'not-found', 'code' => 404], 404)->send();return;}
            $this->response->json(['ok' => true, 'data' => ['calendar' => $calendar]])->send();
        } catch (Throwable) {
            error_log('[DashboardBookingVisitDateCalendarAction] Kalender kon niet worden geladen.');
            $this->response->serverError('Kalender kon niet worden geladen.', 500, false)->send();
        }
    }
}

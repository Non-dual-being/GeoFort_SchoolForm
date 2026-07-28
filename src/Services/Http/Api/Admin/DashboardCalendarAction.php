<?php

declare(strict_types=1);

namespace GeoFort\Services\Http\Api\Admin;

use GeoFort\Services\Dashboard\Calendar\DashboardCalendarService;
use GeoFort\Services\Http\PrivatePageBootstrapper;
use GeoFort\Services\Http\Response\JsonResponse;
use Throwable;

final readonly class DashboardCalendarAction
{
    public function __construct(
        private PrivatePageBootstrapper $privatePageBootstrapper,
        private DashboardCalendarService $service,
        private JsonResponse $response,
    ) {}

    /** @param array<string, mixed> $query */
    public function send(string $method, array $query): void
    {
        try {
            $this->privatePageBootstrapper->init();
            if ($method !== 'GET') {
                $this->response->methodNotAllowed()->header('Allow', 'GET')->send();
                return;
            }

            $request = DashboardCalendarRequest::fromQuery($query);
            if ($request === null) {
                $this->response->json(['ok' => false, 'type' => 'validation', 'code' => 400], 400)->send();
                return;
            }

            $this->response->json([
                'ok' => true,
                'data' => ['calendar' => $this->service->get($request->startDate, $request->endDate)],
            ])->send();
        } catch (Throwable $exception) {
            error_log('[DashboardCalendarAction] Agenda kon niet worden geladen: ' . $exception->getMessage());
            $this->response->serverError('Agenda kon niet worden geladen.', 500, false)->send();
        }
    }
}

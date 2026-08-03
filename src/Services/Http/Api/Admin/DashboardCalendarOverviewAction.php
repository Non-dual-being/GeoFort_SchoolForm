<?php

declare(strict_types=1);

namespace GeoFort\Services\Http\Api\Admin;

use GeoFort\Services\Dashboard\Calendar\DashboardCalendarOverviewService;
use GeoFort\Services\Http\PrivatePageBootstrapper;
use GeoFort\Services\Http\Response\JsonResponse;
use Throwable;

final readonly class DashboardCalendarOverviewAction
{
    public function __construct(
        private PrivatePageBootstrapper $privatePageBootstrapper,
        private DashboardCalendarOverviewService $service,
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
            $request = DashboardCalendarOverviewRequest::fromQuery($query);
            if ($request === null) {
                $this->response->json(['ok' => false, 'type' => 'validation', 'code' => 400], 400)->send();
                return;
            }
            $this->response->json(['ok' => true, 'data' => ['calendar' => $this->service->get($request->year, $request->month)->toArray()]])
                ->header('Cache-Control', 'private, no-store')
                ->send();
        } catch (Throwable $exception) {
            error_log('[DashboardCalendarOverviewAction] Overzichtsagenda kon niet worden geladen.');
            $this->response->serverError('Overzichtsagenda kon niet worden geladen.', 500, false)->send();
        }
    }
}

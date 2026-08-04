<?php
declare(strict_types=1);

namespace GeoFort\Services\Http\Api\Admin;

use GeoFort\Services\Dashboard\Overview\DashboardOverviewService;
use GeoFort\Services\Http\PrivatePageBootstrapper;
use GeoFort\Services\Http\Response\JsonResponse;
use Throwable;

final readonly class DashboardOverviewAction
{
    public function __construct(private PrivatePageBootstrapper $privatePageBootstrapper, private DashboardOverviewService $service, private JsonResponse $response) {}

    public function send(string $method): void
    {
        try {
            $this->privatePageBootstrapper->init();
            if ($method !== 'GET') {
                $this->response->methodNotAllowed()->header('Allow', 'GET')->send();
                return;
            }
            $this->response->json(['ok' => true, 'data' => ['dashboard' => $this->service->get()->toArray()]])
                ->header('Cache-Control', 'private, no-store')->send();
        } catch (Throwable $exception) {
            error_log('[DashboardOverviewAction] Dashboardgegevens konden niet worden geladen.');
            $this->response->serverError('Dashboardgegevens konden niet worden geladen.', 500, false)->send();
        }
    }
}

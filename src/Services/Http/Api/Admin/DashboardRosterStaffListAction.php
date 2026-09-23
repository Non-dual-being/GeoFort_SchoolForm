<?php

declare(strict_types=1);

namespace GeoFort\Services\Http\Api\Admin;

use GeoFort\Services\Dashboard\Roster\RosterStaffConfigService;
use GeoFort\Services\Http\PrivatePageBootstrapper;
use GeoFort\Services\Http\Response\JsonResponse;
use Throwable;

final readonly class DashboardRosterStaffListAction
{
    public function __construct(
        private PrivatePageBootstrapper $privatePageBootstrapper,
        private RosterStaffConfigService $service,
        private JsonResponse $response,
    ) {}

    public function send(string $method): void
    {
        try {
            $this->privatePageBootstrapper->init();

            if ($method !== 'GET') {
                $this->response->methodNotAllowed()->header('Allow', 'GET')->send();
                return;
            }

            $this->response->json([
                'ok' => true,
                'data' => ['items' => $this->service->catalog()],
            ])->send();
        } catch (Throwable $exception) {
            error_log('[DashboardRosterStaffListAction] ' . $exception::class);
            $this->response->serverError(
                'Personeelsconfiguratie kon niet worden geladen.',
                500,
                false,
            )->send();
        }
    }
}
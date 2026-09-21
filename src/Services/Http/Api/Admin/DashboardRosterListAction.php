<?php

declare(strict_types=1);

namespace GeoFort\Services\Http\Api\Admin;

use GeoFort\Services\Dashboard\Roster\RosterPlanService;
use GeoFort\Services\Http\PrivatePageBootstrapper;
use GeoFort\Services\Http\Response\JsonResponse;
use Throwable;

final readonly class DashboardRosterListAction
{
    public function __construct(
        private PrivatePageBootstrapper $privatePageBootstrapper,
        private RosterPlanService $service,
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
                'data' => ['items' => $this->service->listPlans()],
            ])->send();
        } catch (Throwable) {
            error_log('[DashboardRosterListAction] Roosterplannen konden niet worden geladen.');
            $this->response->serverError(
                'Roosters konden niet worden geladen.',
                500,
                false,
            )->send();
        }
    }
}
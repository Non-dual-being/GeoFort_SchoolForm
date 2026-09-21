<?php

declare(strict_types=1);

namespace GeoFort\Services\Http\Api\Admin;

use GeoFort\Services\Dashboard\Roster\RosterPlanService;
use GeoFort\Services\Http\PrivatePageBootstrapper;
use GeoFort\Services\Http\Response\JsonResponse;
use Throwable;

final readonly class DashboardRosterDetailAction
{
    public function __construct(
        private PrivatePageBootstrapper $privatePageBootstrapper,
        private RosterPlanService $service,
        private JsonResponse $response,
    ) {}

    /** @param array<string,mixed> $query */
    public function send(string $method, array $query): void
    {
        try {
            $this->privatePageBootstrapper->init();

            if ($method !== 'GET') {
                $this->response->methodNotAllowed()->header('Allow', 'GET')->send();
                return;
            }

            $rawId = $query['id'] ?? null;
            $id = is_string($rawId)
                ? filter_var($rawId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
                : false;

            if ($id === false) {
                $this->response->json(
                    ['ok' => false, 'type' => 'validation', 'code' => 400],
                    400,
                )->send();
                return;
            }

            $plan = $this->service->getPlan((int) $id);
            if ($plan === null) {
                $this->response->json(
                    ['ok' => false, 'type' => 'not-found', 'code' => 404],
                    404,
                )->send();
                return;
            }

            $this->response->json([
                'ok' => true,
                'data' => ['plan' => $plan],
            ])->send();
        } catch (Throwable) {
            error_log('[DashboardRosterDetailAction] Roosterplan kon niet worden geladen.');
            $this->response->serverError(
                'Rooster kon niet worden geladen.',
                500,
                false,
            )->send();
        }
    }
}
<?php
declare(strict_types=1);

namespace GeoFort\Services\Http\Api\Admin;

use GeoFort\Services\Dashboard\Booking\Export\BookingExportSummaryService;
use GeoFort\Services\Http\PrivatePageBootstrapper;
use GeoFort\Services\Http\Response\JsonResponse;
use Throwable;

final readonly class DashboardBookingExportMetadataAction
{
    public function __construct(
        private PrivatePageBootstrapper $privatePageBootstrapper,
        private BookingExportSummaryService $summaryService,
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
                'data' => ['bounds' => $this->summaryService->bounds()->toArray()],
            ])->send();
        } catch (Throwable $exception) {
            error_log('[DashboardBookingExportMetadataAction] metadata failed: ' . $exception::class);
            $this->response->serverError('Exportdatums konden niet worden geladen.', 500, false)->send();
        }
    }
}

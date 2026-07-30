<?php
declare(strict_types=1);

namespace GeoFort\Services\Http\Api\Admin;

use GeoFort\Services\Dashboard\Booking\Export\BookingExportCriteriaFactory;
use GeoFort\Services\Dashboard\Booking\Export\BookingExportSummaryService;
use GeoFort\Services\Http\PrivatePageBootstrapper;
use GeoFort\Services\Http\Response\JsonResponse;
use GeoFort\Validation\FieldValidationException;
use Throwable;

final readonly class DashboardBookingExportSummaryAction
{
    public function __construct(
        private PrivatePageBootstrapper $privatePageBootstrapper,
        private BookingExportCriteriaFactory $criteriaFactory,
        private BookingExportSummaryService $summaryService,
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
            $criteria = $this->criteriaFactory->create($query, $this->summaryService->bounds());
            $this->response->json([
                'ok' => true,
                'data' => [
                    'period' => $criteria->toArray(),
                    'summary' => $this->summaryService->summarize($criteria),
                ],
            ])->send();
        } catch (FieldValidationException $exception) {
            $this->response->validationError([
                $exception->getField() => $exception->getMessage(),
            ])->send();
        } catch (Throwable $exception) {
            error_log('[DashboardBookingExportSummaryAction] summary failed: ' . $exception::class);
            $this->response->serverError('Exportsamenvatting kon niet worden geladen.', 500, false)->send();
        }
    }
}

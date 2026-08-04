<?php
declare(strict_types=1);

namespace GeoFort\Services\Http\Api\Admin;

use GeoFort\Services\Dashboard\Booking\Revenue\BookingRevenueCriteriaFactory;
use GeoFort\Services\Dashboard\Booking\Revenue\BookingRevenueReportMapper;
use GeoFort\Services\Dashboard\Booking\Revenue\BookingRevenueReportService;
use GeoFort\Services\Http\PrivatePageBootstrapper;
use GeoFort\Services\Http\Response\JsonResponse;
use GeoFort\Validation\FieldValidationException;
use Throwable;

final readonly class DashboardBookingRevenueReportAction
{
    public function __construct(private PrivatePageBootstrapper $privatePageBootstrapper, private BookingRevenueCriteriaFactory $criteriaFactory, private BookingRevenueReportService $service, private BookingRevenueReportMapper $mapper, private JsonResponse $response) {}

    /** @param array<string, mixed> $query */
    public function send(string $method, array $query): void
    {
        try {
            $this->privatePageBootstrapper->init();
            if ($method !== 'GET') { $this->response->methodNotAllowed()->header('Allow', 'GET')->send(); return; }
            $report = $this->service->report($this->criteriaFactory->create($query));
            $this->response->json(['ok'=>true,'data'=>$this->mapper->map($report)])->send();
        } catch (FieldValidationException $exception) {
            $this->response->validationError([$exception->getField()=>$exception->getMessage()])->send();
        } catch (Throwable $exception) {
            error_log('[DashboardBookingRevenueReportAction] report failed: ' . $exception::class);
            $this->response->serverError('Omzetrapportage kon niet worden geladen.')->send();
        }
    }
}

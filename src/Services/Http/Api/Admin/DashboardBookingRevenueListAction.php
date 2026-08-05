<?php
declare(strict_types=1);

namespace GeoFort\Services\Http\Api\Admin;

use GeoFort\Services\Dashboard\Booking\Revenue\BookingRevenueCriteriaFactory;
use GeoFort\Services\Dashboard\Booking\Revenue\BookingRevenueReportService;
use GeoFort\Services\Http\PrivatePageBootstrapper;
use GeoFort\Services\Http\Response\JsonResponse;
use GeoFort\Validation\FieldValidationException;
use Throwable;

final readonly class DashboardBookingRevenueListAction
{
    public function __construct(private PrivatePageBootstrapper $auth, private BookingRevenueCriteriaFactory $factory, private BookingRevenueReportService $service, private JsonResponse $response) {}

    /** @param array<string, mixed> $query */
    public function send(string $method, array $query): void
    {
        try {
            $this->auth->init();
            if ($method !== 'GET') { $this->response->methodNotAllowed()->header('Allow', 'GET')->send(); return; }
            $bounds = $this->service->availableVisitDateRange();
            $data = $bounds->isEmpty() ? ['items'=>[], 'pagination'=>['currentPage'=>1,'perPage'=>10,'totalItems'=>0,'totalPages'=>0,'from'=>0,'to'=>0]] : $this->service->page($this->factory->create($query, $bounds));
            $this->response->json(['ok'=>true,'data'=>$data])->send();
        } catch (FieldValidationException $e) { $this->response->validationError([$e->getField()=>$e->getMessage()])->send(); }
        catch (Throwable $e) { error_log('[DashboardBookingRevenueListAction] failed: '.$e::class); $this->response->serverError('Boekingen konden niet worden geladen.')->send(); }
    }
}

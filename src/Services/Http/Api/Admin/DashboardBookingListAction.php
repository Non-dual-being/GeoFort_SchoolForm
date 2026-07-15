<?php
declare(strict_types=1);

namespace GeoFort\Services\Http\Api\Admin;

use GeoFort\Services\Dashboard\Booking\DashboardBookingFilterParser;
use GeoFort\Services\Dashboard\Booking\DashboardBookingListService;
use GeoFort\Services\Http\PrivatePageBootstrapper;
use GeoFort\Services\Http\Response\JsonResponse;
use GeoFort\Validation\FieldValidationException;
use Throwable;

final readonly class DashboardBookingListAction
{
    public function __construct(
        private PrivatePageBootstrapper $privatePageBootstrapper,
        private DashboardBookingFilterParser $filterParser,
        private DashboardBookingListService $bookingListService,
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

            $filters = $this->filterParser->parse($query);
            $page = $this->bookingListService->getPage($filters);

            $this->response->json([
                'ok' => true,
                'data' => [
                    ...$page->toArray(),
                    'filters' => $this->bookingListService->getFilterOptions(),
                ],
            ])->send();
        } catch (FieldValidationException $e) {
            $this->response->validationError([$e->getField() => $e->getMessage()])->send();
        } catch (Throwable $e) {
            error_log('[DashboardBookingListAction] ' . $e->getMessage());
            $this->response->serverError('Aanvragen konden niet worden geladen.', 500, false)->send();
        }
    }
}

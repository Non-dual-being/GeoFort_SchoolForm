<?php
declare(strict_types=1);

namespace GeoFort\Services\Http\Api\Admin;

use GeoFort\Dashboard\CapacityTarget\CapacityTargetPolicy;
use GeoFort\Services\Dashboard\Booking\Analytics\BookingAnalyticsCriteriaFactory;
use GeoFort\Services\Dashboard\Booking\Analytics\BookingAnalyticsService;
use GeoFort\Services\Http\PrivatePageBootstrapper;
use GeoFort\Services\Http\Response\JsonResponse;
use GeoFort\Validation\FieldValidationException;
use Throwable;

final readonly class DashboardBookingAnalyticsAction
{
    public function __construct(
        private PrivatePageBootstrapper $privatePageBootstrapper,
        private BookingAnalyticsCriteriaFactory $criteriaFactory,
        private BookingAnalyticsService $service,
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
            $bounds = $this->service->bounds();
            if (!isset($query['startDate'], $query['endDate']) && !$bounds->isEmpty()) {
                $query = ['startDate' => $bounds->minDate, 'endDate' => $bounds->maxDate];
            }
            if ($bounds->isEmpty()) {
                $this->response->json(['ok' => true, 'data' => [
                    'criteria' => null,
                    'dateBounds' => $bounds->toArray(),
                    ...$this->emptyResult(),
                ]])->send();
                return;
            }
            $criteria = $this->criteriaFactory->create($query, $bounds);
            $result = $this->service->analyze($criteria)->toArray();
            $result['capacityTargetContext']['canManage'] = $result['capacityTargetContext']['status'] === 'available'
                && CapacityTargetPolicy::canManage((string) ($_SESSION['user_role'] ?? ''));
            $this->response->json(['ok' => true, 'data' => [
                'criteria' => $criteria->toArray(),
                'dateBounds' => $bounds->toArray(),
                ...$result,
            ]])->send();
        } catch (FieldValidationException $exception) {
            $this->response->validationError([$exception->getField() => $exception->getMessage()])->send();
        } catch (Throwable $exception) {
            error_log('[DashboardBookingAnalyticsAction] analytics failed: ' . $exception::class);
            $this->response->serverError('Analytics konden niet worden geladen.')->send();
        }
    }

    /** @return array<string, mixed> */
    private function emptyResult(): array
    {
        return [
            'summary' => [
                'activeBookings' => 0, 'confirmedBookings' => 0, 'optionBookings' => 0,
                'rejectedBookings' => 0, 'plannedStudents' => 0, 'uniqueSchools' => 0,
                'uniqueVisitDays' => 0, 'averageStudentsPerActiveBooking' => 0.0,
                'averageStudentsPerVisitDay' => 0.0, 'rejectionPercentage' => 0,
            ],
            'monthlyTrend' => [], 'sectorDistribution' => [], 'programDistribution' => [],
            'choiceModuleDistribution' => [], 'compositionDistribution' => [
                'selectionBookings' => 0, 'voBookings' => 0, 'oneLevel' => 0, 'multipleLevels' => 0,
                'oneGroup' => 0, 'twoGroups' => 0, 'threeOrMoreGroups' => 0, 'averageGroups' => 0.0,
                'levelPopulationLabel' => 'VO-aanvragen met onderwijsselecties',
            ],
            'weekdayDistribution' => [], 'busiestVisitDates' => [],
            'newSchoolsByMonth' => [], 'capacityByMonth' => [], 'capacityTargetByMonth' => [],
            'capacityTargetContext' => [
                'status' => 'available',
                'today' => (new \DateTimeImmutable())->format('Y-m-d'), 'timezone' => 'Europe/Amsterdam',
                'canManage' => CapacityTargetPolicy::canManage((string) ($_SESSION['user_role'] ?? '')),
                'currentOfficialTarget' => null, 'history' => [], 'daySnapshots' => [],
            ],
            'studentCountAnalysis' => ['bins' => [], 'capacityBins' => [], 'programs' => [], 'invalidRecordCount' => 0, 'definitions' => [], 'context' => 'Geen data.'],
            'cateringAnalysis' => ['bookingProfiles' => [], 'schoolProfiles' => [], 'programBreakdown' => [], 'sectorBreakdown' => [], 'sizeBandBreakdown' => [], 'denominators' => ['bookings' => 0, 'schools' => 0], 'insights' => [], 'definitions' => [], 'context' => 'Geen data.'],
            'yearlyAnalysis' => ['generatedAt' => (new \DateTimeImmutable())->format(DATE_ATOM), 'analyticsAsOfDate' => (new \DateTimeImmutable())->format('Y-m-d'), 'years' => [], 'availableMetrics' => [], 'comparisonAvailability' => '', 'context' => 'Geen data.'],
            'seasonalityAnalysis' => ['monthlyBuckets' => [], 'dailyBuckets' => [], 'availableMetrics' => ['students' => 'Leerlingen', 'bookings' => 'Aanvragen', 'visitDays' => 'Unieke bezoekdagen'], 'summary' => ['bookings' => 0, 'students' => 0, 'uniqueVisitDates' => 0], 'topDays' => []],
        ];
    }
}

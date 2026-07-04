<?php

declare(strict_types=1);

namespace GeoFort\Services\Http\Api\Booking;

use DateTimeImmutable;
use DateTimeZone;
use GeoFort\Booking\BookingPolicy;
use GeoFort\Services\Booking\Availability\BookingAvailabilityService;
use GeoFort\Services\Http\Response\JsonResponse;
use GeoFort\Services\Sql\DisabledDatesSqlService;
use Throwable;

final class DisabledDatesAction
{
    private DateTimeZone $timezone;

    public function __construct(
        private readonly JsonResponse $response,
        private readonly DisabledDatesSqlService $disabledDatesSql,
        private readonly BookingAvailabilityService $bookingAvailabilityService,
    ) {
        $this->timezone = new DateTimeZone('Europe/Amsterdam');
    }

    public function send(): void
    {
        try {
            $today = new DateTimeImmutable('today', $this->timezone);

            $maxYearDate = $today->modify(
                '+' . BookingPolicy::BOOKABLE_YEARS_AHEAD . ' years',
            );

            $maxDateObject = $maxYearDate->setDate(
                (int) $maxYearDate->format('Y'),
                12,
                31,
            );

            $minDate = $today->format('Y-m-d');
            $maxDate = $maxDateObject->format('Y-m-d');

            $details = $this->disabledDatesSql->getDisabledDatesDetailed(
                $minDate,
                $maxDate,
            );

            $disabledDates = array_values(
                array_map(
                    static fn (array $row): string => (string) $row['datum'],
                    $details,
                ),
            );

            $capacity = $this->bookingAvailabilityService
                ->getCapacityForFrontend();

            $availabilityDetails = $this->bookingAvailabilityService
                ->getAvailabilityDetailsForRange(
                    $today,
                    $maxDateObject,
                );

            $this->response
                ->json([
                    'ok' => true,
                    'data' => [
                        'minDate' => $minDate,
                        'maxDate' => $maxDate,
                        'disabledDates' => $disabledDates,
                        'details' => $details,
                        'capacity' => $capacity,
                        'availabilityDetails' => $availabilityDetails,
                    ],
                ])
                ->send();
        } catch (Throwable $e) {
            error_log(__METHOD__ . ' : ' . $e->getMessage());

            $this->response
                ->serverError(
                    'Geblokkeerde datums konden niet worden opgehaald.',
                    500,
                    false,
                )
                ->send();
        }
    }
}
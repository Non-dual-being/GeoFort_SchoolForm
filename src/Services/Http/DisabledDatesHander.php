<?php

declare(strict_types=1);

namespace GeoFort\Services\Http;

use DateTimeImmutable;
use DateTimeZone;
use GeoFort\Services\Sql\DisabledDatesSqlService;
use Throwable;

final class DisabledDatesHandler
{
    private DateTimeZone $timezone;

    public function __construct(
        private readonly JsonResponse $response,
        private readonly DisabledDatesSqlService $disabledDatesSql
    ) {
        $this->timezone = new DateTimeZone('Europe/Amsterdam');
    }

    public function handle(): void
    {
        try {
            $today = new DateTimeImmutable('today', $this->timezone);

            $minDate = $today->format('Y-m-d');
            $maxDate = '2027-08-29';

            $details = $this->disabledDatesSql->getDisabledDatesDetailed(
                $minDate,
                $maxDate
            );

            $disabledDates = array_map(
                static fn (array $row): string => $row['datum'],
                $details
            );

            /**
             * array map allows a function that accepts details as data
             * 
             */

            $this->response
                ->json([
                    'ok' => true,
                    'data' => [
                        'minDate' => $minDate,
                        'maxDate' => $maxDate,
                        'disabledDates' => $disabledDates,
                        'details' => $details,
                    ],
                ])
                ->send();
        } catch (Throwable $e) {
            error_log(__METHOD__ . ' : ' . $e->getMessage());

            $this->response
                ->serverError(
                    'Geblokkeerde datums konden niet worden opgehaald.',
                    500,
                    false
                )
                ->send();
        }
    }
}

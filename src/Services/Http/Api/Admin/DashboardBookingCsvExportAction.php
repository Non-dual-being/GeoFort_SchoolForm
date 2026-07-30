<?php
declare(strict_types=1);

namespace GeoFort\Services\Http\Api\Admin;

use GeoFort\Services\Dashboard\Booking\Export\BookingCsvWriter;
use GeoFort\Services\Dashboard\Booking\Export\BookingExportCriteriaFactory;
use GeoFort\Services\Dashboard\Booking\Export\BookingExportService;
use GeoFort\Services\Dashboard\Booking\Export\BookingExportSummaryService;
use GeoFort\Services\Http\PrivatePageBootstrapper;
use GeoFort\Services\Http\Response\JsonResponse;
use GeoFort\Validation\FieldValidationException;
use Throwable;

final readonly class DashboardBookingCsvExportAction
{
    public function __construct(
        private PrivatePageBootstrapper $privatePageBootstrapper,
        private BookingExportCriteriaFactory $criteriaFactory,
        private BookingExportSummaryService $summaryService,
        private BookingExportService $exportService,
        private BookingCsvWriter $writer,
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
            if ($this->exportService->count($criteria) === 0) {
                $this->response->validationError([
                    'export' => 'Er zijn geen boekingen om te exporteren.',
                ])->send();
                return;
            }

            // Prepare and execute the query before response bytes make JSON errors impossible.
            $statement = $this->exportService->prepare($criteria);
            $filename = sprintf(
                'onderwijsboekingen_%s_tot_%s.csv',
                $criteria->effectiveStartDate,
                $criteria->effectiveEndDate,
            );

            header('Content-Type: text/csv; charset=UTF-8');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('Cache-Control: no-store, private');
            header('Pragma: no-cache');
            header('X-Content-Type-Options: nosniff');

            $stream = fopen('php://output', 'wb');
            if ($stream === false) {
                throw new \RuntimeException('CSV-uitvoer kon niet worden geopend.');
            }
            $this->writer->write($stream, $this->exportService->rows($statement));
            fclose($stream);
        } catch (FieldValidationException $e) {
            $this->response->validationError([$e->getField() => $e->getMessage()])->send();
        } catch (Throwable $e) {
            error_log('[DashboardBookingCsvExportAction] export failed: ' . $e::class);
            if (!headers_sent()) {
                $this->response->serverError('Exporteren is niet gelukt.', 500, false)->send();
            }
        }
    }
}

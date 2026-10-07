<?php

declare(strict_types=1);

namespace GeoFort\Services\Http\Api\Admin;

use GeoFort\Services\Dashboard\Roster\RosterPlanException;
use GeoFort\Services\Dashboard\Roster\RosterPlanService;
use GeoFort\Services\Dashboard\Roster\RosterXlsxWriter;
use GeoFort\Services\Http\PrivatePageBootstrapper;
use GeoFort\Services\Http\Response\JsonResponse;
use Throwable;

final readonly class DashboardRosterExcelExportAction
{
    public function __construct(
        private PrivatePageBootstrapper $privatePageBootstrapper,
        private RosterPlanService $service,
        private RosterXlsxWriter $writer,
        private JsonResponse $response,
    ) {}

    /** @param array<string,mixed> $query */
    public function send(string $method, array $query): void
    {
        $temporaryPath = null;

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
                $this->response->validationError(['id' => 'Ongeldig rooster-ID.'])->send();
                return;
            }

            $plan = $this->service->getPlan((int) $id);
            if ($plan === null) {
                $this->response->json(['ok' => false, 'code' => 'PLAN_NOT_FOUND'], 404)->send();
                return;
            }

            $temporaryPath = tempnam(sys_get_temp_dir(), 'geofort-roster-');
            if ($temporaryPath === false) {
                throw new \RuntimeException('Tijdelijk exportbestand kon niet worden aangemaakt.');
            }

            $this->writer->write($temporaryPath, $plan);

            $filename = sprintf(
                'rooster_%s_%s.xlsx',
                $this->filenamePart((string) ($plan['school']['name'] ?? 'school')),
                (string) ($plan['visitDate'] ?? 'datum'),
            );

            header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('Content-Length: ' . (string) filesize($temporaryPath));
            header('Cache-Control: no-store, private');
            header('Pragma: no-cache');
            header('X-Content-Type-Options: nosniff');

            readfile($temporaryPath);
        } catch (RosterPlanException $exception) {
            if (!headers_sent()) {
                $this->response->json([
                    'ok' => false,
                    'code' => $exception->publicCode,
                    'message' => $exception->getMessage(),
                ], $exception->httpStatus)->send();
            }
        } catch (Throwable $exception) {
            error_log('[DashboardRosterExcelExportAction] export failed: ' . $exception::class);
            if (!headers_sent()) {
                $this->response->serverError('Excel-exporteren is niet gelukt.', 500, false)->send();
            }
        } finally {
            if (is_string($temporaryPath) && is_file($temporaryPath)) {
                @unlink($temporaryPath);
            }
        }
    }

    private function filenamePart(string $value): string
    {
        $value = trim($value);
        $value = preg_replace('/[^A-Za-z0-9_-]+/', '-', $value) ?? 'school';
        $value = trim($value, '-_');

        return $value !== '' ? substr($value, 0, 60) : 'school';
    }
}
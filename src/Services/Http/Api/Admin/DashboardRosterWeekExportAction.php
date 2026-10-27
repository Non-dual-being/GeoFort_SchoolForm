<?php
declare(strict_types=1);

namespace GeoFort\Services\Http\Api\Admin;

use GeoFort\Services\Dashboard\Roster\RosterPlanException;
use GeoFort\Services\Dashboard\Roster\RosterWeekExportService;
use GeoFort\Services\Dashboard\Roster\RosterXlsxWriter;
use GeoFort\Services\Http\PrivatePageBootstrapper;
use GeoFort\Services\Http\Response\JsonResponse;
use Throwable;

final readonly class DashboardRosterWeekExportAction
{
    public function __construct(
        private PrivatePageBootstrapper $bootstrap,
        private RosterWeekExportService $service,
        private RosterXlsxWriter $writer,
        private JsonResponse $response,
    ) {}

    public function send(string $method, array $query): void
    {
        $temporaryPath = null;
        try {
            $this->bootstrap->init();
            if ($method !== 'GET') {
                $this->response->methodNotAllowed()->header('Allow', 'GET')->send();
                return;
            }
            if (!array_key_exists('week', $query)) {
                $this->response->json(['ok' => true, 'data' => ['weeks' => $this->service->weeks()]])->send();
                return;
            }
            $week = is_string($query['week']) ? $query['week'] : '';
            $data = $this->service->export($week);
            $temporaryPath = tempnam(sys_get_temp_dir(), 'geofort-week-');
            if ($temporaryPath === false) throw new \RuntimeException('Tijdelijk exportbestand ontbreekt.');
            $this->writer->writeWeek($temporaryPath, $data);
            header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
            header('Content-Disposition: attachment; filename="weekoverzicht_' . $week . '.xlsx"');
            header('Content-Length: ' . filesize($temporaryPath));
            header('Cache-Control: no-store, private');
            header('X-Content-Type-Options: nosniff');
            readfile($temporaryPath);
        } catch (RosterPlanException $error) {
            $this->response->json(['ok' => false, 'code' => $error->publicCode, 'message' => $error->getMessage()], $error->httpStatus)->send();
        } catch (Throwable $error) {
            error_log('[DashboardRosterWeekExportAction] ' . $error::class);
            if (!headers_sent()) $this->response->serverError('Weekoverzicht kon niet worden gemaakt.', 500, false)->send();
        } finally {
            if (is_string($temporaryPath) && is_file($temporaryPath)) unlink($temporaryPath);
        }
    }
}

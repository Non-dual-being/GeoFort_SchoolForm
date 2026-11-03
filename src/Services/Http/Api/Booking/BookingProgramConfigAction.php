<?php
declare(strict_types=1);
namespace GeoFort\Services\Http\Api\Booking;

use GeoFort\Booking\BookingProgramConfig;
use GeoFort\Services\Http\Response\JsonResponse;

final class BookingProgramConfigAction
{
    public function __construct(
        private readonly JsonResponse $response,
        private readonly ?\GeoFort\Services\Sql\BookingProgramAccessSqlRepository $access = null,
    ) {}

    public function send()
    {
        try {
        $bookProgramConfig = BookingProgramConfig::forFrontend();
        $bookProgramConfig['fourModuleConfig'] = \GeoFort\Booking\BookingProgramVersionConfig::forFrontend(\GeoFort\Booking\FourModuleProgram::VERSION);
        $availability = $this->access?->configuration() ?? ['fiveModuleOpen' => true, 'revision' => 0, 'periods' => []];
        // Internal explanations and audit metadata never enter the public response.
        $availability['periods'] = array_map(static fn(array $row): array => array_diff_key($row, ['note' => true]), $availability['periods']);
        unset($availability['updatedAt']);
        $bookProgramConfig['availability'] = $availability;
        $bookProgramConfig['fourModuleConfig']['availability'] = $availability;
        $bookProgramConfig['fiveModuleOpen'] = $availability['fiveModuleOpen'];
        $bookProgramConfig['programs']['ochtend']['description'][0] = 'Beschikbaar op de opengestelde ochtenden in de kalender.';
        $bookProgramConfig['fourModuleConfig']['programs']['ochtend']['description'][0] = $bookProgramConfig['programs']['ochtend']['description'][0];
        $this->response
        ->header('Cache-Control', 'no-store')
        ->json([
            'ok'    => true,
            'data'  => $bookProgramConfig
        ])
        ->send();

        } catch(\Throwable $e) {
            error_log(__METHOD__ . ' : ' . $e->getMessage());

            $this->response
                ->serverError(
                    'Validatie regels kunnen niet worden verzonden',
                    500,
                    false
                )
                ->send();
        }

    }
}

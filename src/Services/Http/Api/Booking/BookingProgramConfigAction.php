<?php
declare(strict_types=1);
namespace GeoFort\Services\Http\Api\Booking;

use GeoFort\Booking\BookingProgramConfig;
use GeoFort\Services\Http\Response\JsonResponse;

final class BookingProgramConfigAction
{
    public function __construct(
        private readonly JsonResponse $response,
    ) {}

    public function send()
    {
        try {
        $bookProgramConfig = BookingProgramConfig::forFrontend();
        $this->response
        ->json([
            'ok'    => true,
            'data'  => $bookProgramConfig
        ])
        ->send();

        } catch(Throwable $e) {
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
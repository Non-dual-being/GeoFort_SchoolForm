<?php
namespace GeoFort\Services\Http\Api\Booking;

use GeoFort\Booking\BookingPolicy;
use GeoFort\Services\Http\Response\JsonResponse;

final class BookingPolicyAction 
{
    public function __construct(
        private readonly JsonResponse $response,
    )
    {}

    public function send(): void {
        try {
            $bookingPolicy = BookingPolicy::getBookingPolicyForFrontend();
            $this->response
            ->json([
                    'ok' => true,
                    'data' => $bookingPolicy
                ])
            ->send();

        } catch (Throwable $e) {
            error_log(__METHOD__ . ' : ' . $e->getMessage());

            $this->response
                ->serverError(
                    'BookingPolicy regels kunnen niet worden verzonden',
                    500,
                    false
                )
                ->send();
        }

    }
}
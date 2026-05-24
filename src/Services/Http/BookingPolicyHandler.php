<?php
namespace GeoFort\Services\Http;
use GeoFort\Booking\BookingPolicy;

final class BookingPolicyHandler 
{
    public function __construct(
        private readonly JsonResponse $response,
    )
    {}

    public function handle(): void {
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
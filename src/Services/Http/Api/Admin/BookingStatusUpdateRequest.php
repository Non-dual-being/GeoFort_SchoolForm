<?php
declare(strict_types=1);

namespace GeoFort\Services\Http\Api\Admin;

use GeoFort\Booking\BookingPolicy;
use JsonException;

final readonly class BookingStatusUpdateRequest
{
    public function __construct(
        public int $bookingId,
        public string $expectedCurrentStatus,
        public string $targetStatus,
    ) {}

    public static function fromJson(string $json): self
    {
        try {
            $payload = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new BookingStatusUpdateRequestException('MALFORMED_JSON');
        }

        if (!is_array($payload) || array_is_list($payload)) {
            throw new BookingStatusUpdateRequestException('INVALID_REQUEST');
        }
        $expectedKeys = ['bookingId', 'expectedCurrentStatus', 'targetStatus'];
        if (array_diff(array_keys($payload), $expectedKeys) !== []) {
            throw new BookingStatusUpdateRequestException('INVALID_REQUEST');
        }
        foreach ($expectedKeys as $key) {
            if (!array_key_exists($key, $payload)) {
                throw new BookingStatusUpdateRequestException('INVALID_REQUEST');
            }
        }
        if (!is_int($payload['bookingId']) || $payload['bookingId'] <= 0) {
            throw new BookingStatusUpdateRequestException('INVALID_REQUEST');
        }
        foreach (['expectedCurrentStatus', 'targetStatus'] as $key) {
            if (!is_string($payload[$key]) || trim($payload[$key]) === '') {
                throw new BookingStatusUpdateRequestException('INVALID_REQUEST');
            }
        }
        if (!BookingPolicy::isAllowedStatus($payload['expectedCurrentStatus'])) {
            throw new BookingStatusUpdateRequestException('INVALID_CURRENT_STATUS');
        }
        if (!BookingPolicy::isAllowedStatus($payload['targetStatus'])) {
            throw new BookingStatusUpdateRequestException('INVALID_TARGET_STATUS');
        }

        return new self($payload['bookingId'], $payload['expectedCurrentStatus'], $payload['targetStatus']);
    }
}

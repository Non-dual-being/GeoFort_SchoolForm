<?php
declare(strict_types=1);

namespace GeoFort\Services\Http\Api\Admin;

use GeoFort\Booking\BookingPolicy;
use GeoFort\Booking\Status\BookingStatusMailMode;
use GeoFort\Booking\Rules\BookingRuleOverrideRequest;
use InvalidArgumentException;
use JsonException;

final readonly class BookingStatusUpdateRequest
{
    public function __construct(
        public int $bookingId,
        public string $expectedCurrentStatus,
        public string $targetStatus,
        public BookingStatusMailMode $mailMode,
        /** @var list<BookingRuleOverrideRequest> */
        public array $overrides,
    ) {}

    public static function fromJson(string $json): self
    {
        try {
            $payload = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
            $objectPayload = json_decode($json, false, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new BookingStatusUpdateRequestException('MALFORMED_JSON');
        }

        if (!is_array($payload) || array_is_list($payload)) {
            throw new BookingStatusUpdateRequestException('INVALID_REQUEST');
        }
        $expectedKeys = ['bookingId', 'expectedCurrentStatus', 'targetStatus', 'mailMode', 'overrides'];
        if (array_diff(array_keys($payload), $expectedKeys) !== []) {
            throw new BookingStatusUpdateRequestException('INVALID_REQUEST');
        }
        foreach (['bookingId', 'expectedCurrentStatus', 'targetStatus', 'mailMode'] as $key) {
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

        if (!is_string($payload['mailMode'])) {
            throw new BookingStatusUpdateRequestException('INVALID_MAIL_MODE');
        }
        $mailMode = BookingStatusMailMode::tryFrom($payload['mailMode']);
        if ($mailMode === null) {
            throw new BookingStatusUpdateRequestException('INVALID_MAIL_MODE');
        }

        $rawOverrides = $payload['overrides'] ?? [];
        if (is_object($objectPayload) && property_exists($objectPayload, 'overrides') && !is_array($objectPayload->overrides)) {
            throw new BookingStatusUpdateRequestException('INVALID_OVERRIDE_REQUEST');
        }
        if (!is_array($rawOverrides) || !array_is_list($rawOverrides)) throw new BookingStatusUpdateRequestException('INVALID_OVERRIDE_REQUEST');
        $overrides = [];
        $codes = [];
        foreach ($rawOverrides as $rawOverride) {
            if (!is_array($rawOverride) || array_is_list($rawOverride) || array_diff(array_keys($rawOverride), ['ruleCode', 'reason']) !== [] || count($rawOverride) !== 2) {
                throw new BookingStatusUpdateRequestException('INVALID_OVERRIDE_REQUEST');
            }
            if (!is_string($rawOverride['ruleCode'] ?? null) || trim($rawOverride['ruleCode']) === '') {
                throw new BookingStatusUpdateRequestException('INVALID_OVERRIDE_REQUEST');
            }
            $code = trim($rawOverride['ruleCode']);
            if (isset($codes[$code])) throw new BookingStatusUpdateRequestException('INVALID_OVERRIDE_REQUEST');
            if (!is_string($rawOverride['reason'] ?? null)) throw new BookingStatusUpdateRequestException('OVERRIDE_REASON_REQUIRED');
            try {
                $overrides[] = new BookingRuleOverrideRequest($code, $rawOverride['reason']);
            } catch (InvalidArgumentException) {
                throw new BookingStatusUpdateRequestException('OVERRIDE_REASON_REQUIRED');
            }
            $codes[$code] = true;
        }
        if ($payload['targetStatus'] !== BookingPolicy::STATUS_CONFIRMED && $overrides !== []) {
            throw new BookingStatusUpdateRequestException('OVERRIDE_NOT_ALLOWED');
        }

        return new self($payload['bookingId'], $payload['expectedCurrentStatus'], $payload['targetStatus'], $mailMode, $overrides);
    }
}

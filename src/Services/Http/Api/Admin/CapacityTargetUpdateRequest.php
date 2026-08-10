<?php
declare(strict_types=1);

namespace GeoFort\Services\Http\Api\Admin;

use JsonException;

final readonly class CapacityTargetUpdateRequest
{
    public function __construct(
        public string $effectiveDate,
        public mixed $studentsPerAvailableDay,
        public mixed $bookingsPerAvailableDay,
        public ?string $expectedUpdatedAt,
    ) {}

    public static function fromJson(string $json): self
    {
        try {
            $payload = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new CapacityTargetUpdateRequestException('INVALID_REQUEST');
        }
        if (!is_array($payload) || array_is_list($payload)) throw new CapacityTargetUpdateRequestException('INVALID_REQUEST');
        $expectedKeys = ['bookingsPerAvailableDay', 'effectiveDate', 'expectedUpdatedAt', 'studentsPerAvailableDay'];
        $actualKeys = array_keys($payload);
        sort($actualKeys);
        if ($actualKeys !== $expectedKeys
            || !is_string($payload['effectiveDate'])
            || (!is_string($payload['expectedUpdatedAt']) && $payload['expectedUpdatedAt'] !== null)
            || (is_string($payload['expectedUpdatedAt']) && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}(?:\.\d{1,6})?$/', $payload['expectedUpdatedAt']) !== 1)) {
            throw new CapacityTargetUpdateRequestException('INVALID_REQUEST');
        }
        return new self(
            $payload['effectiveDate'],
            $payload['studentsPerAvailableDay'],
            $payload['bookingsPerAvailableDay'],
            $payload['expectedUpdatedAt'],
        );
    }
}

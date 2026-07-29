<?php
declare(strict_types=1);

namespace GeoFort\Services\Http\Api\Admin;

use GeoFort\Dashboard\Calendar\CalendarDateManagementPolicy;
use JsonException;

final readonly class CalendarDateManagementRequest
{
    public function __construct(
        public string $startDate,
        public string $endDate,
        public string $previewFingerprint,
        public string $activeBookingsFingerprint,
        public string $action,
        public ?string $reason,
        public bool $confirmed,
        public bool $existingBookingsAccepted,
    ) {}

    public static function fromJson(string $json): self
    {
        $payload = self::decode($json);
        self::exactKeys($payload, ['expected', 'proposed']);
        $expected = self::object($payload['expected'] ?? null);
        self::exactKeys($expected, ['startDate', 'endDate', 'previewFingerprint', 'activeBookingsFingerprint']);
        $proposed = self::object($payload['proposed'] ?? null);
        self::exactKeys($proposed, ['action', 'reason', 'confirmed', 'existingBookingsAccepted']);

        foreach (['startDate', 'endDate', 'previewFingerprint', 'activeBookingsFingerprint'] as $field) {
            if (!is_string($expected[$field])) throw new CalendarDateManagementRequestException('INVALID_REQUEST');
        }
        if (!is_string($proposed['action'])) {
            throw new CalendarDateManagementRequestException('INVALID_REQUEST');
        }
        if (!in_array($proposed['action'], CalendarDateManagementPolicy::ACTIONS, true)) {
            throw new CalendarDateManagementRequestException('INVALID_CALENDAR_DATE_ACTION');
        }
        if ((!is_string($proposed['reason']) && $proposed['reason'] !== null)
            || !is_bool($proposed['confirmed'])
            || !is_bool($proposed['existingBookingsAccepted'])) {
            throw new CalendarDateManagementRequestException('INVALID_REQUEST');
        }
        if (preg_match('/^[a-f0-9]{64}$/', $expected['previewFingerprint']) !== 1) {
            throw new CalendarDateManagementRequestException('INVALID_REQUEST');
        }
        if (preg_match('/^[a-f0-9]{64}$/', $expected['activeBookingsFingerprint']) !== 1) {
            throw new CalendarDateManagementRequestException('INVALID_REQUEST');
        }
        return new self(
            $expected['startDate'],
            $expected['endDate'],
            $expected['previewFingerprint'],
            $expected['activeBookingsFingerprint'],
            $proposed['action'],
            $proposed['reason'],
            $proposed['confirmed'],
            $proposed['existingBookingsAccepted'],
        );
    }

    /** @return array<string, mixed> */
    private static function decode(string $json): array
    {
        try {
            $payload = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new CalendarDateManagementRequestException('INVALID_REQUEST');
        }
        return self::object($payload);
    }

    /** @return array<string, mixed> */
    private static function object(mixed $value): array
    {
        if (!is_array($value) || array_is_list($value)) throw new CalendarDateManagementRequestException('INVALID_REQUEST');
        return $value;
    }

    /** @param array<string, mixed> $value @param list<string> $keys */
    private static function exactKeys(array $value, array $keys): void
    {
        $actual = array_keys($value);
        sort($actual);
        sort($keys);
        if ($actual !== $keys) throw new CalendarDateManagementRequestException('INVALID_REQUEST');
    }
}

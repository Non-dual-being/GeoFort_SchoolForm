<?php
declare(strict_types=1);

namespace GeoFort\Services\Http\Api\Admin;

use GeoFort\Dashboard\Calendar\CalendarDateManagementPolicy;
use JsonException;

final readonly class CalendarDateManagementPreviewRequest
{
    public function __construct(
        public string $startDate,
        public string $endDate,
        public string $action,
        public ?string $disabledType,
    ) {}

    public static function fromJson(string $json): self
    {
        try {
            $payload = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new CalendarDateManagementRequestException('INVALID_REQUEST');
        }
        if (!is_array($payload) || array_is_list($payload)) throw new CalendarDateManagementRequestException('INVALID_REQUEST');
        $keys = array_keys($payload);
        sort($keys);
        if ($keys !== ['action', 'disabledType', 'endDate', 'startDate']) throw new CalendarDateManagementRequestException('INVALID_REQUEST');
        if (!is_string($payload['startDate']) || !is_string($payload['endDate']) || !is_string($payload['action'])) {
            throw new CalendarDateManagementRequestException('INVALID_REQUEST');
        }
        if (!in_array($payload['action'], CalendarDateManagementPolicy::ACTIONS, true)) {
            throw new CalendarDateManagementRequestException('INVALID_CALENDAR_DATE_ACTION');
        }
        if ((!is_string($payload['disabledType']) && $payload['disabledType'] !== null)
            || (str_starts_with($payload['action'], 'block_')
                && !in_array($payload['disabledType'], CalendarDateManagementPolicy::MANAGEABLE_TYPES, true))
            || (str_starts_with($payload['action'], 'release_') && $payload['disabledType'] !== null)) {
            throw new CalendarDateManagementRequestException('INVALID_DISABLED_DATE_TYPE');
        }
        return new self($payload['startDate'], $payload['endDate'], $payload['action'], $payload['disabledType']);
    }
}

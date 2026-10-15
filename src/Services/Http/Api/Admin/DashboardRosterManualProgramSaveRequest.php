<?php

declare(strict_types=1);

namespace GeoFort\Services\Http\Api\Admin;

use GeoFort\Services\Dashboard\Roster\RosterManualTimeline;
use GeoFort\Services\Dashboard\Roster\RosterPlanException;
use JsonException;
use stdClass;

final readonly class DashboardRosterManualProgramSaveRequest
{
    /** @param list<mixed>|null $moduleKeys */
    private function __construct(
        public int $planId,
        public int $expectedRevision,
        public ?array $moduleKeys,
        public ?RosterManualTimeline $timeline,
        public ?int $groupCount,
    ) {}

    public static function fromJson(string $json): self
    {
        try {
            // Behoud het onderscheid tussen JSON-objecten en arrays, ook bij numerieke keys.
            $payload = json_decode($json, false, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new RosterPlanException('INVALID_REQUEST', 422, 'Ongeldige roosterwijziging.');
        }

        if (
            !$payload instanceof stdClass
            || !is_int($payload->planId ?? null)
            || $payload->planId <= 0
            || !is_int($payload->expectedRevision ?? null)
            || $payload->expectedRevision <= 0
            || (!property_exists($payload, 'moduleKeys') && !property_exists($payload, 'rounds'))
        ) {
            throw new RosterPlanException('INVALID_REQUEST', 422, 'Ongeldige roosterwijziging.');
        }

        if (property_exists($payload, 'groupCount') && (!is_int($payload->groupCount) || $payload->groupCount < 1 || $payload->groupCount > 10)) {
            throw new RosterPlanException('INVALID_GROUP_COUNT', 422, 'Kies een geheel aantal groepen van 1 tot en met 10.');
        }

        $moduleKeys = null;
        if (property_exists($payload, 'moduleKeys')) {
            if (!is_array($payload->moduleKeys) || !array_is_list($payload->moduleKeys)) {
                throw new RosterPlanException('INVALID_REQUEST', 422, 'Gebruik een lijst met modulekeys.');
            }
            $moduleKeys = $payload->moduleKeys;
        }

        return new self(
            $payload->planId,
            $payload->expectedRevision,
            $moduleKeys,
            property_exists($payload, 'rounds') ? RosterManualTimeline::fromArray($payload->rounds) : null,
            $payload->groupCount ?? null,
        );
    }
}

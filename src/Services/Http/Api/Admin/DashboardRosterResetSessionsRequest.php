<?php

declare(strict_types=1);

namespace GeoFort\Services\Http\Api\Admin;

use GeoFort\Services\Dashboard\Roster\RosterPlanException;

final readonly class DashboardRosterResetSessionsRequest
{
    public function __construct(public int $planId, public int $expectedRevision) {}

    public static function fromArray(mixed $payload): self
    {
        if (!is_array($payload) || array_is_list($payload)
            || array_diff(array_keys($payload), ['planId', 'expectedRevision']) !== []
            || !is_int($payload['planId'] ?? null) || $payload['planId'] <= 0
            || !is_int($payload['expectedRevision'] ?? null) || $payload['expectedRevision'] <= 0) {
            throw new RosterPlanException('INVALID_REQUEST', 422, 'Geef uitsluitend een geldig rooster-ID en de verwachte revisie op.');
        }

        return new self($payload['planId'], $payload['expectedRevision']);
    }
}


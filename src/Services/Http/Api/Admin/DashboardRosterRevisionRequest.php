<?php

declare(strict_types=1);

namespace GeoFort\Services\Http\Api\Admin;

use GeoFort\Services\Dashboard\Roster\RosterPlanException;

final readonly class DashboardRosterRevisionRequest
{
    public function __construct(public int $planId, public int $expectedRevision) {}

    public static function fromArray(mixed $payload): self
    {
        if (!is_array($payload) || !is_int($payload['planId'] ?? null) || $payload['planId'] <= 0
            || !is_int($payload['expectedRevision'] ?? null) || $payload['expectedRevision'] <= 0) {
            throw new RosterPlanException('INVALID_REQUEST', 422, 'Een geldig rooster-ID en de verwachte revisie zijn verplicht.');
        }
        return new self($payload['planId'], $payload['expectedRevision']);
    }

    public static function fromQuery(array $query): self
    {
        foreach (['planId', 'expectedRevision'] as $key) {
            if (!isset($query[$key]) || !is_string($query[$key]) || preg_match('/^[1-9][0-9]*$/D', $query[$key]) !== 1
                || (string) (int) $query[$key] !== $query[$key]) throw new RosterPlanException('INVALID_REQUEST', 422, 'Ongeldig rooster-ID of revisie.');
            $query[$key] = (int) $query[$key];
        }
        return self::fromArray($query);
    }
}

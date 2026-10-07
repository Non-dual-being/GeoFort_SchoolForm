<?php

declare(strict_types=1);

namespace GeoFort\Services\Http\Api\Admin;

use GeoFort\Services\Dashboard\Roster\RosterPlanException;

/** Explicit supervision is scoped to one existing session/group relationship. */
final readonly class DashboardRosterSessionSaveRequest
{
    public function __construct(public ?string $supervisionKind) {}

    public static function fromArray(array $payload): self
    {
        $kind = $payload['supervisionKind'] ?? null;
        if (array_key_exists('supervisionKind', $payload) && (!is_string($kind) || !in_array($kind, ['staff', 'school', 'open'], true)
            || !is_int($payload['staffGroupId'] ?? null) || $payload['staffGroupId'] <= 0
            || !is_int($payload['sessionId'] ?? null) || $payload['sessionId'] <= 0
            || ($kind !== 'staff' && ($payload['staffIds'] ?? []) !== []))) {
            throw new RosterPlanException('INVALID_SUPERVISION', 422, 'Kies een geldige begeleidingsvorm voor één groep in een bestaande sessie.');
        }
        return new self($kind);
    }
}

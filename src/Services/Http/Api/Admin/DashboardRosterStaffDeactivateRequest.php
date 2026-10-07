<?php

declare(strict_types=1);

namespace GeoFort\Services\Http\Api\Admin;

use GeoFort\Services\Dashboard\Roster\RosterPlanException;

final readonly class DashboardRosterStaffDeactivateRequest
{
    private function __construct(public int $staffId, public int $expectedVersion, public int $expectedCatalogRevision, public string $impactToken) {}

    public static function fromArray(mixed $input): self
    {
        $invalid = static fn (): RosterPlanException => new RosterPlanException('INVALID_STAFF', 422, 'Controleer medewerker, versies en impactbevestiging.');
        if (!is_array($input) || array_is_list($input) || array_diff(array_keys($input), ['staffId', 'expectedVersion', 'expectedCatalogRevision', 'impactToken']) !== []) throw $invalid();
        foreach (['staffId', 'expectedVersion', 'expectedCatalogRevision'] as $field) {
            if (!is_int($input[$field] ?? null) || $input[$field] < 1) throw $invalid();
        }
        if (!is_string($input['impactToken'] ?? null) || $input['impactToken'] === '' || strlen($input['impactToken']) > 100) throw $invalid();
        return new self($input['staffId'], $input['expectedVersion'], $input['expectedCatalogRevision'], $input['impactToken']);
    }
}

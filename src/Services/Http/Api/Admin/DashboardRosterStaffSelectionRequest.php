<?php

declare(strict_types=1);

namespace GeoFort\Services\Http\Api\Admin;

use GeoFort\Services\Dashboard\Roster\RosterPlanException;

final readonly class DashboardRosterStaffSelectionRequest
{
    private function __construct(public int $planId, public int $expectedRevision, public int $expectedCatalogRevision, public array $staffIds) {}

    public static function fromArray(mixed $input): self
    {
        $invalid = static fn (): RosterPlanException => new RosterPlanException('INVALID_REQUEST', 422, 'Controleer rooster, versies en dagselectie.');
        if (!is_array($input) || array_diff(array_keys($input), ['planId', 'expectedRevision', 'expectedCatalogRevision', 'staffIds']) !== []) throw $invalid();
        foreach (['planId', 'expectedRevision', 'expectedCatalogRevision'] as $key) if (!is_int($input[$key] ?? null) || $input[$key] < 1) throw $invalid();
        if (!is_array($input['staffIds'] ?? null) || !array_is_list($input['staffIds']) || count($input['staffIds']) > 200) throw $invalid();
        foreach ($input['staffIds'] as $id) if (!is_int($id) || $id < 1) throw $invalid();
        if (count(array_unique($input['staffIds'])) !== count($input['staffIds'])) throw $invalid();
        return new self($input['planId'], $input['expectedRevision'], $input['expectedCatalogRevision'], $input['staffIds']);
    }
}

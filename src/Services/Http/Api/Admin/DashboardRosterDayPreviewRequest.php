<?php

declare(strict_types=1);

namespace GeoFort\Services\Http\Api\Admin;

use GeoFort\Services\Dashboard\Roster\RosterPlanException;

final readonly class DashboardRosterDayPreviewRequest
{
    public function __construct(public array $expectedRevisions) {}

    public static function fromQuery(array $query): self
    {
        $ids = $query['planIds'] ?? null;
        $revisions = $query['revisions'] ?? null;
        if (!is_string($ids) || !is_string($revisions)
            || preg_match('/^[1-9][0-9]*(,[1-9][0-9]*)?$/D', $ids) !== 1
            || preg_match('/^[1-9][0-9]*(,[1-9][0-9]*)?$/D', $revisions) !== 1) self::invalid();
        $ids = explode(',', $ids);
        $revisions = explode(',', $revisions);
        if (count($ids) !== count($revisions) || count(array_unique($ids)) !== count($ids)) self::invalid();
        $result = [];
        foreach ($ids as $i => $id) {
            if ((string) (int) $id !== $id || (string) (int) $revisions[$i] !== $revisions[$i]) self::invalid();
            $result[(int) $id] = (int) $revisions[$i];
        }
        return new self($result);
    }

    private static function invalid(): never
    {
        throw new RosterPlanException('INVALID_DAY_PLANS', 422, 'Kies één of twee verschillende rooster-ID’s met hun huidige revisies.');
    }
}

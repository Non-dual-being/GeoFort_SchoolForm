<?php

declare(strict_types=1);

namespace GeoFort\Services\Dashboard\Roster;

final class RosterGroupSupervision
{
    /** Legacy absence never confirms school supervision. */
    public static function kind(?string $storedKind, array $staff): string
    {
        return $storedKind ?? ($staff === [] ? 'open' : 'staff');
    }

    /** @return list<int> */
    public static function staffIds(?string $kind, array $groupIds, array $sessionIds): array
    {
        if ($kind === 'school' || $kind === 'open') return [];
        return $kind === 'staff' ? $groupIds : ($groupIds !== [] ? $groupIds : $sessionIds);
    }
}

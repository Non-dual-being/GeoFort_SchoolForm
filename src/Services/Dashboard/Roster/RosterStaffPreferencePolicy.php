<?php

declare(strict_types=1);

namespace GeoFort\Services\Dashboard\Roster;

final class RosterStaffPreferencePolicy
{
    /** Compare the complete catalog, including retained legacy skills, under the catalog lock. */
    public static function validate(?array $before, array $after): void
    {
        $map = static function (array $preferences): array {
            $result = array_column($preferences, 'rank', 'moduleKey');
            ksort($result, SORT_STRING);
            return $result;
        };
        $ranks = $map($after);
        if ($before !== null && $map($before) === $ranks) return;
        foreach ($ranks as $rank) {
            if (!is_int($rank) || $rank < 1 || $rank > 255) {
                throw new RosterPlanException('INVALID_STAFF_PREFERENCE_RANK', 422, 'Gebruik gehele voorkeursrangen van 1 tot en met 255.');
            }
        }
        if (count(array_unique($ranks)) !== count($ranks)) {
            throw new RosterPlanException('DUPLICATE_STAFF_PREFERENCE_RANK', 422, 'Geef iedere geselecteerde module een unieke voorkeursrang, ook bij legacyvaardigheden.');
        }
    }
}

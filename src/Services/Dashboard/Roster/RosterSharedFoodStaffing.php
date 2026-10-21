<?php
declare(strict_types=1);
namespace GeoFort\Services\Dashboard\Roster;

/** One food workshop team for two groups; pair IDs must come from stored links. */
final class RosterSharedFoodStaffing
{
    public static function compatible(array $a, array $b): bool
    {
        if (($a['moduleKey'] ?? '') !== 'Voedsel-Innovatie' || ($b['moduleKey'] ?? '') !== 'Voedsel-Innovatie'
            || ($a['startTime'] ?? null) !== ($b['startTime'] ?? null) || ($a['endTime'] ?? null) !== ($b['endTime'] ?? null)
            || (int)($a['groupCount'] ?? 0) < 1 || (int)($b['groupCount'] ?? 0) < 1
            || (int)$a['groupCount'] + (int)$b['groupCount'] > 2) return false;
        $first=(int)($a['planId'] ?? 0); $second=(int)($b['planId'] ?? 0);
        if ($first <= 0 || $second <= 0) return false;
        if ($first === $second) return array_intersect($a['groupIds'] ?? [], $b['groupIds'] ?? []) === [];
        return (int)($a['pairId'] ?? 0) > 0 && (int)$a['pairId'] === (int)($b['pairId'] ?? 0);
    }

    public static function guides(array $row): array
    {
        return array_values(array_diff(array_unique(array_map('intval',$row['staffIds'] ?? [])), [(int)($row['cookStaffId'] ?? 0)]));
    }

    public static function allows(array $a, array $b, int $staffId): bool
    {
        if (!self::compatible($a,$b)) return false;
        $cookA=(int)($a['cookStaffId'] ?? 0) === $staffId;
        $cookB=(int)($b['cookStaffId'] ?? 0) === $staffId;
        if ($cookA || $cookB) return $cookA && $cookB;
        return in_array($staffId,self::guides($a),true) && in_array($staffId,self::guides($b),true);
    }
}

<?php
declare(strict_types=1);
namespace GeoFort\Services\Dashboard\Roster;

/** One guide may cover two KE groups of one school in the exact same time slot.
 * Storage as one session or separate session rows must not change this rule.
 * Pass ALL overlapping assignments for this employee, so a third group cannot slip through pairwise checks.
 */
final class RosterSharedKeStaffing
{
    public static function allows(array $rows, int $staffId): bool
    {
        if (count($rows)<2 || $staffId<1) return false;
        $first=reset($rows);$plan=(int)($first['planId']??0);
        $start=$first['startTime']??'';$end=$first['endTime']??'';
        if ($plan<1 || $start==='' || $end<=$start) return false;
        $groups=[];
        foreach ($rows as $row) {
            if (($row['moduleKey']??'')!=='Klimaat-Experience' || (int)($row['planId']??0)!==$plan
                || ($row['startTime']??null)!==$start || ($row['endTime']??null)!==$end
                || ($row['cook']??false) || ($row['cookStaffId']??null)!==null
                || !in_array($staffId,$row['staffIds']??[],true)
                || (int)($row['groupCount']??0)<1 || (int)$row['groupCount']>2) return false;
            $own=array_map('intval',$row['groupIds']??[]);
            if ($own===[] || min($own)<1 || count($own)!==count(array_unique($own)) || array_intersect($groups,$own)!==[]) return false;
            $groups=[...$groups,...$own];
            if (count($groups)>2) return false;
        }
        return count($groups)===2;
    }
}

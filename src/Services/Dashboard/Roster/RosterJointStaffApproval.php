<?php
declare(strict_types=1);
namespace GeoFort\Services\Dashboard\Roster;

/** An approval covers every assignment, including empty time, across the complete pair. */
final class RosterJointStaffApproval
{
    public static function signature(array $state, int $id): string
    {
        $rows=[];
        foreach ($state as $p) foreach ($p['sessions'] as $s) {
            if ($s['cook']===$id) $rows[]=[$p['id'],$s['id'],0,$s['start'],$s['end'],'cook'];
            foreach ($s['groups'] as $g) if (in_array($id,$g['staffIds'],true)) $rows[]=[$p['id'],$s['id'],$g['id'],$s['start'],$s['end'],'guide'];
        }
        usort($rows,fn($a,$b)=>[$a[0],$a[1],$a[2]]<=>[$b[0],$b[1],$b[2]]);
        return json_encode($rows,JSON_THROW_ON_ERROR);
    }

    public static function check(array $state, array $options, array $saved=[]): void
    {
        $approved=$options['approvedStaff']??[]; $released=$options['releasedStaff']??[];
        foreach ($saved['approvedStaff']??[] as $old) {
            if (in_array($old['id'],$released,true)) continue;
            if (!in_array($old,$approved,true)) self::locked();
        }
        $seen=[];
        foreach ($approved as $entry) {
            $id=$entry['id']??0;
            if (!is_int($id) || $id<1 || isset($seen[$id]) || !is_string($entry['schedule']??null) || $entry['schedule']==='[]' || self::signature($state,$id)!==$entry['schedule']) self::locked();
            $seen[$id]=true;
        }
    }

    private static function locked(): never
    {
        throw new RosterPlanException('STAFF_SCHEDULE_APPROVED',422,'Het rooster van een goedgekeurde medewerker staat vast voor beide scholen. Geef de medewerker eerst vrij via Medewerkers goedkeuren.');
    }
}

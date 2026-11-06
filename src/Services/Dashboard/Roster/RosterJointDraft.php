<?php
declare(strict_types=1);
namespace GeoFort\Services\Dashboard\Roster;

/** The editable contract contains identities, times and assignments only, never trusted SQL rows. */
final class RosterJointDraft
{
    public const OPEN_ERRORS = ['OPEN_SUPERVISION','GROUP_GUIDES_REQUIRED','GUIDES_REQUIRED','COOK_REQUIRED','FOUR_MODULE_KE_SUPERVISION_REQUIRED'];

    public static function state(array $data): array
    {
        $plans=[];
        foreach ($data as $pid=>$d) {
            $sessions=[];
            foreach ($d['sessions'] as $s) {
                $sid=(int)$s['id']; $groups=[];
                $fallback=array_map('intval',array_column(array_values(array_filter($d['sessionStaff'],fn($r)=>(int)$r['roster_session_id']===$sid)),'staff_id'));
                foreach ($d['links'] as $l) if ((int)$l['roster_session_id']===$sid) {
                    $gid=(int)$l['roster_group_id'];
                    $specific=array_map('intval',array_column(array_values(array_filter($d['groupStaff'],fn($r)=>(int)$r['roster_session_id']===$sid && (int)$r['roster_group_id']===$gid)),'staff_id'));
                    $people=RosterGroupSupervision::staffIds($l['supervision_kind']??null,$specific,$fallback); sort($people);
                    $groups[]=['id'=>$gid,'kind'=>($l['supervision_kind']??null)==='school'?'school':($people===[]?'open':'staff'),'staffIds'=>$people];
                }
                usort($groups,fn($a,$b)=>$a['id']<=>$b['id']);
                $sessions[]=['id'=>$sid,'start'=>substr($s['start_time'],0,5),'end'=>substr($s['end_time'],0,5),
                    'cook'=>$s['module_key']==='Voedsel-Innovatie'?(int)($s['cook_staff_id']??$d['settings']['cook_staff_id']??0):0,'groups'=>$groups];
            }
            usort($sessions,fn($a,$b)=>$a['id']<=>$b['id']);
            $plans[]=['id'=>(int)$pid,'sessions'=>$sessions];
        }
        usort($plans,fn($a,$b)=>$a['id']<=>$b['id']);
        return $plans;
    }

    public static function patch(array $data, array $state, array $options): array
    {
        $original=self::state($data);
        if (count($state)!==count($original)) self::invalid();
        $seen=[];
        foreach ($state as $p) {
            $pid=$p['id']??0;
            if (!is_int($pid) || !isset($data[$pid]) || isset($seen[$pid]) || !is_array($p['sessions']??null)) self::invalid();
            $seen[$pid]=true;
            $before=array_values(array_filter($original,fn($r)=>$r['id']===$pid))[0];
            if ($data[$pid]['plan']['status']==='published') {
                if ($p!==$before) throw new RosterPlanException('PLAN_NOT_EDITABLE',409,'Een definitief rooster staat vast. Heropen het bewust voordat je het wijzigt.');
                continue;
            }
            $d=&$data[$pid]; $stored=array_column($d['sessions'],null,'id');
            if (count($p['sessions'])!==count($stored)) self::invalid();
            $sessionSeen=[]; $links=[]; $ss=[]; $gs=[];
            foreach ($p['sessions'] as $s) {
                $sid=$s['id']??0;
                if (!is_int($sid) || !isset($stored[$sid]) || isset($sessionSeen[$sid])) self::invalid();
                $sessionSeen[$sid]=true;
                foreach (['start','end'] as $key) if (!is_string($s[$key]??null) || preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/D',$s[$key])!==1) self::invalid();
                $old=$stored[$sid];
                if ($s['end']<=$s['start'] || self::minutes($s['end'])-self::minutes($s['start'])!==self::minutes($old['end_time'])-self::minutes($old['start_time'])) self::invalid();
                $moved=$s['start']!==substr($old['start_time'],0,5) || $s['end']!==substr($old['end_time'],0,5);
                if ($moved && (!(($options['shiftLessons']??false)===true || ($options['moduleSwaps']??false)===true) || in_array("$pid:$sid",$options['timePins']??[],true))) throw new RosterPlanException('LESSON_LOCKED',422,'Deze lestijd staat vast.');
                if ($moved && $old['module_key']==='Voedsel-Innovatie' && !($d['lessonPreferences']['allowLateVi']??false)) throw new RosterPlanException('VI_LOCKED',422,'Geef Voedsel Innovatie eerst vrij in de individuele wizard.');
                $expected=array_values(array_filter($d['links'],fn($l)=>(int)$l['roster_session_id']===$sid));
                if (!is_array($s['groups']??null) || count($s['groups'])!==count($expected) || !is_int($s['cook']??null) || $s['cook']<0 || ($old['module_key']!=='Voedsel-Innovatie' && $s['cook']!==0)) self::invalid();
                $groupSeen=[];
                foreach ($s['groups'] as $g) {
                    $gid=$g['id']??0;
                    $link=array_values(array_filter($expected,fn($l)=>(int)$l['roster_group_id']===$gid))[0]??null;
                    if (!is_int($gid) || $link===null || isset($groupSeen[$gid]) || !in_array($g['kind']??null,['staff','open','school'],true) || !is_array($g['staffIds']??null) || count($g['staffIds'])>10) self::invalid();
                    $groupSeen[$gid]=true; $people=$g['staffIds'];
                    foreach ($people as $id) if (!is_int($id) || $id<1) self::invalid();
                    if (count($people)!==count(array_unique($people)) || (($g['kind']==='staff')!==($people!==[]))) self::invalid();
                    $oldSession=array_values(array_filter($before['sessions'],fn($row)=>$row['id']===$sid))[0];
                    $oldGroup=array_values(array_filter($oldSession['groups'],fn($row)=>$row['id']===$gid))[0];
                    if (in_array("$pid:$sid:$gid",$options['staffPins']??[],true) && $g!==$oldGroup) throw new RosterPlanException('STAFF_LOCKED',422,'Deze docenttoewijzing staat vast.');
                    $links[]=[...$link,'supervision_kind'=>$g['kind']];
                    foreach ($people as $id) {
                        $ss["$sid:$id"]=['roster_session_id'=>$sid,'staff_id'=>$id,'assignment_source'=>'manual'];
                        $gs[]=['roster_session_id'=>$sid,'roster_group_id'=>$gid,'staff_id'=>$id,'session_plan_id'=>$pid,'group_plan_id'=>$pid,'assignment_source'=>'manual'];
                    }
                }
                if (in_array("$pid:$sid:cook",$options['staffPins']??[],true) && $s['cook']!==(int)($old['cook_staff_id']??$d['settings']['cook_staff_id']??0)) throw new RosterPlanException('STAFF_LOCKED',422,'Deze koktoewijzing staat vast.');
                foreach ($d['sessions'] as &$row) if ((int)$row['id']===$sid) { $row['start_time']=$s['start']; $row['end_time']=$s['end']; if ($old['module_key']==='Voedsel-Innovatie') $row['cook_staff_id']=$s['cook']; }
                unset($row);
            }
            $d['links']=$links; $d['sessionStaff']=array_values($ss); $d['groupStaff']=$gs;
            unset($d);
        }
        if (($options['moduleSwaps']??false)===true && ($options['shiftLessons']??false)!==true) {
            // A module swap may use existing slots only. Each group's complete time grid stays identical.
            $slots=static function(array $plans): array {
                $result=[];
                foreach ($plans as $p) foreach ($p['sessions'] as $s) foreach ($s['groups'] as $g) $result[$p['id'].':'.$g['id']][]=$s['start'].'-'.$s['end'];
                foreach ($result as &$times) sort($times); unset($times); ksort($result); return $result;
            };
            if ($slots($original)!==$slots(self::state($data))) throw new RosterPlanException('LESSON_SLOTS_LOCKED',422,'Wissel modules binnen de bestaande lesblokken. Lestijden wijzigen kan alleen in de individuele wizard.');
        }
        RosterJointStaffApproval::check(self::state($data),$options);
        // A shared day selection has one authoritative intersection of availability.
        $availability=[];
        foreach ($data as $d) foreach ($d['selectedStaff'] as $row) {
            $id=(int)$row['staff_id']; $a=$row['availability_start']??null; $b=$row['availability_end']??null;
            $prior=$availability[$id]??[null,null];
            $availability[$id]=[$a===null?$prior[0]:max($prior[0]??$a,$a),$b===null?$prior[1]:min($prior[1]??$b,$b)];
        }
        foreach ($options['additions']??[] as $row) {
            if (!is_int($row['id']??null) || $row['id']<1 || ($row['confirmed']??false)!==true) self::invalid();
            foreach (['start','end'] as $key) if (!is_string($row[$key]??null) || preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/D',$row[$key])!==1) self::invalid();
            if ($row['end']<=$row['start']) self::invalid();
            if (!isset($availability[$row['id']])) $availability[$row['id']]=[$row['start'],$row['end']];
        }
        foreach ($data as &$d) if ($d['plan']['status']==='concept') {
            $d['selectedStaff']=[];
            foreach ($availability as $id=>[$a,$b]) $d['selectedStaff'][]=['staff_id'=>$id,'availability_start'=>$a,'availability_end'=>$b];
        }
        unset($d);
        return $data;
    }

    public static function tasks(array $data, RosterValidationService $validator, ?int $pairId): array
    {
        $tasks=[]; $config=new RosterPlanningConfig();
        foreach ($data as $pid=>$d) {
            $context=$d['plan']['planning_mode']==='custom'?$d['context']:$d['booking'];
            $program=$context['program_key']??$context['programma']??''; $version=$context['program_version']??null;
            $availability=[]; foreach ($d['selectedStaff'] as $r) $availability[(int)$r['staff_id']]=[isset($r['availability_start'])?substr($r['availability_start'],0,5):null,isset($r['availability_end'])?substr($r['availability_end'],0,5):null];
            $breaks=array_values(array_filter($validator->blocks($d,$program,count(array_unique(array_column($d['sessions'],'module_key')))),fn($b)=>$b['type']!=='round'));
            $state=self::state([$pid=>$d])[0]; $stored=array_column($d['sessions'],null,'id');
            foreach ($state['sessions'] as $s) {
                $m=$stored[$s['id']]['module_key'];
                $base=['planId'=>$pid,'pairId'=>$pairId,'sessionId'=>$s['id'],'moduleKey'=>$m,'startTime'=>$s['start'],'endTime'=>$s['end'],'groupCount'=>count($s['groups']),
                    'availability'=>$availability,'breaks'=>$breaks,'morning'=>$program==='ochtend','requiresGeoFortForFinal'=>$config->requiresGeoFortForFinal($m,$version),'locked'=>$d['plan']['status']==='published'];
                $byPerson=[];
                foreach ($s['groups'] as $g) {
                    // Preserve voluntary school supervision; required four-module KE can be repaired.
                    if ($g['kind']==='school' && !$base['requiresGeoFortForFinal']) continue;
                    foreach ($g['staffIds']?:[0] as $id) {
                        $key=$id>0?(string)$id:($m==='Voedsel-Innovatie'?'open:vi':('open:'.$g['id']));
                        if (!isset($byPerson[$key])) $byPerson[$key]=[...$base,'staffId'=>$id,'cook'=>false,'schoolAllowed'=>$program!=='ochtend' && $config->schoolSupervisionAllowed($m),'groupIds'=>[],'wasSchool'=>$g['kind']==='school'];
                        $byPerson[$key]['groupIds'][]=$g['id'];
                    }
                }
                foreach ($byPerson as $task) $tasks[]=$task;
                if ($m==='Voedsel-Innovatie') $tasks[]=[...$base,'staffId'=>$s['cook'],'cook'=>true,'schoolAllowed'=>false,'groupIds'=>array_column($s['groups'],'id')];
            }
        }
        return $tasks;
    }

    public static function assign(array $state, array $tasks, array $assignment): array
    {
        foreach ($state as &$p) foreach ($p['sessions'] as &$s) {
            $own=array_filter($tasks,fn($t)=>$t['planId']===$p['id'] && $t['sessionId']===$s['id']);
            foreach ($s['groups'] as &$g) {
                $matching=array_filter($own,fn($t)=>!$t['cook'] && in_array($g['id'],$t['groupIds'],true));
                if ($matching===[]) continue;
                $people=[]; $school=true;
                foreach ($matching as $i=>$t) { if ($assignment[$i]>0) $people[]=$assignment[$i]; $school=$school && $t['schoolAllowed']; }
                $g['staffIds']=array_values(array_unique($people)); sort($g['staffIds']); $g['kind']=$people!==[]?'staff':($school?'school':'open');
            }
            unset($g);
            foreach ($own as $i=>$t) if ($t['cook']) $s['cook']=$assignment[$i];
        }
        unset($p,$s);
        return $state;
    }
    public static function minutes(string $time): int { return (int)substr($time,0,2)*60+(int)substr($time,3,2); }
    private static function invalid(): never { throw new RosterPlanException('INVALID_DRAFT',422,'De bewerkversie bevat ongeldige lessen of toewijzingen. Laad de opgeslagen roosters opnieuw.'); }
}

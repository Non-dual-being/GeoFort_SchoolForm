<?php
declare(strict_types=1);
namespace GeoFort\Services\Dashboard\Roster;

use GeoFort\Services\Sql\{RosterPlanSqlRepository,RosterPairSqlRepository,RosterStaffSqlRepository,RosterSessionCookSqlRepository,RosterValidationSqlRepository,RosterSnapshotSqlRepository,BookingDaySettingsSqlRepository};
use PDO;
use Throwable;

final readonly class RosterJointOptimizationService
{
    public function __construct(private PDO $pdo) {}

    public function run(array $revisions, ?string $acceptedSignature, int $actor): array
    {
        if(count($revisions)!==2) throw new RosterPlanException('INVALID_PAIR',422,'Kies twee gekoppelde schoolroosters.');
        $this->pdo->beginTransaction();
        try {
            $catalogRevision=RosterStaffCatalogGuard::lock($this->pdo);
            $plans=new RosterPlanSqlRepository($this->pdo); $raw=new RosterValidationSqlRepository($this->pdo);
            $first=$plans->findById(array_key_first($revisions));
            if($first===null) throw new RosterPlanException('PLAN_NOT_FOUND',404,'Het rooster bestaat niet.');
            (new BookingDaySettingsSqlRepository($this->pdo))->lockDate($first['visit_date']);
            $ids=array_keys($revisions); sort($ids,SORT_NUMERIC);
            foreach($ids as $id) {
                $plan=$plans->lockPlan($id);
                if($plan===null || (int)$plan['revision']!==$revisions[$id]) throw new RosterPlanException('STALE_REVISION',409,'Een rooster is gewijzigd. Maak een nieuw optimalisatievoorstel.');
                if($plan['status']!=='concept') throw new RosterPlanException('PLAN_NOT_EDITABLE',409,'Optimaliseer alleen conceptroosters.');
            }
            $pair=(new RosterPairSqlRepository($this->pdo))->forPlan($ids[0]);
            $pairIds=$pair['planIds']??[]; sort($pairIds,SORT_NUMERIC);
            if($pair===null || $pairIds!==$ids) throw new RosterPlanException('PAIR_REQUIRED',422,'Optimalisatie is alleen beschikbaar voor een bestaande koppeling.');
            $validator=new RosterValidationService($raw,new RosterSnapshotSqlRepository($this->pdo),new RosterPlanningConfig(),new RosterGenerationTemplateProvider());
            $staff=new RosterStaffSqlRepository($this->pdo); $members=$staff->catalog();
            $data=[]; $tasks=[]; $availability=[];
            foreach($ids as $id) {
                $data[$id]=$raw->load($id);
                foreach($data[$id]['selectedStaff'] as $row) {
                    $sid=(int)$row['staff_id']; $a=$row['availability_start']??null; $b=$row['availability_end']??null;
                    $a=$a===null?null:substr($a,0,5); $b=$b===null?null:substr($b,0,5);
                    $old=$availability[$sid]??[null,null];
                    $availability[$sid]=[$a===null?$old[0]:max($old[0]??$a,substr($a,0,5)),$b===null?$old[1]:min($old[1]??$b,substr($b,0,5))];
                }
            }
            foreach($data as $id=>$d) {
                if($d['sessions']===[] || $d['sessionStaff']===[]) throw new RosterPlanException('JOINT_NOT_READY',422,'Maak eerst voor beide scholen een les- en docentenrooster.');
                $sessionStaff=[];
                $program=$d['plan']['planning_mode']==='custom' ? ($d['context']['program_key']??'') : ($d['booking']['programma']??'');
                $moduleCount=count(array_unique(array_column($d['sessions'],'module_key')));
                $breaks=array_values(array_filter($validator->blocks($d,$program,$moduleCount),static fn(array $b):bool=>$b['type']!=='round'));
                foreach($d['sessionStaff'] as $r) $sessionStaff[(int)$r['roster_session_id']][]=(int)$r['staff_id'];
                foreach($d['sessions'] as $s) {
                    $base=['planId'=>$id,'pairId'=>$pair['id'],'groupCount'=>count(array_filter($d['links'],static fn(array $l):bool=>(int)$l['roster_session_id']===(int)$s['id'])),'groupIds'=>array_map('intval',array_column(array_values(array_filter($d['links'],static fn(array $l):bool=>(int)$l['roster_session_id']===(int)$s['id'])),'roster_group_id')),'sessionId'=>(int)$s['id'],'moduleKey'=>$s['module_key'],'startTime'=>substr($s['start_time'],0,5),'endTime'=>substr($s['end_time'],0,5),'availability'=>$availability,'breaks'=>$breaks];
                    foreach($sessionStaff[(int)$s['id']]??[] as $sid) {
                        $groupIds=[];
                        foreach($d['links'] as $link) if((int)$link['roster_session_id']===(int)$s['id']) {
                            $gid=(int)$link['roster_group_id'];
                            $specific=array_values(array_filter($d['groupStaff'],static fn(array $r):bool=>(int)$r['roster_session_id']===(int)$s['id'] && (int)$r['roster_group_id']===$gid));
                            $assigned=RosterGroupSupervision::staffIds($link['supervision_kind']??null,array_map('intval',array_column($specific,'staff_id')),$sessionStaff[(int)$s['id']]??[]);
                            if(in_array($sid,$assigned,true)) $groupIds[]=$gid;
                        }
                        $tasks[]=[...$base,'staffId'=>$sid,'cook'=>false,'morning'=>$program==='ochtend','groupIds'=>$groupIds];
                    }
                    if($s['module_key']==='Voedsel-Innovatie') {
                        $sid=(int)($s['cook_staff_id']??$d['settings']['cook_staff_id']??0);
                        if($sid>0) $tasks[]=[...$base,'staffId'=>$sid,'cook'=>true];
                    }
                }
            }
            $baseline=$validator->validatePlans($revisions);
            foreach([...$baseline['day']['errors'],...array_merge(...array_map(static fn(array $r):array=>$r['errors'],$baseline['plans']))] as $e) {
                if(preg_match('/STAFF|GUIDES|COOK|SUPERVISION|SCHOOL_NOT_ALLOWED/',$e['code'])) throw new RosterPlanException('STAFF_REPAIR_REQUIRED',422,'Los eerst de personeelsconflicten of ontbrekende begeleiding op. Daarna kun je de gezamenlijke inzet optimaliseren.');
            }
            $fixed=[];
            foreach($raw->otherPlans($first['visit_date'],$ids[0]) as $other) {
                if(in_array((int)$other['id'],$ids,true)) continue;
                if($other['status']==='published') {
                    $snap=(new RosterSnapshotSqlRepository($this->pdo))->state((int)$other['id'])['current'];
                    if($snap===null) throw new RosterPlanException('FINAL_SNAPSHOT_MISSING',409,'Een ander definitief rooster kan niet worden gecontroleerd.');
                    $occupancy=$snap['content']['occupancy'];
                    foreach($occupancy as &$row) {
                        if(!array_key_exists('cookStaffId',$row)) $row['cookStaffId']=$row['moduleKey']==='Voedsel-Innovatie' ? ($snap['content']['stored']['settings']['cook_staff_id']??null) : null;
                    }
                    unset($row);
                    $fixed=[...$fixed,...$occupancy];
                } else $fixed=[...$fixed,...$validator->occupancy($raw->load((int)$other['id']))];
            }
            $optimizer=new RosterJointStaffOptimizer();
            $result=$optimizer->optimize($tasks,$members,$fixed);
            $candidate=$data; $maps=[]; $changes=[]; $names=array_column($members,'name','id');
            foreach($tasks as $i=>$task) {
                $new=$result['assignments'][$i]; $old=$task['staffId']; $pid=$task['planId']; $sid=$task['sessionId'];
                if($task['cook']) {
                    foreach($candidate[$pid]['sessions'] as &$session) if((int)$session['id']===$sid) $session['cook_staff_id']=$new;
                    unset($session);
                } else $maps[$pid][$sid][$old]=$new;
                if($new!==$old) $changes[]=['school'=>array_search($pid,$pair['planIds'],true)+1,'sessionId'=>$sid,'startTime'=>$task['startTime'],'endTime'=>$task['endTime'],
                    'module'=>$task['moduleKey'],'role'=>$task['cook']?'Kok':'Begeleider','from'=>$names[$old]??(string)$old,'to'=>$names[$new]??(string)$new];
            }
            foreach($candidate as $pid=>&$d) foreach(['sessionStaff','groupStaff'] as $key) {
                foreach($d[$key] as &$r) $r['staff_id']=$maps[$pid][(int)$r['roster_session_id']][(int)$r['staff_id']]??$r['staff_id'];
                unset($r);
            }
            unset($d);
            // Authoritative validation on the exact candidate, preserving existing lesson warnings/errors only.
            $day=[];
            foreach($candidate as $pid=>$d) {
                $report=$validator->validateData($d,$revisions[$pid])->toArray();
                foreach($report['errors'] as $e) if(!in_array($e,$baseline['plans'][$pid]['errors'],true)) throw new RosterPlanException('OPTIMIZATION_INVALID',422,'Het voorstel voldoet niet aan alle personeelsregels. Het huidige rooster blijft behouden.');
                $day[]=['planId'=>$pid,'pairId'=>$pair['id'],'revision'=>$revisions[$pid],'visitDate'=>$first['visit_date'],'schoolName'=>'School','cookStaffId'=>$d['settings']['cook_staff_id']??null,'occupancy'=>$validator->occupancy($d)];
            }
            $report=(new RosterDayRules(new RosterPlanningConfig()))->validate($day);
            foreach($report['errors'] as $e) if(str_starts_with($e['code'],'STAFF_') || $e['code']==='SHARED_SUPERVISION_CONFIRMATION_REQUIRED') throw new RosterPlanException('OPTIMIZATION_INVALID',422,'Het voorstel veroorzaakt een personeelsconflict.');
            $signature=hash('sha256',json_encode([$pair,$catalogRevision,$data,$members,$fixed,$result['assignments']],JSON_THROW_ON_ERROR));
            if($acceptedSignature!==null) {
                if(!hash_equals($signature,$acceptedSignature)) throw new RosterPlanException('STALE_OPTIMIZATION',409,'De roosters of personeelsgegevens zijn gewijzigd. Bekijk eerst een nieuw voorstel.');
                if(!$result['improved']) throw new RosterPlanException('NO_IMPROVEMENT',422,'Er is geen beter voorstel om toe te passen.');
                $cooks=new RosterSessionCookSqlRepository($this->pdo);
                foreach($candidate as $pid=>$d) {
                    $bySession=[]; $byGroup=[]; $selected=[];
                    foreach($d['sessionStaff'] as $r) { $bySession[(int)$r['roster_session_id']][]=(int)$r['staff_id']; $selected[]=(int)$r['staff_id']; }
                    foreach($d['groupStaff'] as $r) $byGroup[(int)$r['roster_session_id']][(int)$r['roster_group_id']][]=(int)$r['staff_id'];
                    foreach($d['sessions'] as $s) {
                        $sid=(int)$s['id'];
                        $staff->replaceSessionAssignments($sid,$bySession[$sid]??[],'manual');
                        $staff->replaceSessionGroupAssignments($sid,$byGroup[$sid]??[],'manual');
                        if($s['module_key']==='Voedsel-Innovatie') { $cooks->save($sid,(int)$s['cook_staff_id']); $selected[]=(int)$s['cook_staff_id']; }
                    }
                    $staff->addPlanSelection($pid,array_values(array_unique($selected)));
                    $plans->advanceRevision($pid,$actor);
                }
                RosterPairGuard::staff($this->pdo,$ids[0]);
                $this->pdo->commit();
            } else $this->pdo->rollBack();
            return ['signature'=>$signature,'improved'=>$result['improved'],'before'=>$result['before'],'after'=>$result['after'],'changes'=>$changes,
                'searchLimited'=>$result['searchLimited'],'applied'=>$acceptedSignature!==null];
        } catch(Throwable $e) { if($this->pdo->inTransaction()) $this->pdo->rollBack(); throw $e; }
    }
}

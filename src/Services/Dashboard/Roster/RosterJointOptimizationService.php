<?php
declare(strict_types=1);
namespace GeoFort\Services\Dashboard\Roster;

use GeoFort\Services\Sql\{RosterPlanSqlRepository,RosterPairSqlRepository,RosterStaffSqlRepository,RosterSessionCookSqlRepository,RosterValidationSqlRepository,RosterSnapshotSqlRepository,RosterSessionSqlRepository,BookingDaySettingsSqlRepository};
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
                if($d['sessions']===[]) throw new RosterPlanException('JOINT_NOT_READY',422,'Maak eerst voor beide scholen een les- en docentenrooster.');
                $sessionStaff=[];
                $config = new RosterPlanningConfig();
                $version = ($d['plan']['planning_mode'] === 'custom' ? $d['context'] : $d['booking'])['program_version'] ?? null;
                $program=$d['plan']['planning_mode']==='custom' ? ($d['context']['program_key']??'') : ($d['booking']['programma']??'');
                $moduleCount=count(array_unique(array_column($d['sessions'],'module_key')));
                $breaks=array_values(array_filter($validator->blocks($d,$program,$moduleCount),static fn(array $b):bool=>$b['type']!=='round'));
                foreach($d['sessionStaff'] as $r) $sessionStaff[(int)$r['roster_session_id']][]=(int)$r['staff_id'];
                foreach($d['sessions'] as $s) {
                    $base=['planId'=>$id,'pairId'=>$pair['id'],'groupCount'=>count(array_filter($d['links'],static fn(array $l):bool=>(int)$l['roster_session_id']===(int)$s['id'])),'groupIds'=>array_map('intval',array_column(array_values(array_filter($d['links'],static fn(array $l):bool=>(int)$l['roster_session_id']===(int)$s['id'])),'roster_group_id')),'sessionId'=>(int)$s['id'],'moduleKey'=>$s['module_key'],'startTime'=>substr($s['start_time'],0,5),'endTime'=>substr($s['end_time'],0,5),'availability'=>$availability,'breaks'=>$breaks,'requiresGeoFortForFinal'=>$config->requiresGeoFortForFinal($s['module_key'], $version)];
                    foreach($sessionStaff[(int)$s['id']]??[] as $sid) {
                        $groupIds=[];
                        foreach($d['links'] as $link) if((int)$link['roster_session_id']===(int)$s['id']) {
                            $gid=(int)$link['roster_group_id'];
                            $specific=array_values(array_filter($d['groupStaff'],static fn(array $r):bool=>(int)$r['roster_session_id']===(int)$s['id'] && (int)$r['roster_group_id']===$gid));
                            $assigned=RosterGroupSupervision::staffIds($link['supervision_kind']??null,array_map('intval',array_column($specific,'staff_id')),$sessionStaff[(int)$s['id']]??[]);
                            if(in_array($sid,$assigned,true)) $groupIds[]=$gid;
                        }
                        $tasks[]=[...$base,'staffId'=>$sid,'cook'=>false,'schoolAllowed'=>$program!=='ochtend' && (new RosterPlanningConfig())->schoolSupervisionAllowed($s['module_key']),'morning'=>$program==='ochtend','groupIds'=>$groupIds];
                    }
                    // Include open roles, so the joint tool can repair a five-person concept.
                    $unassigned = [];
                    foreach($d['links'] as $link) if((int)$link['roster_session_id']===(int)$s['id'] && (($link['supervision_kind']??null)!=='school' || $base['requiresGeoFortForFinal'])) {
                        $gid=(int)$link['roster_group_id']; $covered=false;
                        foreach($tasks as $t) if($t['planId']===$id && $t['sessionId']===(int)$s['id'] && in_array($gid,$t['groupIds'],true)) $covered=true;
                        if(!$covered) $unassigned[]=$gid;
                    }
                    $config=new RosterPlanningConfig();
                    if($unassigned!==[]) {
                        $required=$config->requiredGeoFortGuidesForSession($s['module_key'],count($unassigned),null,$version);
                        foreach($required<=1?[$unassigned]:array_map(static fn(int $gid):array=>[$gid],$unassigned) as $groupIds) {
                            $tasks[]=[...$base,'staffId'=>0,'cook'=>false,'schoolAllowed'=>$program!=='ochtend' && $config->schoolSupervisionAllowed($s['module_key']),'morning'=>$program==='ochtend','groupIds'=>$groupIds,'wasSchool'=>count(array_filter($d['links'],static fn(array $l):bool=>(int)$l['roster_session_id']===(int)$s['id'] && in_array((int)$l['roster_group_id'],$groupIds,true) && ($l['supervision_kind']??null)==='school'))===count($groupIds)];
                        }
                    }
                    if($s['module_key']==='Voedsel-Innovatie') {
                        $sid=(int)($s['cook_staff_id']??$d['settings']['cook_staff_id']??0);
                        $tasks[]=[...$base,'staffId'=>$sid,'cook'=>true];
                    }
                }
            }
            $baseline=$validator->validatePlans($revisions);
            foreach([...$baseline['day']['errors'],...array_merge(...array_map(static fn(array $r):array=>$r['errors'],$baseline['plans']))] as $e) {
                if(!in_array($e['code'],['GROUP_GUIDES_REQUIRED','OPEN_SUPERVISION','GUIDES_REQUIRED','COOK_REQUIRED','FOUR_MODULE_KE_SUPERVISION_REQUIRED'],true) && preg_match('/STAFF|GUIDES|COOK|SUPERVISION|SCHOOL_NOT_ALLOWED/',$e['code'])) throw new RosterPlanException('STAFF_REPAIR_REQUIRED',422,'Los eerst de personeelsconflicten op. Daarna kun je de gezamenlijke inzet optimaliseren.');
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
            $selected=array_values(array_unique(array_merge(...array_map(static fn(array $d):array=>array_map('intval',array_column($d['selectedStaff'],'staff_id')),$data))));
            $result=$optimizer->optimize($tasks,$members,$fixed,$selected);
            $candidate=$data; $changes=[]; $names=array_column($members,'name','id');
            foreach($candidate as &$d) { $d['sessionStaff']=[]; $d['groupStaff']=[]; }
            unset($d);
            foreach($tasks as $i=>$task) {
                $new=$result['assignments'][$i]; $old=$task['staffId']; $pid=$task['planId']; $sid=$task['sessionId'];
                if($task['cook']) {
                    foreach($candidate[$pid]['sessions'] as &$session) if((int)$session['id']===$sid) $session['cook_staff_id']=$new;
                    unset($session);
                } else {
                    if($new>0) $candidate[$pid]['sessionStaff'][]=['roster_session_id'=>$sid,'staff_id'=>$new,'assignment_source'=>'manual'];
                    foreach($task['groupIds'] as $gid) {
                        if($new>0) $candidate[$pid]['groupStaff'][]=['roster_session_id'=>$sid,'roster_group_id'=>$gid,'staff_id'=>$new,'assignment_source'=>'manual'];
                        foreach($candidate[$pid]['links'] as &$link) if((int)$link['roster_session_id']===$sid && (int)$link['roster_group_id']===$gid) $link['supervision_kind']=$new>0?'staff':(($task['schoolAllowed']??false)?'school':'open');
                        unset($link);
                    }
                }
                if($new!==$old) $changes[]=['school'=>array_search($pid,$pair['planIds'],true)+1,'sessionId'=>$sid,'startTime'=>$task['startTime'],'endTime'=>$task['endTime'],
                    'module'=>$task['moduleKey'],'role'=>$task['cook']?'Kok':'Begeleider','from'=>$names[$old]??(($task['wasSchool']??false)?'Schoolbegeleiding':'Open'),'to'=>$names[$new]??(($task['schoolAllowed']??false)?'Schoolbegeleiding':'Open')];
            }
            // Sharing one KE guide across its two groups creates one session-level assignment.
            foreach($candidate as &$d) {
                $unique=[];
                foreach($d['sessionStaff'] as $r) $unique[$r['roster_session_id'].':'.$r['staff_id']]=$r;
                $d['sessionStaff']=array_values($unique);
            }
            unset($d);
            // Any employee selected in either school can be assigned to both in this reviewed proposal.
            foreach($candidate as &$d) foreach($selected as $sid) if(!in_array($sid,array_map('intval',array_column($d['selectedStaff'],'staff_id')),true)) $d['selectedStaff'][]=['staff_id'=>$sid,'availability_start'=>$availability[$sid][0]??null,'availability_end'=>$availability[$sid][1]??null];
            unset($d);
            // Authoritative validation on the exact candidate, preserving existing lesson warnings/errors only.
            $day=[]; $finalizationIssues=[];
            foreach($candidate as $pid=>$d) {
                $report=$validator->validateData($d,$revisions[$pid])->toArray();
                foreach($report['errors'] as $e) if($e['code']!=='FOUR_MODULE_KE_SUPERVISION_REQUIRED' && !in_array($e,$baseline['plans'][$pid]['errors'],true)) throw new RosterPlanException('OPTIMIZATION_INVALID',422,'Het voorstel voldoet niet aan alle personeelsregels. Het huidige rooster blijft behouden.');
                foreach($report['errors'] as $e) if($e['code']==='FOUR_MODULE_KE_SUPERVISION_REQUIRED') {
                    $session=array_values(array_filter($d['sessions'],static fn(array $s):bool=>(int)$s['id']===($e['sessionId']??0)))[0];
                    $finalizationIssues[]=[...$e,'school'=>array_search($pid,$pair['planIds'],true)+1,'startTime'=>substr($session['start_time'],0,5),'endTime'=>substr($session['end_time'],0,5)];
                }
                $day[]=['planId'=>$pid,'pairId'=>$pair['id'],'revision'=>$revisions[$pid],'visitDate'=>$first['visit_date'],'schoolName'=>'School','cookStaffId'=>$d['settings']['cook_staff_id']??null,'occupancy'=>$validator->occupancy($d)];
            }
            $report=(new RosterDayRules(new RosterPlanningConfig()))->validate($day);
            foreach($report['errors'] as $e) if(str_starts_with($e['code'],'STAFF_') || $e['code']==='SHARED_SUPERVISION_CONFIRMATION_REQUIRED') throw new RosterPlanException('OPTIMIZATION_INVALID',422,'Het voorstel veroorzaakt een personeelsconflict.');
            $signature=hash('sha256',json_encode([$pair,$catalogRevision,$data,$members,$fixed,$result['assignments']],JSON_THROW_ON_ERROR));
            if($acceptedSignature!==null) {
                if(!hash_equals($signature,$acceptedSignature)) throw new RosterPlanException('STALE_OPTIMIZATION',409,'De roosters of personeelsgegevens zijn gewijzigd. Bekijk eerst een nieuw voorstel.');
                if($result['after']['unfilledAssignments']>0) throw new RosterPlanException('STAFF_REPAIR_REQUIRED',422,'Er blijven verplichte docentplekken open. Pas de selectie of lesindeling aan.');
                if(!$result['improved']) throw new RosterPlanException('NO_IMPROVEMENT',422,'Er is geen beter voorstel om toe te passen.');
                $cooks=new RosterSessionCookSqlRepository($this->pdo);
                $sessions=new RosterSessionSqlRepository($this->pdo);
                foreach($candidate as $pid=>$d) {
                    $bySession=[]; $byGroup=[]; $selected=[];
                    foreach($d['sessionStaff'] as $r) { $bySession[(int)$r['roster_session_id']][]=(int)$r['staff_id']; $selected[]=(int)$r['staff_id']; }
                    foreach($d['groupStaff'] as $r) $byGroup[(int)$r['roster_session_id']][(int)$r['roster_group_id']][]=(int)$r['staff_id'];
                    foreach($d['links'] as $link) $sessions->setGroupSupervision((int)$link['roster_session_id'],(int)$link['roster_group_id'],$link['supervision_kind']??null);
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
                'finalizationIssues'=>$finalizationIssues,'searchLimited'=>$result['searchLimited'],'applied'=>$acceptedSignature!==null];
        } catch(Throwable $e) { if($this->pdo->inTransaction()) $this->pdo->rollBack(); throw $e; }
    }
}

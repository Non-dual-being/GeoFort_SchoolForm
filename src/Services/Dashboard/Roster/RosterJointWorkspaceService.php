<?php
declare(strict_types=1);
namespace GeoFort\Services\Dashboard\Roster;
use GeoFort\Services\Sql\{RosterPlanSqlRepository,RosterPairSqlRepository,RosterValidationSqlRepository,RosterSnapshotSqlRepository,RosterStaffSqlRepository,RosterSessionSqlRepository,RosterSessionCookSqlRepository,RosterLessonPreferencesSqlRepository,RosterJointWorkspaceSqlRepository,RosterSingleWorkspaceSqlRepository,BookingDaySettingsSqlRepository};
use PDO;
use Throwable;

/** Read/preview is side-effect free. Apply rechecks the exact reviewed state under short locks. */
final readonly class RosterJointWorkspaceService
{
    public function __construct(private PDO $pdo, private bool $single = false) {}

    public function run(array $revisions, string $mode, ?array $state, array $options, ?string $signature, int $actor): array
    {
        if (!in_array($mode,['read','preview','optimize','apply','candidates'],true) || count($revisions)!==($this->single ? 1 : 2)) throw new RosterPlanException('INVALID_REQUEST',422,$this->single?'Kies één schoolrooster.':'Kies twee gekoppelde schoolroosters.');
        if ($this->single && ($options['shiftLessons']??false)===true) throw new RosterPlanException('LESSON_SLOTS_LOCKED',422,'Lestijden wijzigen kan alleen in de wizard.');
        // Read the lock key before opening the transaction. All consistent reads
        // during apply happen after the catalog and visit-date locks are acquired.
        $initial=$mode==='apply'?(new RosterPlanSqlRepository($this->pdo))->findById(min(array_keys($revisions))):null;
        $this->pdo->beginTransaction();
        try {
            $context=$this->context($revisions,$mode==='apply',$initial);
            $validator=$this->validator();
            $base=RosterJointDraft::state($context['data']);
            $readiness=$this->readiness($context,$validator);
            if ($mode==='read') {
                $this->pdo->rollBack();
                return ['state'=>$base,'readiness'=>$readiness,'context'=>$context['hash'],'options'=>$context['options'],'members'=>$context['members'],'selected'=>array_values(array_unique(array_merge(...array_map(fn($d)=>array_map('intval',array_column($d['selectedStaff'],'staff_id')),$context['data'])))),
                    'existing'=>array_values(array_unique(array_filter(array_column(RosterJointDraft::tasks($context['data'],$validator,($context['pair']['id']??null)),'staffId'))))];
            }
            if ($this->single && $context['data'][array_key_first($context['data'])]['plan']['status']!=='concept') throw new RosterPlanException('PLAN_NOT_EDITABLE',409,'Dit rooster is definitief. Heropen het bewust voordat je wijzigingen maakt.');
            if (!$readiness['canOpenJoint']) throw new RosterPlanException('JOINT_NOT_READY',422,$this->single?'Sla eerst de lesindeling op in de wizard.':'Sla eerst voor beide scholen een volledige, geldige lesindeling op.');
            if ($state===null) throw new RosterPlanException('INVALID_DRAFT',422,'De bewerkversie ontbreekt.');
            $candidate=RosterJointDraft::patch($context['data'],$state,$options);
            RosterJointStaffApproval::check(RosterJointDraft::state($candidate),$options,$context['options']??[]);
            // Long searches never hold database locks. The apply fingerprint detects all intervening changes.
            if ($mode!=='apply') $this->pdo->rollBack();
            $search=null;
            if ($mode==='optimize') {
                [$state,$search]=$this->optimize($context,$candidate,$options,$validator);
                RosterJointStaffApproval::check($state,$options,$context['options']??[]);
                $candidate=RosterJointDraft::patch($context['data'],$state,$options);
            }
            if ($mode==='candidates') return ['candidates'=>$this->candidates($context,$candidate,$options,$validator)];
            $report=$this->report($context,$candidate,$validator);
            $normalized=RosterJointDraft::state($candidate);
            $token=hash('sha256',json_encode([$context['hash'],$normalized,$options],JSON_THROW_ON_ERROR));
            $result=['state'=>$normalized,'signature'=>$token,'report'=>$report,'search'=>$search,'applied'=>false,'context'=>$context['hash']];
            if ($mode==='apply') {
                if ($signature===null || !hash_equals($token,$signature)) throw new RosterPlanException('STALE_JOINT_DRAFT',409,'De roosters, aanvragen of personeelsgegevens zijn gewijzigd. Je bewerkversie is behouden; controleer opnieuw voordat je toepast.');
                if (!$report['canApply']) throw new RosterPlanException('JOINT_DRAFT_INVALID',422,'Los eerst de getoonde conflicten op. Er is niets opgeslagen.');
                $this->persist($context,$candidate,$base,$normalized,$options,$actor);
                $this->pdo->commit(); $result['applied']=true;
            }
            return $result;
        } catch (Throwable $e) { if ($this->pdo->inTransaction()) $this->pdo->rollBack(); throw $e; }
    }

    private function validator(): RosterValidationService
    {
        return new RosterValidationService(new RosterValidationSqlRepository($this->pdo),new RosterSnapshotSqlRepository($this->pdo),new RosterPlanningConfig(),new RosterGenerationTemplateProvider());
    }
    private function context(array $revisions, bool $lock, ?array $initial): array
    {
        ksort($revisions,SORT_NUMERIC); $ids=array_keys($revisions);
        $plans=new RosterPlanSqlRepository($this->pdo); $raw=new RosterValidationSqlRepository($this->pdo); $snapshots=new RosterSnapshotSqlRepository($this->pdo);
        $catalogRevision=$lock?RosterStaffCatalogGuard::lock($this->pdo):RosterStaffCatalogGuard::revision($this->pdo);
        if ($lock) {
            if (!$initial) throw new RosterPlanException('PLAN_NOT_FOUND',404,'Een schoolrooster bestaat niet meer.');
            (new BookingDaySettingsSqlRepository($this->pdo))->lockDate($initial['visit_date']);
        }
        $first=$plans->findById($ids[0]);
        if (!$first) throw new RosterPlanException('PLAN_NOT_FOUND',404,'Een schoolrooster bestaat niet meer.');
        if ($lock && $first['visit_date']!==$initial['visit_date']) throw new RosterPlanException('STALE_CONTEXT',409,'De bezoekdatum is gewijzigd. Controleer de roosters opnieuw.');
        foreach ($revisions as $id=>$revision) {
            if ($lock) $plans->lockPlan($id);
            $p=$plans->findById($id);
            if (!$p || (int)$p['revision']!==$revision || $p['visit_date']!==$first['visit_date']) throw new RosterPlanException('STALE_REVISION',409,'Een schoolrooster is gewijzigd. Je bewerkversie blijft zichtbaar; laad daarna de actuele roosters.');
            if (!in_array($p['status'],['concept','published'],true)) throw new RosterPlanException('PLAN_NOT_EDITABLE',409,'Dit rooster is niet meer beschikbaar.');
            if ($lock && $p['booking_id']) $raw->lockBooking((int)$p['booking_id']);
        }
        $pair=(new RosterPairSqlRepository($this->pdo))->forPlan($ids[0]); $pairIds=$pair['planIds']??[]; sort($pairIds);
        if ($this->single && $pair!==null) throw new RosterPlanException('JOINT_WORKSPACE_REQUIRED',409,'Deze school is gekoppeld. Open de gezamenlijke werkruimte om beide scholen te controleren.');
        if (!$this->single && $pairIds!==$ids) throw new RosterPlanException('PAIR_REQUIRED',409,'Deze scholen zijn niet meer gekoppeld.');
        $data=[]; $prefs=new RosterLessonPreferencesSqlRepository($this->pdo);
        foreach ($ids as $id) {
            $d=$raw->load($id);
            if ($d['plan']['status']==='published') {
                $snapshot=$snapshots->state($id)['current'];
                if (!$snapshot) throw new RosterPlanException('FINAL_SNAPSHOT_MISSING',409,'Het definitieve rooster heeft geen betrouwbare snapshot.');
                $plan=$d['plan']; $d=$snapshot['content']['stored']; $d['plan']=$plan;
            }
            $d['lessonPreferences']=$prefs->forPlan($id); $data[$id]=$d;
        }
        $validator=$this->validator(); $external=[]; $fixed=[];
        foreach ($raw->otherPlans($first['visit_date'],$ids[0]) as $other) {
            $id=(int)$other['id']; if (in_array($id,$ids,true)) continue;
            if ($lock) $plans->lockPlan($id);
            if ($other['status']==='published') {
                $snapshot=$snapshots->state($id)['current'];
                if (!$snapshot) throw new RosterPlanException('FINAL_SNAPSHOT_MISSING',409,'Een ander definitief rooster kan niet worden gecontroleerd.');
                $d=$snapshot['content']['stored'];
            } else $d=$raw->load($id);
            $external[$id]=$d; $fixed=[...$fixed,...$validator->occupancy($d)];
        }
        $members=(new RosterStaffSqlRepository($this->pdo))->catalog();
        $options=$this->single?(new RosterSingleWorkspaceSqlRepository($this->pdo))->options($ids[0]):(new RosterJointWorkspaceSqlRepository($this->pdo))->options($pair['id']);
        $hash=hash('sha256',json_encode(['joint-workspace-v1',$catalogRevision,$pair,$data,$external,$members,$options],JSON_THROW_ON_ERROR));
        return compact('data','external','fixed','members','pair','hash','options');
    }

    private function readiness(array $c, RosterValidationService $v): array
    {
        $schools=[];
        foreach ($c['data'] as $id=>$d) {
            if ($d['plan']['status']==='published') $errors=[];
            else {
                $d['sessionStaff']=$d['groupStaff']=$d['selectedStaff']=[]; $d['settings']=null;
                foreach ($d['sessions'] as &$s) unset($s['cook_staff_id']); unset($s);
                foreach ($d['links'] as &$l) $l['supervision_kind']='open'; unset($l);
                $errors=array_values(array_filter($v->validateData($d,(int)$d['plan']['revision'])->errors,fn($e)=>!in_array($e['code'],RosterJointDraft::OPEN_ERRORS,true)));
            }
            if ($d['sessions']===[] && $errors===[]) $errors[]=['code'=>'NO_LESSONS','message'=>'Sla eerst de lesindeling op.'];
            $schools[]=['id'=>$id,'ready'=>$errors===[],'issues'=>$errors];
        }
        $ready=!array_filter($schools,fn($s)=>!$s['ready']);
        $report=$this->report($c,$c['data'],$v);
        return ['canOpenJoint'=>$ready,'canOptimize'=>$ready && count(array_filter($c['data'],fn($d)=>$d['plan']['status']==='concept'))>0,'canFinalize'=>$report['canFinalize'],'schools'=>$schools,'report'=>$report];
    }
    private function report(array $c, array $candidate, RosterValidationService $v): array
    {
        $errors=[]; $warnings=[]; $day=[];
        foreach ($candidate+$c['external'] as $id=>$d) {
            if (isset($candidate[$id]) && $d['plan']['status']==='concept') {
                $r=$v->validateData($d,(int)$d['plan']['revision'])->toArray();
                foreach ($r['errors'] as $e) $errors[]=[...$e,'planId'=>$id];
                foreach ($r['warnings'] as $e) $warnings[]=[...$e,'planId'=>$id];
            }
            $day[]=['planId'=>$id,'pairId'=>isset($candidate[$id])?($c['pair']['id']??null):null,'revision'=>(int)$d['plan']['revision'],'visitDate'=>$d['plan']['visit_date'],
                'schoolName'=>$d['context']['school_name']??$d['booking']['schoolnaam']??'School','cookStaffId'=>$d['settings']['cook_staff_id']??null,'occupancy'=>$v->occupancy($d)];
        }
        $r=(new RosterDayRules(new RosterPlanningConfig()))->validate($day,false,null,array_keys($candidate));
        $errors=[...$errors,...$r['errors']]; $warnings=[...$warnings,...$r['warnings']];
        foreach (RosterJointDraft::tasks($candidate,$v,($c['pair']['id']??null)) as $t) {
            if ($t['locked'] || $t['staffId']===0) continue;
            $id=$t['staffId']; $window=$t['availability'][$id]??null;
            $member=array_values(array_filter($c['members'],fn($m)=>$m['id']===$id))[0]??null;
            if ($t['cook'] && (!$member || !$member['isActive'] || !$member['canCook'])) $errors[]=['code'=>'COOK_NOT_QUALIFIED','planId'=>$t['planId'],'sessionId'=>$t['sessionId'],'message'=>'Deze medewerker is niet bevoegd als kok.'];
            if ($window===null) $errors[]=['code'=>'STAFF_DAY_SELECTION_REQUIRED','planId'=>$t['planId'],'sessionId'=>$t['sessionId'],'staffId'=>$id,'message'=>'Bevestig eerst de beschikbaarheid van de toegevoegde medewerker.'];
            elseif (($window[0]!==null && $t['startTime']<$window[0]) || ($window[1]!==null && $t['endTime']>$window[1])) $errors[]=['code'=>'STAFF_UNAVAILABLE','planId'=>$t['planId'],'sessionId'=>$t['sessionId'],'staffId'=>$id,'message'=>'Deze les valt buiten de beschikbaarheid van de medewerker.'];
        }
        foreach ($candidate as $pid=>$d) if ($d['plan']['status']==='concept') foreach ($d['sessions'] as $s) if ($s['module_key']==='Voedsel-Innovatie') {
            $guides=array_column(array_values(array_filter($d['sessionStaff'],fn($r)=>(int)$r['roster_session_id']===(int)$s['id'])),'staff_id');
            if (count(array_unique($guides))>1 || in_array((int)($s['cook_staff_id']??0),array_map('intval',$guides),true)) $errors[]=['code'=>'VI_ROLES_CONFLICT','planId'=>$pid,'sessionId'=>(int)$s['id'],'message'=>'Voedsel Innovatie heeft één kok en één andere begeleider, ook bij twee groepen.'];
        }
        return ['errors'=>$errors,'warnings'=>$warnings,'canApply'=>!array_filter($errors,fn($e)=>!in_array($e['code'],RosterJointDraft::OPEN_ERRORS,true)),'canFinalize'=>$errors===[]];
    }

    private function optimize(array $c, array $candidate, array $options, RosterValidationService $v): array
    {
        $optimizer=new RosterJointStaffOptimizer();
        $originalTasks=RosterJointDraft::tasks($candidate,$v,($c['pair']['id']??null));
        $existing=array_values(array_unique(array_filter(array_column(RosterJointDraft::tasks($c['data'],$v,($c['pair']['id']??null)),'staffId'))));
        $selected=array_values(array_unique(array_merge(...array_map(fn($d)=>array_map('intval',array_column($d['selectedStaff'],'staff_id')),$candidate))));
        $pool=($options['teamMode']??'existing')==='existing'?$existing:array_values(array_intersect($options['teamIds']??$selected,$selected));
        $approved=array_column($options['approvedStaff']??[],'id');
        $pool=array_values(array_unique([...$pool,...$approved]));
        foreach ($originalTasks as $t) if ($t['locked'] && $t['staffId']>0 && !in_array($t['staffId'],$pool,true)) $pool[]=$t['staffId'];
        $retained=$options['retained']??[];
        foreach ($retained as $id) if (!in_array($id,$pool,true)) throw new RosterPlanException('RETAINED_STAFF_MISSING',422,'Een behouden medewerker ontbreekt in het gekozen team.');
        $evaluate=function(array $data) use ($c,$options,$v,$optimizer,$pool,$retained): array {
            $tasks=RosterJointDraft::tasks($data,$v,($c['pair']['id']??null));
            foreach ($tasks as &$t) {
                $keys=$t['cook']?[$t['planId'].':'.$t['sessionId'].':cook']:array_map(fn($gid)=>$t['planId'].':'.$t['sessionId'].':'.$gid,$t['groupIds']);
                if (array_intersect($keys,$options['staffPins']??[])!==[] || in_array($t['staffId'],array_column($options['approvedStaff']??[],'id'),true)) $t['locked']=true;
            }
            unset($t);
            $result=$optimizer->optimize($tasks,$c['members'],$c['fixed'],$pool,['pool'=>$pool,'retained'=>$retained,'approved'=>array_column($options['approvedStaff']??[],'id'),'maxMilliseconds'=>2000]);
            $state=RosterJointDraft::assign(RosterJointDraft::state($data),$tasks,$result['assignments']);
            $trial=RosterJointDraft::patch($c['data'],$state,$options);
            return [$state,$result,$this->report($c,$trial,$v)['canApply']];
        };
        [$best,$result,$valid]=$evaluate($candidate); $count=1; $deadline=microtime(true)+8; $limited=false;
        // Search swaps of whole rounds per school. Each trial is staffed jointly against both schools.
        if (($options['shiftLessons']??false)===true) {
            $seed=RosterJointDraft::state($candidate);
            foreach ($seed as $pi=>$plan) {
                if ($candidate[$plan['id']]['plan']['status']!=='concept') continue;
                $slots=[]; foreach ($plan['sessions'] as $s) $slots[$s['start'].':'.$s['end']]=[$s['start'],$s['end']]; $slots=array_values($slots);
                for ($a=0;$a<count($slots);$a++) for ($b=$a+1;$b<count($slots);$b++) {
                    if ($count>=20 || microtime(true)>$deadline) { $limited=true; break 3; }
                    if (RosterJointDraft::minutes($slots[$a][1])-RosterJointDraft::minutes($slots[$a][0])!==RosterJointDraft::minutes($slots[$b][1])-RosterJointDraft::minutes($slots[$b][0])) continue;
                    $trial=$best;
                    foreach ($trial[$pi]['sessions'] as &$s) { if ([$s['start'],$s['end']]===$slots[$a]) [$s['start'],$s['end']]=$slots[$b]; elseif ([$s['start'],$s['end']]===$slots[$b]) [$s['start'],$s['end']]=$slots[$a]; } unset($s);
                    try {
                        $raw=RosterJointDraft::patch($c['data'],$trial,$options);
                        [$next,$score,$ok]=$evaluate($raw); $count++;
                        if ($ok && (!$valid || $score['after']['score']<$result['after']['score'])) { $best=$next; $result=$score; $valid=true; }
                    } catch (RosterPlanException) { continue; }
                }
            }
        }
        $finalData=RosterJointDraft::patch($c['data'],$best,$options);
        $finalTasks=RosterJointDraft::tasks($finalData,$v,($c['pair']['id']??null));
        if (array_diff($retained,array_column($finalTasks,'staffId'))!==[]) throw new RosterPlanException('RETAINED_STAFF_UNAVAILABLE',422,'Niet iedere behouden medewerker kan binnen deze indeling worden ingezet. Pas de vaste afspraken of het team aan.');
        foreach ($finalTasks as $t) if (!$t['locked'] && $t['staffId']>0 && !in_array($t['staffId'],$pool,true)) throw new RosterPlanException('TEAM_NOT_FEASIBLE',422,'Met het gekozen team is geen geldige herindeling gevonden. Voeg een geschikte medewerker toe of geef meer lessen vrij.');
        $before=$optimizer->metrics($originalTasks,array_column($originalTasks,'staffId'),array_column($c['members'],null,'id'),array_column($originalTasks,'staffId'));
        return [$best,['before'=>$before,'after'=>$result['after'],'candidateCount'=>$count,'searchLimited'=>$result['searchLimited'] || $limited]];
    }

    private function candidates(array $c, array $data, array $options, RosterValidationService $v): array
    {
        $target=$options['target']??[]; $pid=$target['planId']??0; $sid=$target['sessionId']??0; $gid=$target['groupId']??0; $cook=($target['cook']??false)===true;
        $session=array_values(array_filter($data[$pid]['sessions']??[],fn($s)=>(int)$s['id']===$sid))[0]??null;
        if (!$session) throw new RosterPlanException('INVALID_TARGET',422,'Kies eerst een les.');
        $tasks=RosterJointDraft::tasks($data,$v,($c['pair']['id']??null)); $rows=[]; $selected=array_map('intval',array_column($data[$pid]['selectedStaff'],'staff_id'));
        $baseline=$this->report($c,$data,$v)['errors'];
        foreach ($c['members'] as $m) {
            $qualified=$m['isActive'] && ($cook?$m['canCook']:($m['canGuide'] && in_array($session['module_key'],RosterPlanningConfig::qualifiedModuleKeys(array_column($m['preferences'],'moduleKey')),true)));
            $reasons=[];
            if (!$qualified) $reasons[]='Niet bevoegd voor deze rol';
            $uses=[];
            foreach ($tasks as $t) if ($t['staffId']===$m['id']) $uses[]=['planId'=>$t['planId'],'start'=>$t['startTime'],'end'=>$t['endTime'],'moduleKey'=>$t['moduleKey'],'cook'=>$t['cook']];
            foreach ($c['fixed'] as $t) if (in_array($m['id'],$t['staffIds'],true)) $uses[]=['planId'=>0,'start'=>$t['startTime'],'end'=>$t['endTime'],'moduleKey'=>$t['moduleKey'],'cook'=>($t['cookStaffId']??0)===$m['id']];
            foreach ($uses as $u) if ($u['start']<substr($session['end_time'],0,5) && $u['end']>substr($session['start_time'],0,5) && !($u['planId']===$pid && $u['start']===substr($session['start_time'],0,5) && $u['moduleKey']===$session['module_key'] && $u['cook']===$cook)) $reasons[]='Bezet tijdens deze les; omwisselen kan mogelijk helpen';
            if ($qualified) {
                $trial=RosterJointDraft::state($data);
                foreach ($trial as &$p) if ($p['id']===$pid) foreach ($p['sessions'] as &$s) if ($s['id']===$sid) {
                    if ($cook) $s['cook']=$m['id'];
                    else foreach ($s['groups'] as &$g) if ($g['id']===$gid || $session['module_key']==='Voedsel-Innovatie') { $g['kind']='staff';$g['staffIds']=[$m['id']]; } unset($g);
                } unset($p,$s);
                try {
                    $trialOptions=$options; $trialOptions['staffPins']=[];
                    $trialData=RosterJointDraft::patch($c['data'],$trial,$trialOptions);
                    foreach ($this->report($c,$trialData,$v)['errors'] as $e) if (!in_array($e['code'],[...RosterJointDraft::OPEN_ERRORS,'STAFF_DAY_SELECTION_REQUIRED'],true) && !in_array($e,$baseline,true)) $reasons[]=$e['message'];
                } catch (RosterPlanException $e) { $reasons[]=$e->getMessage(); }
            }
            $rows[]=['id'=>$m['id'],'qualified'=>$qualified,'selected'=>in_array($m['id'],$selected,true),'uses'=>$uses,'reasons'=>array_values(array_unique($reasons))];
        }
        return $rows;
    }

    private function persist(array $c, array $data, array $before, array $after, array $options, int $actor): void
    {
        $plans=new RosterPlanSqlRepository($this->pdo); $sessions=new RosterSessionSqlRepository($this->pdo); $staff=new RosterStaffSqlRepository($this->pdo); $cooks=new RosterSessionCookSqlRepository($this->pdo);
        foreach ($data as $pid=>$d) {
            if ($d['plan']['status']!=='concept') continue;
            $prior=RosterJointDraft::state([$pid=>$c['data'][$pid]]); $next=RosterJointDraft::state([$pid=>$d]);
            if ($prior===$next && $options===($c['options']??[])) continue;
            $cooks->clearForPlan($pid);
            (new RosterJointWorkspaceSqlRepository($this->pdo))->clearCookFallback($pid);
            foreach ($d['sessions'] as $s) {
                $sid=(int)$s['id']; $byGroup=[];
                $ids=array_map('intval',array_column(array_values(array_filter($d['sessionStaff'],fn($r)=>(int)$r['roster_session_id']===$sid)),'staff_id'));
                foreach ($d['groupStaff'] as $r) if ((int)$r['roster_session_id']===$sid) $byGroup[(int)$r['roster_group_id']][]=(int)$r['staff_id'];
                $sessions->updateActivity($sid,$pid,$s['module_key'],$s['start_time'],$s['end_time'],$s['location_label']??null,$actor);
                $staff->replaceSessionAssignments($sid,$ids,'manual'); $staff->replaceSessionGroupAssignments($sid,$byGroup,'manual');
                if ($s['module_key']==='Voedsel-Innovatie' && (int)$s['cook_staff_id']>0) $cooks->save($sid,(int)$s['cook_staff_id']);
            }
            foreach ($d['links'] as $l) $sessions->setGroupSupervision((int)$l['roster_session_id'],(int)$l['roster_group_id'],$l['supervision_kind']);
            $staff->addPlanSelection($pid,array_map('intval',array_column($d['selectedStaff'],'staff_id')));
            (new RosterJointWorkspaceSqlRepository($this->pdo))->availability($pid,$d['selectedStaff']);
            $plans->advanceRevision($pid,$actor);
        }
        if ($this->single) (new RosterSingleWorkspaceSqlRepository($this->pdo))->record(array_key_first($data),$actor,$before,$after,$options);
        else (new RosterJointWorkspaceSqlRepository($this->pdo))->record($c['pair']['id'],$actor,$before,$after,$options);
    }
}

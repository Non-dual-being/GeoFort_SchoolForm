<?php
declare(strict_types=1);
require __DIR__.'/Support/RosterLifecycleFixtures.php';
use GeoFort\Services\Dashboard\Roster\RosterPlanException;
$db=DisposableBookingMariaDb::create('roster_swap');
try {
    RosterLifecycleFixtures::schema($db->pdo);
    $s=RosterLifecycleFixtures::services($db->pdo);
    $assert=RosterLifecycleFixtures::assert(...);
    $plan=RosterLifecycleFixtures::validPlan($db->pdo,$s,18,1,2);
    $id=$plan['id'];$gid=$plan['groups'][0]['id'];
    $rows=$s['sessions']->findByPlanId($id);[$a,$b]=$rows;
    $swap=fn($mode,$revision,$first=null,$second=null)=>$s['sessionService']->swap($id,$revision,$mode,$first??(int)$a['id'],$gid,$second??(int)$b['id'],$gid,1);
    $state=fn()=>serialize($s['raw']->load($id));
    $reject=function(callable $operation,string $code)use($assert,$state){$before=$state();try{$operation();throw new RuntimeException('Unexpected success: '.$code);}catch(RosterPlanException $e){$assert($e->publicCode===$code,'Unexpected error '.$e->publicCode.' instead of '.$code);}$assert($state()===$before,'Rejected swap changed stored data');};
    $result=$swap('lesson',1);$assert($result['revision']===2,'One revision per exchange');
    $moved=$s['sessions']->findByIdForPlan((int)$a['id'],$id);
    $assert($moved['start_time']===$b['start_time']&&$moved['module_key']===$a['module_key'],'Lesson did not move intact');
    $assert($s['validator']->validate($id,2)->isValid(),'Swapped lesson invalid');
    $reject(fn()=>$swap('lesson',1),'ROSTER_REVISION_CONFLICT');
    $reject(fn()=>$swap('staff',2),'STAFF_MODULE_NOT_ALLOWED');
    $assign=$s['staff']->assignmentsForPlan($id);
    $left=(int)$assign[$a['id']][0]['id'];$right=(int)$assign[$b['id']][0]['id'];
    $insert=$db->pdo->prepare('INSERT IGNORE INTO roster_staff_module_preferences(staff_id,module_key,preference_rank) VALUES(?,?,7)');
    $insert->execute([$left,$b['module_key']]);$insert->execute([$right,$a['module_key']]);
    $result=$swap('staff',2);$assert($result['revision']===3,'Staff swap must advance once');
    $assign=$s['staff']->assignmentsForPlan($id);
    $assert((int)$assign[$a['id']][0]['id']===$right&&(int)$assign[$b['id']][0]['id']===$left,'Guides not exchanged');
    $assert($s['validator']->validate($id,3)->isValid(),'Group/aggregate staff inconsistent');
    $reject(fn()=>$swap('lesson',3,(int)$a['id'],(int)$a['id']),'INVALID_SWAP');
    $other=RosterLifecycleFixtures::validPlan($db->pdo,$s,18,1,2,'2028-10-11');
    $foreign=$s['sessions']->findByPlanId($other['id'])[0];
    $reject(fn()=>$swap('lesson',3,null,(int)$foreign['id']),'INVALID_SWAP');
    // Both guides are qualified, but moving a lesson outside a person's window is forbidden.
    $db->pdo->prepare('UPDATE roster_plan_staff SET availability_start=? WHERE roster_plan_id=? AND staff_id=?')->execute([$b['start_time'],$id,$right]);
    $reject(fn()=>$swap('lesson',3),'SWAP_AVAILABILITY');
    $db->pdo->prepare('UPDATE roster_plan_staff SET availability_start=NULL WHERE roster_plan_id=?')->execute([$id]);
    $s['lifecycle']->finalize($id,3,1);
    $reject(fn()=>$swap('lesson',4),'PLAN_NOT_EDITABLE');
    $multi=RosterLifecycleFixtures::validPlan($db->pdo,$s,56,4,2,'2028-10-12');
    $mid=$multi['id'];$multiRows=$s['sessions']->findByPlanId($mid);
    // Exchanging whole lessons across different groups must not create overlapping groups.
    $before=serialize($s['raw']->load($mid));
    try {
        $s['sessionService']->swap($mid,1,'lesson',(int)$multiRows[0]['id'],(int)$multiRows[0]['group_ids'][0],(int)$multiRows[3]['id'],(int)$multiRows[3]['group_ids'][0],1);
        throw new RuntimeException('Conflicting group exchange accepted');
    } catch(RosterPlanException $e) {$assert(in_array($e->publicCode,['SWAP_CONFLICT','STAFF_TIME_CONFLICT'],true),'Unexpected overlap error');}
    $assert($before===serialize($s['raw']->load($mid)),'Conflict left half an exchange');
    // Explicit per-group assignments in a shared lesson must swap without changing the other lesson.
    $sid=(int)$multiRows[0]['id'];[$g1,$g2]=$multiRows[0]['group_ids'];
    $guides=array_column($s['staff']->assignmentsForPlan($mid)[$sid],'id');$guide=(int)$guides[0];
    $db->pdo->prepare('INSERT IGNORE INTO roster_staff_module_preferences(staff_id,module_key,preference_rank) VALUES(?,?,7)')->execute([$left,$multiRows[0]['module_key']]);
    $s['staff']->addPlanSelection($mid,[$left]);
    $s['staff']->replaceSessionAssignments($sid,[$guide,$left],'manual');
    $s['staff']->replaceSessionGroupAssignments($sid,[$g1=>[$guide],$g2=>[$left]],'manual');
    $otherSessionBefore=$s['staff']->assignmentsForPlan($mid)[(int)$multiRows[1]['id']];
    $s['sessionService']->swap($mid,1,'staff',$sid,(int)$g1,$sid,(int)$g2,1);
    $groups=$s['staff']->groupAssignmentIdsForSession($sid);
    $assert($groups[$g1]===[$left]&&$groups[$g2]===[$guide],'Shared lesson group assignments not exchanged');
    $assert($otherSessionBefore===$s['staff']->assignmentsForPlan($mid)[(int)$multiRows[1]['id']],'Other lesson was changed');
    echo "PASS: atomic lesson/staff swaps, qualifications, availability, stale revision, foreign cells, published guard and exact rollback\n";
} finally {$db->drop();}

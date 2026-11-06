<?php
declare(strict_types=1);
require __DIR__.'/Support/RosterLifecycleFixtures.php';
use GeoFort\Booking\FourModuleProgram;
use GeoFort\Services\Dashboard\Roster\{RosterAutoGenerator,RosterStaffOptimizer,RosterJointOptimizationService,RosterPairService,RosterPlanException};
$db=DisposableBookingMariaDb::create('single_workspace');
try {
 $pdo=$db->pdo;RosterLifecycleFixtures::schema($pdo);$s=RosterLifecycleFixtures::services($pdo);$assert=RosterLifecycleFixtures::assert(...);
 $dg='Dynamische-Globe';$sdk='Stop-de-Klimaat-Klok';$ke='Klimaat-Experience';$vi='Voedsel-Innovatie';$vs='Klimparcours';
 $people=[];
 foreach([[$dg,$ke],[$sdk,$ke,$vi,$dg],[$sdk,$ke],[$dg,$sdk,$ke,$vi],[$vi,$sdk,$ke,$dg],[]] as $i=>$skills) {
  $pdo->prepare('INSERT INTO roster_staff_members(display_name,is_active,can_guide,can_cook,employment_type) VALUES (?,1,?,?,?)')->execute(['Efficiency '.chr(65+$i),$i===5?0:1,$i===5?1:0,$i===0?'volunteer':'paid']);
  $people[$i]=(int)$pdo->lastInsertId();
  foreach($skills as $rank=>$key)$pdo->prepare('INSERT INTO roster_staff_module_preferences(staff_id,module_key,preference_rank) VALUES (?,?,?)')->execute([$people[$i],$key,$rank+1]);
 }
 $plans=[];
 foreach([$ke,$sdk] as $i=>$choice) {
  $pdo->prepare("INSERT INTO aanvragen(status,schoolnaam,land,adres,postcode,plaats,school_telefoonnummer,contactpersoon_telefoonnummer,contactpersoon_voornaam,contactpersoon_achternaam,email,bezoekdatum,onderwijs_sector,programma,program_version,keuzemodule_key,aantal_leerlingen,aantal_begeleiders,voorwaarden_akkoord) VALUES ('Definitief',?,'Nederland','Testweg 1','1234 AB','Testplaats','0100000000','0612345678','Test','Planner','test@example.test','2027-01-12','primairOnderwijs','dag',?,?,44,4,1)")->execute(['Efficiency school '.($i+1),FourModuleProgram::VERSION,$choice]);
  $bid=(int)$pdo->lastInsertId();
  if($i===1)$pdo->prepare("UPDATE aanvragen SET onderwijs_sector='voortgezetOnderbouw' WHERE id=?")->execute([$bid]);
  $pdo->prepare("INSERT INTO aanvraag_onderwijs_selecties(aanvraag_id,sector_key,sector_label,level_key,level_label,level_position,group_key,group_label,group_position) VALUES (?,'primairOnderwijs','PO','regulier','Regulier',1,'groep8','Groep 8',1)")->execute([$bid]);
  if($i===1)$pdo->prepare("UPDATE aanvraag_onderwijs_selecties SET sector_key='voortgezetOnderbouw',sector_label='VO onderbouw',level_key='havo',level_label='Havo',group_key='leerjaar1',group_label='Leerjaar 1' WHERE aanvraag_id=?")->execute([$bid]);
  $plans[]=$s['service']->createFromBooking($bid,1)['plan'];
 }
 $plans=array_map(fn(array $p):array=>$s['service']->getPlan($p['id']),$plans);
 $layout=[
  [[$vi,0,[0,1],[4]],[$dg,0,[2],[0]],[$vi,1,[2],[4]],[$dg,1,[0,1],[1,3]],[$vs,2,[0,1],[]],[$ke,2,[2],[3]],[$vs,3,[2],[]],[$ke,3,[0,1],[3]]],
  [[$dg,0,[0],[3]],[$ke,0,[1],[]],[$sdk,0,[2],[2]],[$ke,1,[0,2],[0]],[$sdk,1,[1],[2]],[$vi,2,[0,1],[4]],[$dg,2,[2],[1]],[$vi,3,[2],[4]],[$dg,3,[1],[0]],[$sdk,3,[0],[1]]],
 ];
 $times=[['10:15','11:00'],['11:00','11:45'],['12:15','13:00'],['13:00','13:45']];
 foreach($plans as $i=>$p) {
  $gid=array_column($p['groups'],'id');$pid=$p['id'];
  $s['staff']->savePlanSettings($pid,'with_staff',true,$people[5]);
  $s['staff']->addPlanSelection($pid,$i===0?array_values(array_diff($people,[$people[2]])):$people);
  foreach($layout[$i] as [$module,$round,$groupIndexes,$staffIndexes]) {
   $session=$s['sessions']->insertActivity($pid,$module,...[...$times[$round],null,1]);
   $groups=array_map(fn(int $index):int=>$gid[$index],$groupIndexes);
   $staff=array_map(fn(int $index):int=>$people[$index],$staffIndexes);
   $s['sessions']->replaceGroups($session,$groups);$s['staff']->replaceSessionAssignments($session,$staff,'manual');
   $groupStaff=[];
   foreach($groups as $position=>$id) { $groupStaff[$id]=$staff===[]?[]:[$staff[count($staff)>1?$position:0]];$s['sessions']->setGroupSupervision($session,$id,$staff===[]?'school':'staff'); }
   $s['staff']->replaceSessionGroupAssignments($session,$groupStaff,'manual');
  }
 }


 $pdo->exec(file_get_contents(dirname(__DIR__,2).'/database/sql/2026-11-06_create_single_workspace_events.sql'));
 $pdo->exec(file_get_contents(dirname(__DIR__,2).'/database/sql/2026-11-06_create_joint_workspace_events.sql'));
 $ids=array_column($plans,'id');$id=$ids[0];$other=$ids[1];
 // Keep the second school as an external day constraint, initially on another date.
 $pdo->prepare("UPDATE roster_plans SET visit_date='2027-01-13' WHERE id=?")->execute([$other]);
 $workspace=new \GeoFort\Services\Dashboard\Roster\RosterJointWorkspaceService($pdo,true);
 $revisions=fn():array=>[$id=>(int)$s['raw']->load($id)['plan']['revision']];
 $snapshot=fn():string=>serialize(array_map(fn(int $id):array=>$s['raw']->load($id),$ids));
 $expect=function(callable $call,string $code)use($assert):void{try{$call();throw new RuntimeException('Expected '.$code);}catch(RosterPlanException $e){$assert($e->publicCode===$code,'Expected '.$code.', got '.$e->publicCode);}};
 $base=$snapshot();$loaded=$workspace->run($revisions(),'read',null,[],null,1);
 $assert($loaded['readiness']['canOpenJoint'] && count($loaded['state'])===1,'Single complete school not ready');
 $options=['teamMode'=>'existing','shiftLessons'=>false,'moduleSwaps'=>true,'timePins'=>[],'staffPins'=>[],'retained'=>[],'additions'=>[],'approvedStaff'=>[],'releasedStaff'=>[]];
 $preview=$workspace->run($revisions(),'preview',$loaded['state'],$options,null,1);
 $assert($preview['report']['canApply'],'Valid single plan refused: '.json_encode($preview['report']['errors']));
 $optimized=$workspace->run($revisions(),'optimize',$loaded['state'],$options,null,1);
 $assert($optimized['report']['canApply'],'Single optimizer invalid');
 $assert($snapshot()===$base,'Read, preview or optimize changed saved rosters');
 // Missing teachers can be saved as a concept, but cannot pass finalization.
 $draft=$loaded['state'];$target=1;
 $draft[0]['sessions'][$target]['groups'][0]['kind']='open';$draft[0]['sessions'][$target]['groups'][0]['staffIds']=[];
 $checked=$workspace->run($revisions(),'preview',$draft,$options,null,1);
 $assert($checked['report']['canApply']&&!$checked['report']['canFinalize'],'Open concept should apply but block finalization');
 $assert($snapshot()===$base,'Preview wrote data');
 // Stale catalog/another roster changes invalidate the exact reviewed payload.
 $pdo->prepare('UPDATE roster_plans SET revision=revision+1 WHERE id=?')->execute([$other]);
 $pdo->prepare("UPDATE roster_plans SET visit_date='2027-01-12' WHERE id=?")->execute([$other]);
 $expect(fn()=>$workspace->run($revisions(),'apply',$draft,$options,$checked['signature'],1),'STALE_JOINT_DRAFT');
 $assert($s['raw']->load($id)['plan']['revision']===$plans[0]['revision'],'Stale preview incremented revision');
 $pdo->prepare("UPDATE roster_plans SET visit_date='2027-01-13' WHERE id=?")->execute([$other]);
 $checked=$workspace->run($revisions(),'preview',$draft,$options,null,1);

 // A failed history write rolls back lesson assignments and revision together.
 $beforeFailure=$snapshot();
 $pdo->exec("CREATE TRIGGER fail_single_history BEFORE INSERT ON roster_single_workspace_events FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='forced single history failure'");
 try{$workspace->run($revisions(),'apply',$draft,$options,$checked['signature'],1);throw new RuntimeException('Expected history failure');}
 catch(PDOException $e){$assert(str_contains($e->getMessage(),'forced single history failure'),'Unexpected history failure');}
 $assert($snapshot()===$beforeFailure,'History failure partially saved the single roster');
 $assert((int)$pdo->query('SELECT COUNT(*) FROM roster_single_workspace_events')->fetchColumn()===0,'Failed write left history');
 $pdo->exec('DROP TRIGGER fail_single_history');
 $otherBefore=serialize($s['raw']->load($other));$oldRevision=$revisions();
 $workspace->run($oldRevision,'apply',$draft,$options,$checked['signature'],1);
 $assert(serialize($s['raw']->load($other))===$otherBefore,'Single apply changed external school');
 $assert((int)$pdo->query('SELECT COUNT(*) FROM roster_single_workspace_events')->fetchColumn()===1,'Missing single audit');
 $expect(fn()=>$workspace->run($oldRevision,'apply',$draft,$options,$checked['signature'],1),'STALE_REVISION');
 $saved=$workspace->run($revisions(),'read',null,[],null,1);
 $assert($saved['state']===$draft,'Applied state differs from reviewed state');
 // Restore the valid fixture through the exact same preview/apply path.
 $restore=$workspace->run($revisions(),'preview',$loaded['state'],$options,null,1);
 $workspace->run($revisions(),'apply',$loaded['state'],$options,$restore['signature'],1);

 // A finalized single roster can be read, but all editing/approval requests are rejected.
 $s['lifecycle']->finalize($id,$revisions()[$id],1);
 $frozen=serialize($s['snapshots']->state($id));$publishedBefore=$snapshot();
 $publishedLoad=$workspace->run($revisions(),'read',null,[],null,1);
 foreach(['preview','optimize','candidates','apply'] as $mode)$expect(fn()=>$workspace->run($revisions(),$mode,$publishedLoad['state'],$options,null,1),'PLAN_NOT_EDITABLE');
 $assert($snapshot()===$publishedBefore && serialize($s['snapshots']->state($id))===$frozen,'Published single changed');
 $s['lifecycle']->reopen($id,$revisions()[$id],1);
 $approval=$options;$approval['approvedStaff']=[['id'=>$people[4],'schedule'=>\GeoFort\Services\Dashboard\Roster\RosterJointStaffApproval::signature($loaded['state'],$people[4])]];
 $approved=$workspace->run($revisions(),'preview',$loaded['state'],$approval,null,1);
 $workspace->run($revisions(),'apply',$loaded['state'],$approval,$approved['signature'],1);
 $assert($workspace->run($revisions(),'read',null,[],null,1)['options']['approvedStaff']===$approval['approvedStaff'],'Approval not persisted');
 $expect(fn()=>$workspace->run($revisions(),'preview',$loaded['state'],$options,null,1),'STAFF_SCHEDULE_APPROVED');
 $moved=$loaded['state'];$moved[0]['sessions'][0]['groups'][0]['kind']='open';$moved[0]['sessions'][0]['groups'][0]['staffIds']=[];
 $expect(fn()=>$workspace->run($revisions(),'preview',$moved,$approval,null,1),'STAFF_SCHEDULE_APPROVED');
 $expect(fn()=>$workspace->run($revisions(),'preview',$loaded['state'],[...$approval,'shiftLessons'=>true],null,1),'LESSON_SLOTS_LOCKED');
 // Linking cannot silently drop staff approvals; after explicit release it preserves lessons.
 $pdo->prepare("UPDATE roster_plans SET visit_date='2027-01-12' WHERE id=?")->execute([$other]);
 $allRevs=fn():array=>array_column(array_map(fn(int $pid):array=>$s['service']->getPlan($pid),$ids),'revision','id');
 $pairService=new RosterPairService($pdo,$s['service']);
 $expect(fn()=>$pairService->link($allRevs(),'lessons_first',1,false,true),'SINGLE_APPROVALS_PRESENT');
 $release=[...$options,'releasedStaff'=>[$people[4]]];
 // Move the unrelated fixture away only while saving the release of the approval.
 $pdo->prepare("UPDATE roster_plans SET visit_date='2027-01-13' WHERE id=?")->execute([$other]);
 $r=$workspace->run($revisions(),'preview',$loaded['state'],$release,null,1);
 $workspace->run($revisions(),'apply',$loaded['state'],$release,$r['signature'],1);
 $pdo->prepare("UPDATE roster_plans SET visit_date='2027-01-12' WHERE id=?")->execute([$other]);
 $beforeLink=$snapshot();$pairService->link($allRevs(),'lessons_first',1,false,true);
 $assert($snapshot()===$beforeLink,'Preserve-link changed rosters');
 foreach(['read','preview','candidates','optimize','apply'] as $mode)$expect(fn()=>$workspace->run($revisions(),$mode,$loaded['state'],$options,$r['signature'],1),'JOINT_WORKSPACE_REQUIRED');
 $joint=new \GeoFort\Services\Dashboard\Roster\RosterJointWorkspaceService($pdo);
 $jointLoad=$joint->run($allRevs(),'read',null,[],null,1);
 $assert(count($jointLoad['state'])===2 && $jointLoad['readiness']['canOpenJoint'],'Joint workspace not available after linking');
 $assert($snapshot()===$beforeLink,'Single rejection or joint read wrote data');
 // Parsing and action-specific CSRF protect the new endpoint independently.
 $body=json_encode(['plans'=>[['id'=>$id,'revision'=>$revisions()[$id]]],'mode'=>'read']);
 $request=\GeoFort\Services\Http\Api\Admin\DashboardRosterJointWorkspaceRequest::fromBody($body,true);
 $assert(count($request->expectedRevisions)===1,'Single payload parsing');
 try{\GeoFort\Services\Http\Api\Admin\DashboardRosterJointWorkspaceRequest::fromBody($body);throw new RuntimeException('Single accepted by joint endpoint');}catch(JsonException){}
 $pairBody=json_encode(['plans'=>array_map(fn($pid)=>['id'=>$pid,'revision'=>$allRevs()[$pid]],$ids),'mode'=>'read']);
 try{\GeoFort\Services\Http\Api\Admin\DashboardRosterJointWorkspaceRequest::fromBody($pairBody,true);throw new RuntimeException('Pair accepted by single endpoint');}catch(JsonException){}
 ini_set('session.save_path',sys_get_temp_dir());
 $auth=new \GeoFort\Security\AuthMiddleware('geofort_single_workspace_test','Lax',false);$auth->startPublicSession();
 $user=$pdo->query('SELECT id,email,name,role FROM admin_users WHERE id=1')->fetch();$auth->establishAuthenticatedSession($user,null,'single-test');
 $csrf=new \GeoFort\Services\Auth\CsrfTokenService();$token=$csrf->getOrCreate('roster-single-workspace');
 $response=new \GeoFort\Services\Http\Response\JsonResponse(new \GeoFort\Services\Http\Url\EnvironmentBaseUrlProvider('development','https://onderwijsformulier.test'));
 $action=new \GeoFort\Services\Http\Api\Admin\DashboardRosterSingleWorkspaceAction($auth,new \GeoFort\Security\SessionGuard(new \GeoFort\Services\Sql\AdminUsersSqlService($pdo,10),1800,300),$csrf,$workspace,$response);
 $send=function(string $method,?string $token,string $body)use($action):array{ob_start();try{$action->send($method,'application/json',$token,$body,'single-test');$out=(string)ob_get_contents();}finally{ob_end_clean();}return[http_response_code(),json_decode($out,true,512,JSON_THROW_ON_ERROR)];};
 [$status,$result]=$send('POST',$csrf->getOrCreate('roster-joint-workspace'),$body);$assert($status===403,'Joint CSRF authorized single action');
 [$status,$result]=$send('GET',$token,$body);$assert($status===405,'GET accepted');
 [$status,$result]=$send('POST',$token,$pairBody);$assert($status===422,'Two schools accepted by single endpoint');
 unset($_SESSION['user_id']);[$status,$result]=$send('POST',$token,$body);$assert($status===401,'Unauthenticated access');
 if(session_status()===PHP_SESSION_ACTIVE)session_destroy();
 echo "PASS single workspace: side-effect-free reads/previews/search, exact apply, external/stale protection, concept/finalization rules, approvals/release/audit, paired redirect guard, preserving link, separate CSRF and payload scope\n";
}finally{$db->drop();}

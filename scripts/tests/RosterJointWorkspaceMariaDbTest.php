<?php
declare(strict_types=1);
require __DIR__.'/Support/RosterLifecycleFixtures.php';
use GeoFort\Booking\FourModuleProgram;
use GeoFort\Services\Dashboard\Roster\{RosterAutoGenerator,RosterStaffOptimizer,RosterJointOptimizationService,RosterPairService,RosterPlanException};
$db=DisposableBookingMariaDb::create('joint_workspace');
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

 $pdo->exec(file_get_contents(dirname(__DIR__,2).'/database/sql/2026-11-06_create_joint_workspace_events.sql'));
 $rev=array_column($plans,'revision','id');$ids=array_keys($rev);

 // Linking existing school lessons must not regenerate, clear, or increment them.
 $beforeLink=serialize(array_map(fn(int $id):array=>$s['raw']->load($id),$ids));
 (new RosterPairService($pdo,$s['service']))->link($rev,'lessons_first',1,false,true);
 $assert(serialize(array_map(fn(int $id):array=>$s['raw']->load($id),$ids))===$beforeLink,'Preserve-link changed existing lessons or selections');
 $workspace=new \GeoFort\Services\Dashboard\Roster\RosterJointWorkspaceService($pdo);
 $snapshot=fn():string=>serialize(array_map(fn(int $id):array=>$s['raw']->load($id),$ids));$before=$snapshot();
 $loaded=$workspace->run($rev,'read',null,[],null,1);
 $assert($loaded['readiness']['canOpenJoint'],'Complete saved lessons with school KE must open');
 $assert(!$loaded['readiness']['canFinalize'],'Four-module KE exception must block finalization');
 $options=['teamMode'=>'existing','shiftLessons'=>false,'timePins'=>[],'staffPins'=>[],'retained'=>[],'additions'=>[]];
 $proposal=$workspace->run($rev,'optimize',$loaded['state'],$options,null,1);
 $assert($proposal['report']['canApply'],'Optimized proposal invalid: '.json_encode($proposal['report']['errors']));
 $assert($proposal['search']['after']['unfilledAssignments']===0,'Mandatory coverage lost');
 $assert($proposal['search']['after']['keSchoolAssignments']===0,'Required KE not repaired');
 $assert($snapshot()===$before,'Read/preview/search changed saved data');
 $same=$workspace->run($rev,'preview',$proposal['state'],$options,null,1);
 $assert($same['signature']===$proposal['signature'],'Apply fingerprint differs from exact optimized state');
 $bad=$proposal['state'];$bad[0]['sessions'][0]['groups'][0]['staffIds']=[$people[5]];$bad[0]['sessions'][0]['groups'][0]['kind']='staff';
 $invalid=$workspace->run($rev,'preview',$bad,$options,null,1);
 $assert(!$invalid['report']['canApply'],'Cook-only person accepted as guide');
 try{$workspace->run($rev,'apply',$bad,$options,$invalid['signature'],1);throw new RuntimeException('Bad proposal saved');}catch(RosterPlanException $e){$assert($e->publicCode==='JOINT_DRAFT_INVALID','Unexpected invalid rejection');}
 $assert($snapshot()===$before,'Failed validation changed either school');
 $moved=$loaded['state'];$moved[0]['sessions'][0]['start']='13:00';$moved[0]['sessions'][0]['end']='13:45';
 try{$workspace->run($rev,'preview',$moved,$options,null,1);throw new RuntimeException('Moved locked lesson');}catch(RosterPlanException $e){$assert($e->publicCode==='LESSON_LOCKED','Unexpected lesson lock error');}
 $altered=$options;$altered['retained']=[$people[0]];
 try{$workspace->run($rev,'apply',$proposal['state'],$altered,$proposal['signature'],1);throw new RuntimeException('Changed options accepted');}catch(RosterPlanException $e){$assert($e->publicCode==='STALE_JOINT_DRAFT','Changed options not rejected');}

 // Pins and retained people are enforced by the server, not only disabled controls.
 $pinOptions=$options;$target=$loaded['state'][0]['sessions'][0];$gid=$target['groups'][0]['id'];
 $pinOptions['staffPins']=[$ids[0].':'.$target['id'].':'.$gid];
 try{$workspace->run($rev,'preview',$bad,$pinOptions,null,1);throw new RuntimeException('Assignment pin ignored');}catch(RosterPlanException $e){$assert($e->publicCode==='STAFF_LOCKED','Unexpected pin failure');}
 $pinOptions['retained']=[$people[2]];
 $pinned=$workspace->run($rev,'optimize',$loaded['state'],$pinOptions,null,1);
 $pinnedGroup=$pinned['state'][0]['sessions'][0]['groups'][0];
 $assert($pinnedGroup===$target['groups'][0],'Optimizer changed pinned group');
 $assert(count(array_filter($pinned['search']['after']['staff'],fn($r)=>$r['staffId']===$people[2] && $r['workMinutes']>0))===1,'Retained employee has no actual assignment');
 // Cross-school swap is compared as one candidate, then validated against both roles.
 $candidates=$workspace->run($rev,'candidates',$loaded['state'],[...$options,'target'=>['planId'=>$ids[0],'sessionId'=>$target['id'],'groupId'=>$gid,'cook'=>false]],null,1);
 $cookCandidate=array_values(array_filter($candidates['candidates'],fn($r)=>$r['id']===$people[5]))[0];
 $assert(!$cookCandidate['qualified'] && $cookCandidate['reasons']!==[],'Cook-only must explain guide ineligibility');


 $shift=$options;$shift['shiftLessons']=true;
 try{$workspace->run($rev,'preview',$moved,$shift,null,1);throw new RuntimeException('VI moved without permission');}catch(RosterPlanException $e){$assert($e->publicCode==='VI_LOCKED','VI permission guard missing');}
 $shift['timePins']=[$ids[0].':'.$target['id']];
 try{$workspace->run($rev,'preview',$moved,$shift,null,1);throw new RuntimeException('Pinned lesson moved');}catch(RosterPlanException $e){$assert($e->publicCode==='LESSON_LOCKED','Time pin ignored');}
 // A catalog update invalidates a reviewed draft even without a roster revision.
 $pdo->exec('UPDATE roster_staff_catalog_state SET revision=revision+1 WHERE id=1');
 try{$workspace->run($rev,'apply',$proposal['state'],$options,$proposal['signature'],1);throw new RuntimeException('Changed catalog accepted');}catch(RosterPlanException $e){$assert($e->publicCode==='STALE_JOINT_DRAFT','Catalog signature missing');}
 $assert($snapshot()===$before,'Catalog conflict changed rosters');
 $proposal=$workspace->run($rev,'preview',$proposal['state'],$options,null,1);
 // Reducing the selected team never silently reintroduces an excluded employee.
 $five=$options;$five['teamMode']='selection';$five['teamIds']=array_values(array_diff($people,[$people[2]]));
 $fiveResult=$workspace->run($rev,'optimize',$loaded['state'],$five,null,1);
 $assert($fiveResult['report']['canApply'],'Five-person concept not available');
 $assert(!$fiveResult['report']['canFinalize'],'Five-person KE exception wrongly finalizable');
 $assert(!array_filter($fiveResult['search']['after']['staff'],fn($r)=>$r['staffId']===$people[2] && $r['workMinutes']>0),'Excluded employee reintroduced');
 $second=$ids[1];
 $pdo->exec("CREATE TRIGGER fail_second_joint_workspace BEFORE UPDATE ON roster_plans FOR EACH ROW BEGIN IF NEW.id=$second AND NEW.revision>OLD.revision THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='forced second school failure'; END IF; END");
 try{$workspace->run($rev,'apply',$proposal['state'],$options,$proposal['signature'],1);throw new RuntimeException('Expected rollback');}catch(Throwable $e){$assert(str_contains($e->getMessage(),'forced')||$e->getPrevious() instanceof PDOException,'Unexpected failure: '.$e->getMessage());}
 $assert($snapshot()===$before,'Second-school failure did not roll back first school');
 $assert((int)$pdo->query('SELECT COUNT(*) FROM roster_joint_workspace_events')->fetchColumn()===0,'Failed apply left audit');
 $pdo->exec('DROP TRIGGER fail_second_joint_workspace');
 $workspace->run($rev,'apply',$proposal['state'],$options,$proposal['signature'],1);
 $assert((int)$pdo->query('SELECT COUNT(*) FROM roster_joint_workspace_events')->fetchColumn()===1,'Missing audit');
 $now=array_map(fn(int $id):array=>$s['service']->getPlan($id),$ids);
 $newRev=array_column($now,'revision','id');
 $saved=$workspace->run($newRev,'read',null,[],null,1);
 $assert($saved['state']===$proposal['state'],'Saved proposal differs from exact reviewed state');
 try{$workspace->run($rev,'apply',$proposal['state'],$options,$proposal['signature'],1);throw new RuntimeException('Duplicate apply accepted');}catch(RosterPlanException $e){$assert($e->publicCode==='STALE_REVISION','Duplicate apply should be stale');}

 // Freeze both snapshots; explicitly reopen only one school and preserve the other.
 $control=$s['lifecycle']->joint($newRev,'preview',null,false,1);
 $assert($control['canFinalize'],'Optimized pair should finalize');
 $s['lifecycle']->joint($newRev,'finalize',$control['signature'],true,1);
 $published=array_map(fn(int $id):array=>$s['service']->getPlan($id),$ids);$publishedRev=array_column($published,'revision','id');
 $frozen=serialize($s['snapshots']->state($ids[0]));
 $s['lifecycle']->joint($publishedRev,'reopen',null,false,1,$ids[1]);
 $mixed=array_map(fn(int $id):array=>$s['service']->getPlan($id),$ids);$mixedRev=array_column($mixed,'revision','id');
 $assert($mixed[0]['status']==='published' && $mixed[1]['status']==='concept','Targeted reopening changed both schools');
 try{$s['lifecycle']->joint($mixedRev,'reopen',null,false,1,$ids[1]);throw new RuntimeException('Reopened concept');}catch(RosterPlanException $e){$assert($e->publicCode==='PLAN_NOT_PUBLISHED','Wrong concept reopen guard');}
 $mixedLoad=$workspace->run($mixedRev,'read',null,[],null,1);
 $assert($mixedLoad['readiness']['canOpenJoint'],'Mixed pair should be available');
 $mixedOptions=$options;$mixedOptions['shiftLessons']=true;
 $mixedResult=$workspace->run($mixedRev,'optimize',$mixedLoad['state'],$mixedOptions,null,1);
 $assert($mixedResult['state'][0]===$mixedLoad['state'][0],'Optimizer moved published school');
 $tampered=$mixedLoad['state'];$tampered[0]['sessions'][0]['groups'][0]['staffIds']=[$people[1]];
 try{$workspace->run($mixedRev,'preview',$tampered,$options,null,1);throw new RuntimeException('Published mutation accepted');}catch(RosterPlanException $e){$assert($e->publicCode==='PLAN_NOT_EDITABLE','Wrong published guard');}
 $assert(serialize($s['snapshots']->state($ids[0]))===$frozen,'Frozen snapshot history changed');
 $mixedControl=$s['lifecycle']->joint($mixedRev,'preview',null,false,1);
 $assert($mixedControl['canFinalize'],'Mixed concept should finalize around frozen school');
 $s['lifecycle']->joint($mixedRev,'finalize',$mixedControl['signature'],true,1);
 $assert(serialize($s['snapshots']->state($ids[0]))===$frozen,'Mixed finalize replaced frozen snapshot');
 $allPublished=array_map(fn(int $id):array=>$s['service']->getPlan($id),$ids);
 $s['lifecycle']->joint(array_column($allPublished,'revision','id'),'reopen',null,false,1);
 $newRev=array_column(array_map(fn(int $id):array=>$s['service']->getPlan($id),$ids),'revision','id');

 // Approving a person's current draft locks their complete pair schedule, including cook roles and unused slots.
 $approvalState=$workspace->run($newRev,'read',null,[],null,1)['state'];
 $signatureOf=\GeoFort\Services\Dashboard\Roster\RosterJointStaffApproval::signature(...);
 $approvedOptions=[...$options,'moduleSwaps'=>true,'approvedStaff'=>array_map(fn($id)=>['id'=>$id,'schedule'=>$signatureOf($approvalState,$id)],[$people[4],$people[5]]),'releasedStaff'=>[]];
 $approvedPreview=$workspace->run($newRev,'preview',$approvalState,$approvedOptions,null,1);
 $assert($approvedPreview['report']['canApply'],'Current valid roster cannot be approved');
 $protected=$workspace->run($newRev,'optimize',$approvalState,$approvedOptions,null,1);
 foreach($approvedOptions['approvedStaff'] as $entry)$assert($signatureOf($protected['state'],$entry['id'])===$entry['schedule'],'Optimizer changed an approved complete schedule');
 foreach($protected['state'] as $pi=>$p)foreach($p['sessions'] as $si=>$session)$assert([$session['start'],$session['end']]===[$approvalState[$pi]['sessions'][$si]['start'],$approvalState[$pi]['sessions'][$si]['end']],'Fixed-slot optimizer moved a lesson');
 $rejectApproval=function(array $draft,array $settings)use($workspace,&$newRev,$assert){
  try{$workspace->run($newRev,'preview',$draft,$settings,null,1);throw new RuntimeException('Approved schedule mutation accepted');}
  catch(RosterPlanException $e){$assert($e->publicCode==='STAFF_SCHEDULE_APPROVED','Wrong approval rejection: '.$e->publicCode);}
 };
 $removed=$approvalState;
 foreach($removed as &$p)foreach($p['sessions'] as &$session)foreach($session['groups'] as &$g)if(in_array($people[4],$g['staffIds'],true)){$g['staffIds']=[];$g['kind']='open';}unset($p,$session,$g);
 $rejectApproval($removed,$approvedOptions);
 $added=$approvalState;$found=false;
 foreach($added as &$p)foreach($p['sessions'] as &$session)foreach($session['groups'] as &$g)if(!$found&&!in_array($people[4],$g['staffIds'],true)){$g['staffIds']=[$people[4]];$g['kind']='staff';$found=true;}unset($p,$session,$g);
 $rejectApproval($added,$approvedOptions);
 $cookRemoved=$approvalState;
 foreach($cookRemoved as &$p)foreach($p['sessions'] as &$session)if($session['cook']===$people[5])$session['cook']=0;unset($p,$session);
 $rejectApproval($cookRemoved,$approvedOptions);
 $shiftApproved=$approvalState;
 foreach($shiftApproved as &$p)foreach($p['sessions'] as &$session)if($session['cook']===$people[5]){$session['start']='15:00';$session['end']='15:45';}unset($p,$session);
 try{$workspace->run($newRev,'preview',$shiftApproved,$approvedOptions,null,1);throw new RuntimeException('Locked VI time changed');}catch(RosterPlanException $e){$assert($e->publicCode==='VI_LOCKED','VI time should fail before approval validation');}
 try{\GeoFort\Services\Dashboard\Roster\RosterJointStaffApproval::check($shiftApproved,$approvedOptions);throw new RuntimeException('Approved time changed');}catch(RosterPlanException $e){$assert($e->publicCode==='STAFF_SCHEDULE_APPROVED','Approval does not include times');}
 $workspace->run($newRev,'apply',$approvalState,$approvedOptions,$approvedPreview['signature'],1);
 $newRev=array_column(array_map(fn(int $id):array=>$s['service']->getPlan($id),$ids),'revision','id');
 $approvalLoaded=$workspace->run($newRev,'read',null,[],null,1);
 $assert($approvalLoaded['state']===$approvalState,'Approval-only save altered lesson assignments');
 $assert($approvalLoaded['options']['approvedStaff']===$approvedOptions['approvedStaff'],'Approved schedule not persisted');
 $rejectApproval($approvalState,$options); // Removing saved locks requires an explicit release.
 $forged=$approvedOptions;$forged['approvedStaff'][0]['schedule']=$signatureOf($removed,$people[4]);
 $rejectApproval($removed,$forged);
 $released=[...$options,'moduleSwaps'=>true,'approvedStaff'=>[],'releasedStaff'=>[$people[4],$people[5]]];
 $releasePreview=$workspace->run($newRev,'preview',$approvalState,$released,null,1);
 $workspace->run($newRev,'apply',$approvalState,$released,$releasePreview['signature'],1);
 $newRev=array_column(array_map(fn(int $id):array=>$s['service']->getPlan($id),$ids),'revision','id');
 $afterRelease=$workspace->run($newRev,'preview',$removed,$released,null,1);
 $assert($afterRelease['report']['canApply'],'Explicitly released guide cannot be removed in concept');
 // Module swaps keep each group's existing time grid; arbitrary time edits are rejected.
 $slots=$approvalState;$left=$right=null;
 foreach($slots[1]['sessions'] as $i=>$a)foreach($slots[1]['sessions'] as $j=>$b)if($i<$j&&$a['cook']===0&&$b['cook']===0&&array_column($a['groups'],'id')===array_column($b['groups'],'id')&&$a['start']!==$b['start']){$left=$i;$right=$j;break 2;}
 $assert($left!==null,'Missing module swap fixture');
 [$slots[1]['sessions'][$left]['start'],$slots[1]['sessions'][$right]['start']]=[$slots[1]['sessions'][$right]['start'],$slots[1]['sessions'][$left]['start']];
 [$slots[1]['sessions'][$left]['end'],$slots[1]['sessions'][$right]['end']]=[$slots[1]['sessions'][$right]['end'],$slots[1]['sessions'][$left]['end']];
 $workspace->run($newRev,'preview',$slots,$released,null,1);
 $slots[1]['sessions'][$left]['start']='15:00';$slots[1]['sessions'][$left]['end']='15:45';
 try{$workspace->run($newRev,'preview',$slots,$released,null,1);throw new RuntimeException('Arbitrary slot accepted');}catch(RosterPlanException $e){$assert($e->publicCode==='LESSON_SLOTS_LOCKED','Wrong fixed-slot rejection');}

 $pdo->prepare('DELETE FROM roster_session_groups WHERE roster_session_id=? LIMIT 1')->execute([$loaded['state'][0]['sessions'][0]['id']]);
 $incomplete=$workspace->run($newRev,'read',null,[],null,1);
 $assert(!$incomplete['readiness']['canOpenJoint'],'Partial lesson plan incorrectly ready');

 // Real HTTP controller: authentication, scoped CSRF and malformed requests must
 // stop before any draft mutation. This isolated session is never the user's.
 ini_set('session.save_path',sys_get_temp_dir());
 $auth=new \GeoFort\Security\AuthMiddleware('geofort_joint_workspace_test','Lax',false);
 $auth->startPublicSession();
 $user=$pdo->query('SELECT id,email,name,role FROM admin_users WHERE id=1')->fetch();
 $auth->establishAuthenticatedSession($user,null,'workspace-test-agent');
 $csrf=new \GeoFort\Services\Auth\CsrfTokenService();
 $scope=\GeoFort\Services\Http\Api\Admin\DashboardRosterJointWorkspaceAction::CSRF_SCOPE;
 $csrfToken=$csrf->getOrCreate($scope);
 $response=new \GeoFort\Services\Http\Response\JsonResponse(new \GeoFort\Services\Http\Url\EnvironmentBaseUrlProvider('development','https://onderwijsformulier.test'));
 $action=new \GeoFort\Services\Http\Api\Admin\DashboardRosterJointWorkspaceAction($auth,new \GeoFort\Security\SessionGuard(new \GeoFort\Services\Sql\AdminUsersSqlService($pdo,10),1800,300),$csrf,$workspace,$response);
 $send=function(string $method,?string $token,string $body)use($action):array{
  ob_start();try{$action->send($method,'application/json',$token,$body,'workspace-test-agent');$output=(string)ob_get_contents();}finally{ob_end_clean();}
  return [http_response_code(),json_decode($output,true,512,JSON_THROW_ON_ERROR)];
 };
 $httpBefore=$snapshot();
 [$status,$payload]=$send('POST',$csrf->getOrCreate('logout'),'{}');$assert($status===403 && $payload['code']==='INVALID_CSRF','Unrelated CSRF authorized workspace');
 [$status,$payload]=$send('GET',$csrfToken,'{}');$assert($status===405,'GET accepted');
 foreach(['{bad',json_encode(['plans'=>[['id'=>$ids[0],'revision'=>$newRev[$ids[0]]],['id'=>$ids[1],'revision'=>$newRev[$ids[1]]]],'mode'=>'optimize','options'=>['teamIds'=>'all']])] as $body){
  [$status,$payload]=$send('POST',$csrfToken,$body);$assert($status===422 && $payload['code']==='INVALID_REQUEST','Malformed request not rejected');
 }
 unset($_SESSION['user_id']);[$status,$payload]=$send('POST',$csrfToken,'{}');$assert($status===401 && $payload['code']==='UNAUTHENTICATED','Unauthenticated request accepted');
 $assert($snapshot()===$httpBefore,'HTTP rejection mutated a roster');
 if(session_status()===PHP_SESSION_ACTIVE)session_destroy();
 echo "PASS joint workspace: readiness, hard KE priority, exact preview/apply, roles, fixed times, stale changes, atomic rollback, audit, duplicate apply, incomplete plan, full-person approvals (guide/cook/free time), persistence/release, fixed-slot module swaps\n";
}finally{$db->drop();}

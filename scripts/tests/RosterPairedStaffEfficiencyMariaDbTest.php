<?php
declare(strict_types=1);
require __DIR__.'/Support/RosterLifecycleFixtures.php';
use GeoFort\Booking\FourModuleProgram;
use GeoFort\Services\Dashboard\Roster\{RosterAutoGenerator,RosterStaffOptimizer,RosterJointOptimizationService,RosterPairService,RosterPlanException};
$db=DisposableBookingMariaDb::create('paired_staff_efficiency');
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
 (new RosterPairService($pdo,$s['service']))->link(array_fill_keys(array_column($plans,'id'),1),'lessons_first',1,true);
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
 $rev=array_column($plans,'revision','id');$ids=array_keys($rev);
 $control=$s['validator']->validatePlans($rev);$assert(!$control['canFinalize'] && in_array('FOUR_MODULE_KE_SUPERVISION_REQUIRED',array_column($control['plans'][$ids[1]]['errors'],'code'),true),'Original four-module KE School must block finalization');
 $snapshot=fn():string=>serialize(array_map(fn(int $id):array=>$s['raw']->load($id),$ids));$before=$snapshot();
 $generator=new RosterAutoGenerator($pdo,$s['bookings'],$s['plans'],$s['sessions'],$s['config'],$s['templates'],$s['staff'],new RosterStaffOptimizer($s['staff']));
 $five=array_values(array_diff($people,[$people[2],$people[5]]));
 $preview=$generator->preview($ids[1],[],$five,cookStaffId:$people[5],useExistingLessons:true,optimizeExistingLessons:true);
 $assert(!$preview['canFinalize'] && $preview['staffing']['unfilledRequiredAssignments']>=1,'Fixed first-school assignments must expose the specialist bottleneck');
 $assert($snapshot()===$before,'Wizard preview modified the first school');
 $opt=new RosterJointOptimizationService($pdo);$proposal=$opt->run($rev,null,1);
 $assert($proposal['before']['people']===6 && $proposal['after']['people']===6,'Six people are required to keep KE staffed in this lesson layout');
 $assert($proposal['after']['unfilledAssignments']===0 && $proposal['after']['paidGapMinutes']===0,'Hard coverage and paid gaps');
 $assert($proposal['after']['keSchoolAssignments']===0 && $proposal['finalizationIssues']===[],'KE coverage must precede headcount savings');
 $assert(count(array_filter($proposal['changes'],fn(array $c):bool=>$c['from']==='Schoolbegeleiding'))===1,'Existing KE school exception must be repaired explicitly');
 $assert($snapshot()===$before,'Joint preview wrote data');
 $second=$ids[1];
 $pdo->exec("CREATE TRIGGER fail_second_efficiency BEFORE UPDATE ON roster_plans FOR EACH ROW BEGIN IF NEW.id=$second AND NEW.revision>OLD.revision THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='second school failure'; END IF; END");
 try {$opt->run($rev,$proposal['signature'],1);throw new RuntimeException('Expected rollback');}catch(RuntimeException $e){$assert($e->getPrevious() instanceof PDOException && $e->getPrevious()->getCode()==='45000','Unexpected failure');}
 $assert($snapshot()===$before,'Second-school failure did not roll back the whole proposal');
 $pdo->exec('DROP TRIGGER fail_second_efficiency');
 // Remove the short-shift employee only in this disposable database: two open SDK roles.
 $pdo->prepare('DELETE FROM roster_session_group_staff WHERE staff_id=?')->execute([$people[2]]);
 $pdo->prepare('DELETE FROM roster_session_staff WHERE staff_id=?')->execute([$people[2]]);
 $pdo->prepare('DELETE FROM roster_plan_staff WHERE staff_id=?')->execute([$people[2]]);
 try{$opt->run($rev,$proposal['signature'],1);throw new RuntimeException('Stale signature accepted');}catch(RosterPlanException $e){$assert($e->publicCode==='STALE_OPTIMIZATION','Stale proposal not rejected');}
 $proposal=$opt->run($rev,null,1);
 $assert($proposal['before']['unfilledAssignments']===2 && $proposal['after']['unfilledAssignments']===0,'Open required lessons not repaired');
 $assert($proposal['after']['people']===5,'Unselected employee reintroduced');
 $opt->run($rev,$proposal['signature'],1);
 $afterRev=array_map(static fn(int $revision):int=>$revision+1,$rev);$assert(!$s['validator']->validatePlans($afterRev)['canFinalize'],'Five-person KE exception incorrectly finalized');
 $assert($proposal['after']['keSchoolAssignments']>0 && $proposal['finalizationIssues']!==[],'Concept KE exception must be visible');
 $review=$s['lifecycle']->joint($afterRev,'preview',null,false,1);
 $assert(!$review['canFinalize'] && in_array('FOUR_MODULE_KE_SUPERVISION_REQUIRED',array_column($review['errors'],'code'),true),'Joint finalization preview missed KE');
 try{$s['lifecycle']->joint($afterRev,'finalize',$review['signature'],true,1);throw new RuntimeException('Accepted warnings bypassed KE');}catch(RosterPlanException $e){$assert($e->publicCode==='ROSTER_INVALID','Unexpected finalization rejection');}
 $export=tempnam(sys_get_temp_dir(),'ke-concept-');
 try {
  $week=(new \GeoFort\Services\Dashboard\Roster\RosterWeekExportService($pdo,$s['service']))->export('2027-W02');
  (new \GeoFort\Services\Dashboard\Roster\RosterXlsxWriter())->writeWeek($export,$week);
  $zip=new ZipArchive();$assert($zip->open($export)===true && str_contains($zip->getFromName('xl/worksheets/sheet3.xml'),'School'),'Concept Excel export failed');$zip->close();
 }finally{unlink($export);}
 foreach($ids as $id)$assert($s['service']->getPlan($id)['status']==='concept','Blocked finalization changed status');
 foreach($ids as $id) $assert($s['service']->getPlan($id)['revision']===$afterRev[$id],'Both revisions must advance together');
 echo "PASS: four-module KE staffing before headcount, concept exception and Excel retained, joint finalization blocked, preview immutability, stale rejection, atomic rollback and apply\n";
} finally {$db->drop();}

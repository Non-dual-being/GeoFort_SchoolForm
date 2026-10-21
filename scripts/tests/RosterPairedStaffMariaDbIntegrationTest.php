<?php
declare(strict_types=1);
require __DIR__.'/Support/RosterLifecycleFixtures.php';
use GeoFort\Services\Dashboard\Roster\{RosterStaffOptimizer,RosterAutoGenerator,RosterPlanException};
use GeoFort\Services\Sql\RosterPairSqlRepository;
$assert=RosterLifecycleFixtures::assert(...);
$db=DisposableBookingMariaDb::create('paired_staff');
try {
 RosterLifecycleFixtures::schema($db->pdo);$s=RosterLifecycleFixtures::services($db->pdo);
 $optimizer=new RosterStaffOptimizer($s['staff']);
 $makeStaff=function(string $name,array $modules,bool $cook=false)use($db):int {
  $db->pdo->prepare('INSERT INTO roster_staff_members(display_name,is_active,can_guide,can_cook) VALUES (?,1,1,?)')->execute([$name,(int)$cook]);$id=(int)$db->pdo->lastInsertId();
  foreach($modules as $key)$db->pdo->prepare('INSERT INTO roster_staff_module_preferences(staff_id,module_key,preference_rank) VALUES (?,?,1)')->execute([$id,$key]);return $id;
 };
 $keys=['Earth-Watch','Stop-de-Klimaat-Klok','Dynamische-Globe','Voedsel-Innovatie','Minecraft-Programmeren'];
 $old=$makeStaff('Already on day',$keys,true);$extra=$makeStaff('Extra guide',$keys);
 $task=['moduleKey'=>'Earth-Watch','startTime'=>'11:00','endTime'=>'11:45','minimumGeoFortStaff'=>1,'schoolSupervisionAllowed'=>false];
 $fixed=[['moduleKey'=>'Earth-Watch','startTime'=>'10:30','endTime'=>'11:15','staffIds'=>[$old],'cookStaffId'=>null]];
 $r=$optimizer->optimize([$task],[$old,$extra],true,null,[],$fixed);
 $assert($r['assignments'][0]===[$extra],'Partial overlap reused occupied guide');
 $fixed[0]['endTime']='11:00';
 $r=$optimizer->optimize([$task],[$extra,$old],true,null,[],$fixed);
 $assert($r['assignments'][0]===[$old],'Available existing guide not preferred at exact boundary');
 $fixed=[];
 foreach(array_slice($keys,0,3) as $i=>$key)$fixed[]=['moduleKey'=>$key,'startTime'=>'10:00','endTime'=>'10:30','staffIds'=>[$old],'cookStaffId'=>null];
 $fourth=[...$task,'moduleKey'=>'Voedsel-Innovatie'];
 $r=$optimizer->optimize([$fourth],[$old,$extra],true,null,[],$fixed);
 $assert($r['assignments'][0]===[$old],'Fourth day module should remain allowed');
 $fixed[]=['moduleKey'=>'Voedsel-Innovatie','startTime'=>'10:00','endTime'=>'10:30','staffIds'=>[$old],'cookStaffId'=>null];
 $r=$optimizer->optimize([[...$task,'moduleKey'=>'Minecraft-Programmeren']],[$old,$extra],true,null,[],$fixed);
 $assert($r['assignments'][0]===[$extra],'Fifth day module assigned');
 $fixed=[['moduleKey'=>'Voedsel-Innovatie','startTime'=>'10:30','endTime'=>'11:15','staffIds'=>[$old],'cookStaffId'=>$old]];
 $r=$optimizer->optimize([$task],[$old,$extra],true,null,[],$fixed);
 $assert($r['assignments'][0]===[$extra],'Busy companion cook assigned as guide');

 // Reproduce applying a second-school proposal with actual stored assignments.
 $a=RosterLifecycleFixtures::validPlan($db->pdo,$s,40,1,2,'2029-04-02');
 $b=$s['service']->createManual('Second school','Testplaats','2029-04-02','primairOnderwijs','dag',40,1,1)['plan'];
 $s['plans']->saveManualModuleKeys($b['id'],array_slice($keys,0,2));$s['plans']->saveManualRounds($b['id'],$s['templates']->defaultTimeline('dag',2));
 $db->pdo->beginTransaction();(new RosterPairSqlRepository($db->pdo))->create([$a['id'],$b['id']],'2029-04-02','lessons_first',1);$db->pdo->commit();
 $raw=$s['raw']->load($a['id']);$ids=array_values(array_unique([...array_column($raw['sessionStaff'],'staff_id'),$extra]));$ids=array_map('intval',$ids);
 $generator=new RosterAutoGenerator($db->pdo,$s['bookings'],$s['plans'],$s['sessions'],$s['config'],$s['templates'],$s['staff'],$optimizer);
 $rounds=$s['templates']->defaultRounds('dag',2);
 $preview=$generator->preview($b['id'],$rounds,$ids,'with_staff');
 $assert($preview['canApply'],'Valid second-school proposal cannot apply');
 $generator->apply($b['id'],1,$rounds,false,1,$ids,'with_staff',true,null,[],$preview['catalogRevision']);
 $assert($raw===$s['raw']->load($a['id']),'Applying second school changed companion');
 $report=$s['validator']->validatePlans([$a['id']=>1,$b['id']=>2]);
 $assert(array_filter($report['day']['errors'],fn(array $e):bool=>str_starts_with($e['code'],'STAFF_'))===[],'Saved proposal violates joint staff rules');
 // Repeat with a definitive companion: use the immutable staffing snapshot.
 $x=RosterLifecycleFixtures::validPlan($db->pdo,$s,40,1,2,'2029-04-03');$x=$s['lifecycle']->finalize($x['id'],1,1);
 $y=$s['service']->createManual('After final','Testplaats','2029-04-03','primairOnderwijs','dag',40,1,1)['plan'];
 $s['plans']->saveManualModuleKeys($y['id'],array_slice($keys,0,2));$s['plans']->saveManualRounds($y['id'],$s['templates']->defaultTimeline('dag',2));
 $db->pdo->beginTransaction();(new RosterPairSqlRepository($db->pdo))->create([$x['id'],$y['id']],'2029-04-03','lessons_first',1);$db->pdo->commit();
 $snapshot=$s['snapshots']->state($x['id'])['current'];$frozen=$snapshot['content_json'];
 $exportPlans=$s['service']->getJointExportPlans($y['id']);
 $assert($exportPlans[0]['status']==='published' && $exportPlans[0]['planning']===$s['service']->getPlan($x['id'])['planning'],'Export lost published snapshot');
 $p=$generator->preview($y['id'],$rounds,[$extra],'with_staff');
 $assert($p['companion']['status']==='published' && $p['companion']['occupancy']!==[],'Final companion omitted');
 $generator->apply($y['id'],1,$rounds,false,1,[$extra],'with_staff');
 $assert($frozen===$s['snapshots']->state($x['id'])['current']['content_json'],'Final snapshot mutated');
 // A selected cook busy during every possible VI slot must be rejected visibly before any write.
 $busy=RosterLifecycleFixtures::validPlan($db->pdo,$s,40,1,2,'2029-04-04');
 foreach($s['sessions']->findByPlanId($busy['id']) as $session)$s['staff']->replaceSessionAssignments($session['id'],[$old],'manual');
 $target=$s['service']->createManual('Cook conflict','Testplaats','2029-04-04','primairOnderwijs','dag',40,1,1)['plan'];
 $s['plans']->saveManualModuleKeys($target['id'],['Earth-Watch','Voedsel-Innovatie']);$s['plans']->saveManualRounds($target['id'],$s['templates']->defaultTimeline('dag',2));
 $db->pdo->beginTransaction();(new RosterPairSqlRepository($db->pdo))->create([$busy['id'],$target['id']],'2029-04-04','lessons_first',1);$db->pdo->commit();
 $blocked=$generator->preview($target['id'],$rounds,[$extra],'with_staff',true,$old);
 $assert(!$blocked['canApply'] && in_array('STAFF_DAY_OVERLAP',array_column($blocked['hardIssues'],'code'),true),'Busy cook proposal falsely offered as applicable');
 $untouched=$s['raw']->load($target['id']);
 try{$generator->apply($target['id'],1,$rounds,false,1,[$extra],'with_staff',true,$old);throw new RuntimeException('Busy cook saved');}
 catch(RosterPlanException $e){$assert($e->publicCode==='PAIRED_STAFF_CONFLICT','Wrong busy cook failure');}
 $assert($untouched===$s['raw']->load($target['id']),'Rejected cook proposal modified roster');
 echo "PASS paired personnel: partial overlap, free boundary, existing guide priority, three-module day limit, cook occupancy, real preview/apply, fixed concept and final snapshots\n";
} finally { $db->drop(); }

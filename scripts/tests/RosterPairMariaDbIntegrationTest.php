<?php
declare(strict_types=1);
require __DIR__.'/Support/RosterLifecycleFixtures.php';
use GeoFort\Services\Dashboard\Roster\{RosterPairService,RosterPlanException};
use GeoFort\Services\Sql\RosterPairSqlRepository;
use GeoFort\Services\Http\Api\Admin\DashboardRosterPairRequest;
$assert=RosterLifecycleFixtures::assert(...);
$reject=function(callable $call,string $code)use($assert):void {
 try{$call();throw new LogicException('Expected '.$code);}catch(RosterPlanException $e){$assert($e->publicCode===$code,'Got '.$e->publicCode.' expected '.$code);}
};
foreach([false,null,'true',1]as$value){
 $p=['plans'=>[['id'=>1,'revision'=>1],['id'=>2,'revision'=>1]],'workflow'=>'lessons_first','confirmRestart'=>$value];
 try{DashboardRosterPairRequest::fromBody(json_encode($p),false);throw new LogicException('Confirmation bypassed');}catch(JsonException){}
}
$db=DisposableBookingMariaDb::create('roster_pair');
try{
 RosterLifecycleFixtures::schema($db->pdo);$s=RosterLifecycleFixtures::services($db->pdo);$service=new RosterPairService($db->pdo,$s['service']);
 $insert=$db->pdo->prepare("INSERT INTO aanvragen(status,schoolnaam,land,adres,postcode,plaats,school_telefoonnummer,contactpersoon_telefoonnummer,contactpersoon_voornaam,contactpersoon_achternaam,email,bezoekdatum,onderwijs_sector,programma,aantal_leerlingen,keuzemodule_key) VALUES ('In optie',?,'NL','Test','1234AB','Testplaats','0612345678','0612345678','Test','Contact','test@example.test','2028-10-10','primairOnderwijs','dag',40,'Earth-Watch')");
 $books=[];foreach(['Alpha','Beta','Gamma']as$name){$insert->execute([$name]);$books[]=(int)$db->pdo->lastInsertId();}
 $a=RosterLifecycleFixtures::validPlan($db->pdo,$s,40,1,2,'2028-10-10',$books[0]);
 $b=RosterLifecycleFixtures::validPlan($db->pdo,$s,40,1,2,'2028-10-10',$books[1]);
 $c=RosterLifecycleFixtures::validPlan($db->pdo,$s,40,1,2,'2028-10-10',$books[2]);
 $a=$s['lifecycle']->finalize($a['id'],1,1);$snapshot=$s['snapshots']->state($a['id'])['current'];
 $revs=[$a['id']=>$a['revision'],$b['id']=>$b['revision']];$beforeA=$s['raw']->load($a['id']);$beforeB=$s['raw']->load($b['id']);
 $reject(fn()=>$service->link($revs,'school_first',1),'PAIR_RESTART_CONFIRMATION_REQUIRED');
 $assert($beforeA===$s['raw']->load($a['id'])&&$beforeB===$s['raw']->load($b['id']),'Unconfirmed mutation');
 // Failure on school 2 must roll back the reset and reopen of school 1 as well.
 $db->pdo->prepare('UPDATE aanvragen SET aantal_leerlingen=NULL WHERE id=?')->execute([$books[1]]);
 $reject(fn()=>$service->link($revs,'school_first',1,true),'STUDENT_COUNT_REQUIRED');
 $assert($beforeA===$s['raw']->load($a['id']),'First reset did not roll back');
 $assert((new RosterPairSqlRepository($db->pdo))->forPlan($a['id'])===null,'Partial pair created');
 $db->pdo->prepare('UPDATE aanvragen SET aantal_leerlingen=40 WHERE id=?')->execute([$books[1]]);
 $pair=$service->link($revs,'school_first',1,true);
 $assert($pair['planIds']===array_keys($revs)&&$pair['workflow']==='school_first','Order/workflow lost');
 foreach([$a,$b]as$old){
  $p=$s['service']->getPlan($old['id']);$raw=$s['raw']->load($old['id']);
  $assert($p['status']==='concept'&&$p['planningMode']==='booking_locked'&&$p['sourceCurrent'],'Not restarted from booking');
  foreach(['sessions','sessionStaff','groupStaff','selectedStaff']as$key)$assert($raw[$key]===[],'Old '.$key.' survived');
  $assert($raw['settings']===null&&$raw['context']===null,'Settings survived');
  $assert(count($p['planning']['modules'])===5&&count($p['groups'])>1,'Requested program/groups not restored');
  $assert($s['service']->createFromBooking($old['bookingId'],1)['plan']['id']===$old['id'],'Duplicate plan');
 }
 $assert($s['snapshots']->find($a['id'],(int)$snapshot['id'])['content_json']===$snapshot['content_json'],'Final history modified');
 $exportPlans=$s['service']->getJointExportPlans($b['id']);
 $assert(array_column($exportPlans,'id')===$pair['planIds'],'Export lost canonical school order');
 $assert(!$db->pdo->inTransaction(),'Export leaked transaction');
 $reject(fn()=>$s['service']->getJointExportPlans($c['id']),'PAIR_NOT_FOUND');
 $assert(!$db->pdo->inTransaction(),'Failed export leaked transaction');
 $summaries=array_column($s['service']->listPlans(),null,'id');
 $assert($summaries[$a['id']]['jointPlanning']['id']===$pair['id']&&$summaries[$b['id']]['jointPlanning']['id']===$pair['id'],'Overview lacks shared pair metadata');
 $fresh=RosterLifecycleFixtures::services($db->connect());$assert($fresh['service']->getPlan($a['id'])['jointPlanning']['id']===$pair['id'],'Lost durable pair');
 $a=$s['service']->getPlan($a['id']);$b=$s['service']->getPlan($b['id']);$revs=[$a['id']=>$a['revision'],$b['id']=>$b['revision']];
 $reject(fn()=>$service->link([$a['id']=>1,$b['id']=>1],'school_first',1,true),'STALE_REVISION');
 $reject(fn()=>$service->link([$b['id']=>$b['revision'],$c['id']=>1],'lessons_first',1,true),'ALREADY_LINKED');
 // Deletion of one linked school is covered by RosterWeekAndRejectedDeleteMariaDbTest.
 $round=array_values(array_filter($a['planning']['rounds'],fn(array$r):bool=>$r['type']==='round'))[0];$staff=$beforeA['sessionStaff'][0]['staff_id'];
 $sid=$s['sessions']->insertActivity($a['id'],'Earth-Watch',$round['startTime'],$round['endTime'],null,1);
 $s['sessions']->replaceGroups($sid,[$a['groups'][0]['id']]);$s['staff']->replaceSessionAssignments($sid,[$staff],'manual');$s['staff']->addPlanSelection($a['id'],[$staff]);
 $after=$s['raw']->load($a['id']);$assert($service->link($revs,'school_first',1,true)===$pair,'Retry duplicated pair');$assert($after===$s['raw']->load($a['id']),'Retry cleared new lessons');
 $before=$s['raw']->load($b['id']);
 $reject(fn()=>$s['sessionService']->saveActivity($b['id'],null,$b['revision'],'Earth-Watch',$round['startTime'],$round['endTime'],null,[$b['groups'][0]['id']],1,[$staff]),'STAFF_DAY_OVERLAP');
 $assert($before===$s['raw']->load($b['id']),'Staff conflict not rolled back');
 $bid=$s['sessions']->insertActivity($b['id'],'Earth-Watch',$round['startTime'],$round['endTime'],null,1);$s['sessions']->replaceGroups($bid,array_slice(array_column($b['groups'],'id'),0,2));
 $assert(in_array('MODULE_CAPACITY',array_column($s['validator']->validate($a['id'],$a['revision'])->errors,'code'),true),'Linked concept capacity omitted');
 $reject(fn()=>$service->archive([$a['id']=>999,$b['id']=>$b['revision']],$pair['id'],$pair['revision'],1),'STALE_REVISION');
 $reject(fn()=>$service->archive($revs,$pair['id'],$pair['revision']+1,1),'STALE_PAIR');
 $a=$s['service']->getPlan($a['id']);$b=$s['service']->getPlan($b['id']);$service->archive($revs,$pair['id'],$pair['revision'],1);
 foreach([$a,$b]as$old){$p=$s['service']->getPlan($old['id']);$assert($p['status']==='archived'&&$p['planning']===$old['planning'],'Archive lost content');$assert($s['plans']->findIdByBookingId($old['bookingId'])===null,'Booking not released');}
 $assert($s['snapshots']->find($a['id'],(int)$snapshot['id'])['content_json']===$snapshot['content_json'],'Archive changed snapshot');
 $new=$s['service']->createFromBooking($books[0],1)['plan'];$assert($new['id']!==$a['id']&&$new['jointPlanning']===null,'Independent planning unavailable');
 // 6+5 is rejected before reset. 6+4 links successfully, including requested counts.
 $db->pdo->prepare('UPDATE aanvragen SET aantal_leerlingen=90 WHERE id=?')->execute([$books[0]]);
 $db->pdo->prepare('UPDATE aanvragen SET aantal_leerlingen=70 WHERE id=?')->execute([$books[1]]);
 $x=$s['service']->createFromBooking($books[0],1)['plan'];
 $y=$s['service']->createFromBooking($books[1],1)['plan'];
 // Existing x is the earlier recreated three-group plan: failure must also roll back after restart expands it.
 $xr=$s['raw']->load($x['id']);$yr=$s['raw']->load($y['id']);
 $reject(fn()=>$service->link([$x['id']=>$x['revision'],$y['id']=>$y['revision']],'lessons_first',1,true),'PAIR_GROUP_LIMIT');
 $assert($xr===$s['raw']->load($x['id'])&&$yr===$s['raw']->load($y['id']),'Over-limit restart modified an existing roster');
 $db->pdo->prepare("INSERT INTO roster_groups(roster_plan_id,position,label) VALUES (?,4,'Groep 4'),(?,5,'Groep 5'),(?,6,'Groep 6')")->execute([$x['id'],$x['id'],$x['id']]); // six current groups versus five current groups
 $xr=$s['raw']->load($x['id']);
 $reject(fn()=>$service->link([$x['id']=>$x['revision'],$y['id']=>$y['revision']],'lessons_first',1,true),'PAIR_GROUP_LIMIT');
 $assert($xr===$s['raw']->load($x['id']),'Preflight rejection changed roster');
 // A fresh school with four requested groups can pair with six.
 $s['service']->deleteConcept($c['id']);
 $db->pdo->prepare('UPDATE aanvragen SET aantal_leerlingen=60 WHERE id=?')->execute([$books[2]]);
 $z=$s['service']->createFromBooking($books[2],1)['plan'];
 $limitPair=$service->link([$x['id']=>$x['revision'],$z['id']=>$z['revision']],'lessons_first',1,true);
 $assert(count($s['service']->getPlan($x['id'])['groups'])+count($s['service']->getPlan($z['id'])['groups'])===10,'Ten-group boundary not accepted');
 $assert(!$db->pdo->inTransaction(),'Transaction left open');
 echo "PASS confirmed atomic restart, second-school rollback, same IDs, requested program, clean staff, immutable history, stale/retry guards, linked rules and archive\n";
}finally{$db->drop();}

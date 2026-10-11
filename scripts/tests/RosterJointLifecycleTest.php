<?php
declare(strict_types=1);
require __DIR__.'/Support/RosterLifecycleFixtures.php';
use GeoFort\Services\Dashboard\Roster\{RosterPlanException,RosterJointOptimizationService};
use GeoFort\Services\Sql\RosterPairSqlRepository;
$db=DisposableBookingMariaDb::create('joint_lifecycle');
try {
 RosterLifecycleFixtures::schema($db->pdo);$s=RosterLifecycleFixtures::services($db->pdo);$assert=RosterLifecycleFixtures::assert(...);
 $a=RosterLifecycleFixtures::validPlan($db->pdo,$s,28,1,2);$b=RosterLifecycleFixtures::validPlan($db->pdo,$s,28,1,2);
 $ids=[$a['id'],$b['id']];$rev=[$a['id']=>1,$b['id']=>1];
 $db->pdo->beginTransaction();(new RosterPairSqlRepository($db->pdo))->create($ids,$a['visitDate'],'lessons_first',1);$db->pdo->commit();
 $expect=static function(callable $fn,string $code)use($assert):void {try{$fn();throw new RuntimeException('Expected '.$code);}catch(RosterPlanException $e){$assert($e->publicCode===$code,'Expected '.$code.', got '.$e->publicCode);}};
 $before=$db->pdo->query('SELECT * FROM roster_plans ORDER BY id')->fetchAll();
 $preview=$s['lifecycle']->joint($rev,'preview',null,false,1);
 $assert($preview['canFinalize'],json_encode($preview));$assert($preview['warnings']!==[],'Expected low-student warning');
 $assert($db->pdo->query('SELECT * FROM roster_plans ORDER BY id')->fetchAll()===$before,'Preview changed plans');
 $expect(fn()=>$s['lifecycle']->finalize($a['id'],1,1),'JOINT_LIFECYCLE_REQUIRED');
 $expect(fn()=>$s['lifecycle']->joint($rev,'finalize',$preview['signature'],false,1),'WARNINGS_NOT_ACCEPTED');
 $expect(fn()=>$s['lifecycle']->joint($rev,'finalize',str_repeat('0',64),true,1),'STALE_JOINT_CONTROL');
 // A failure while saving the second school must roll back the first snapshot and status too.
 $second=$b['id'];$db->pdo->exec("CREATE TRIGGER test_joint_failure BEFORE INSERT ON roster_plan_snapshots FOR EACH ROW BEGIN IF NEW.roster_plan_id=$second THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='test second school failure'; END IF; END");
 try{$s['lifecycle']->joint($rev,'finalize',$preview['signature'],true,1);throw new RuntimeException('Expected rollback');}catch(PDOException $e){$assert($e->getCode()==='45000','Unexpected SQL failure');}
 $assert((int)$db->pdo->query('SELECT COUNT(*) FROM roster_plan_snapshots')->fetchColumn()===0,'Partial snapshot survived rollback');
 $assert((int)$db->pdo->query('SELECT COUNT(*) FROM roster_plan_lifecycle_events')->fetchColumn()===0,'Partial audit survived rollback');
 $assert($db->pdo->query('SELECT * FROM roster_plans ORDER BY id')->fetchAll()===$before,'Partial publication survived rollback');
 $db->pdo->exec('DROP TRIGGER test_joint_failure');
 $done=$s['lifecycle']->joint($rev,'finalize',$preview['signature'],true,1);
 $assert($done['applied']&&$done['status']==='published','Joint publication failed');
 foreach($ids as$id){$p=$s['service']->getPlan($id);$assert($p['status']==='published'&&$p['revision']===2&&$p['finalization']['trusted'],'Untrusted or partial final');$snap=$s['snapshots']->state($id)['current'];$assert($snap['content']['jointFinalization']['acceptedWarnings']==$preview['warnings'],'Warning acceptance not retained');}
 $expect(fn()=>$s['lifecycle']->joint($rev,'finalize',$preview['signature'],true,1),'STALE_REVISION');
 $expect(fn()=>$s['lifecycle']->reopen($a['id'],2,1),'JOINT_LIFECYCLE_REQUIRED');
 $rev=array_fill_keys($ids,2);$s['lifecycle']->joint($rev,'reopen',null,false,1);
 foreach($ids as$id){$p=$s['service']->getPlan($id);$assert($p['status']==='concept'&&$p['revision']===3&&$p['finalization']['historyCount']===1,'Joint reopen lost history');}
 // Optimizer must preview without writes, then apply both revisions together.
 $db->pdo->exec("INSERT IGNORE INTO roster_staff_module_preferences(staff_id,module_key,preference_rank) SELECT id,'Earth-Watch',1 FROM roster_staff_members WHERE display_name LIKE 'V1 %'");
 $db->pdo->exec("INSERT IGNORE INTO roster_staff_module_preferences(staff_id,module_key,preference_rank) SELECT id,'Stop-de-Klimaat-Klok',2 FROM roster_staff_members WHERE display_name LIKE 'V1 %'");
 $opt=new RosterJointOptimizationService($db->pdo);$rev=array_fill_keys($ids,3);
 $before=$db->pdo->query('SELECT * FROM roster_session_staff ORDER BY roster_session_id,staff_id')->fetchAll();
 $proposal=$opt->run($rev,null,1);$assert(!$proposal['improved'],'Already equal work must not be consolidated into fewer teachers');
 $assert($db->pdo->query('SELECT * FROM roster_session_staff ORDER BY roster_session_id,staff_id')->fetchAll()===$before,'Optimization preview wrote assignments');
 $expect(fn()=>$opt->run($rev,$proposal['signature'],1),'NO_IMPROVEMENT');
 foreach($ids as$id)$assert($s['service']->getPlan($id)['revision']===3,'Rejected optimization changed a revision');
 $preview=$s['lifecycle']->joint($rev,'preview',null,false,1);$assert($preview['canFinalize'],'Preserved planning invalid');
 // A changed catalog invalidates a checked proposal even without a plan revision change.
 $db->pdo->exec("UPDATE roster_staff_members SET display_name=CONCAT(display_name,' changed') WHERE display_name LIKE 'V1 %'");
 $expect(fn()=>$s['lifecycle']->joint($rev,'finalize',$preview['signature'],true,1),'STALE_JOINT_CONTROL');
 echo "PASS joint lifecycle: preview, warning consent, atomic rollback, trusted snapshots, duplicate/stale rejection, joint reopen, optimizer preserves equitable workload\n";
}finally{$db->drop();}

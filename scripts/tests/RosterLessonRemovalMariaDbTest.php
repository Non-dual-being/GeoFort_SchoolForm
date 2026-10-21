<?php
declare(strict_types=1);
require __DIR__.'/Support/RosterLifecycleFixtures.php';

use GeoFort\Services\Dashboard\Roster\RosterSessionResetService;
use GeoFort\Services\Sql\{RosterPairSqlRepository,RosterSessionCookSqlRepository};

$db=DisposableBookingMariaDb::create('lesson_removal');
$assert=RosterLifecycleFixtures::assert(...);
try {
    RosterLifecycleFixtures::schema($db->pdo);
    $s=RosterLifecycleFixtures::services($db->pdo);
    $reset=new RosterSessionResetService($db->pdo,$s['plans'],$s['sessions']);
    $cooks=new RosterSessionCookSqlRepository($db->pdo);
    $food=static fn(array $plan):array=>array_values(array_filter($plan['planning']['sessions'],static fn(array $row):bool=>$row['moduleKey']==='Voedsel-Innovatie'))[0];
    foreach([false,true] as $linked) {
        $date=$linked?'2028-11-02':'2028-11-01';
        $one=RosterLifecycleFixtures::validPlan($db->pdo,$s,15,1,4,$date);
        $two=RosterLifecycleFixtures::validPlan($db->pdo,$s,15,1,4,$date);
        $vi=$food($one);
        $cook=$one['planning']['staffingSettings']['cookStaffId'];
        if($linked) {
            $otherFood=$food($two);
            $s['staff']->replaceSessionAssignments($otherFood['id'],array_column($vi['staffAssignments'],'id'),'manual');
            $s['staff']->addPlanSelection($two['id'],array_column($vi['staffAssignments'],'id'));
            $s['staff']->savePlanSettings($two['id'],'with_staff',true,$cook);
            $db->pdo->beginTransaction();
            (new RosterPairSqlRepository($db->pdo))->create([$one['id'],$two['id']],$date,'linked',1);
            $db->pdo->commit();
        }
        foreach([$one['id'],$two['id']] as $id) {
            $plan=$s['service']->getPlan($id);
            foreach($plan['planning']['sessions'] as $lesson) {
                foreach($lesson['groupIds'] as $group) {
                    $db->pdo->prepare("INSERT INTO roster_session_group_staff(roster_session_id,roster_group_id,staff_id,assignment_source) VALUES (?,?,?,'manual')")
                        ->execute([$lesson['id'],$group,$lesson['staffAssignments'][0]['id']]);
                }
            }
            $cooks->save($food($plan)['id'],$plan['planning']['staffingSettings']['cookStaffId']);
        }
        $before=$s['service']->getPlan($one['id']);
        $companion=serialize($s['raw']->load($two['id']));
        $remaining=array_values(array_filter($before['planning']['sessions'],static fn(array $row):bool=>$row['id']!==$vi['id']));
        $result=$s['sessionService']->deleteActivity($one['id'],$vi['id'],$before['revision'],1);
        $after=$s['service']->getPlan($one['id']);
        $assert($after['planning']['sessions']===$remaining,'Deletion changed another lesson/assignment');
        foreach(['roster_session_staff','roster_session_group_staff','roster_session_cooks','roster_session_groups'] as $table) {
            $q=$db->pdo->prepare('SELECT COUNT(*) FROM '.$table.' WHERE roster_session_id=?');$q->execute([$vi['id']]);
            $assert((int)$q->fetchColumn()===0,'Removed lesson left assignments in '.$table);
        }
        $assert($s['validator']->occupancy($s['raw']->load($one['id']))!==[],'Remaining lessons lost occupancy');
        $assert(!in_array($vi['id'],array_column($s['validator']->occupancy($s['raw']->load($one['id'])),'sessionId'),true),'Deleted lesson remains in staff occupancy');
        $reset->reset($one['id'],$result['revision'],1);
        $empty=$s['service']->getPlan($one['id']);
        $assert($empty['planning']['sessions']===[] && $s['validator']->occupancy($s['raw']->load($one['id']))===[],'Reset leaves staff occupancy');
        $assert($empty['planning']['selectedStaffIds']===$before['planning']['selectedStaffIds'],'Reset removed reusable staff selection');
        $assert($cooks->forPlan($one['id'])===[],'Reset leaves cook assignments');
        $assert(serialize($s['raw']->load($two['id']))===$companion,'Companion roster or shared VI team changed');
        echo 'PASS '.($linked?'linked':'single').": one lesson and full reset clear guide/group/cook assignments; remaining lessons and companion preserved\n";
    }
} finally { $db->drop(); }

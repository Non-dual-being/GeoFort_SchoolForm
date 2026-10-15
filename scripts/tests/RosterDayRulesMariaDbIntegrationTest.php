<?php
declare(strict_types=1);
require __DIR__.'/Support/RosterLifecycleFixtures.php';
use GeoFort\Services\Dashboard\Roster\RosterPlanException;
$db=DisposableBookingMariaDb::create('roster_day_rules');
try{
    RosterLifecycleFixtures::schema($db->pdo);$s=RosterLifecycleFixtures::services($db->pdo);$assert=RosterLifecycleFixtures::assert(...);
    $db->pdo->exec("INSERT INTO roster_staff_members(display_name,is_active,can_guide,can_cook) VALUES('Gezamenlijke dagdocent',1,1,0)");$guide=(int)$db->pdo->lastInsertId();
    foreach(['Earth-Watch','Stop-de-Klimaat-Klok','Dynamische-Globe','Klimaat-Experience','Klimparcours']as $module)$db->pdo->prepare('INSERT INTO roster_staff_module_preferences(staff_id,module_key,preference_rank) VALUES(?,?,7)')->execute([$guide,$module]);
    $make=function(string $name,array $modules,int $shift=0,string $date='2028-10-10')use($s,$guide):array{
        $plan=$s['service']->createManual($name,'Testplaats',$date,'primairOnderwijs','dag',40,1,1)['plan'];$id=$plan['id'];
        $s['plans']->saveManualModuleKeys($id,$modules);
        $timeline=$s['templates']->defaultTimeline('dag',count($modules));
        if($shift)foreach($timeline as &$block)foreach(['startTime','endTime']as $k){[$h,$m]=array_map('intval',explode(':',$block[$k]));$mins=$h*60+$m+$shift;$block[$k]=sprintf('%02d:%02d',intdiv($mins,60),$mins%60);}unset($block);
        $s['plans']->saveManualRounds($id,$timeline);$s['staff']->savePlanSettings($id,'with_staff',true,null);$s['staff']->addPlanSelection($id,[$guide]);
        $rounds=array_values(array_filter($timeline,fn(array $b):bool=>$b['type']==='round'));
        foreach($rounds as $i=>$round){$sid=$s['sessions']->insertActivity($id,$modules[$i],$round['startTime'],$round['endTime'],null,1);$s['sessions']->replaceGroups($sid,[$plan['groups'][0]['id']]);$s['staff']->replaceSessionAssignments($sid,[$guide],'manual');}
        return $s['service']->getPlan($id);
    };
    $early=$make('School vroeg',['Earth-Watch','Stop-de-Klimaat-Klok']);$late=$make('School laat',['Dynamische-Globe','Klimaat-Experience','Klimparcours'],120);
    $combined=$s['validator']->validatePlans([$early['id']=>1,$late['id']=>1]);
    $assert($combined['plans'][$early['id']]['valid']&&$combined['plans'][$late['id']]['valid'],'Both individual plans must be structurally valid');
    $assert(!$combined['canFinalize']&&in_array('STAFF_DAY_MODULE_LIMIT',array_column($combined['day']['errors'],'code'),true),'Joint day must reject five different modules');
    $first=$s['lifecycle']->finalize($early['id'],1,1);$frozen=$s['snapshots']->state($early['id'])['current']['content_json'];
    $validation=$s['lifecycle']->validate($late['id'],1);$assert(!$validation['valid']&&in_array('STAFF_DAY_MODULE_LIMIT',array_column($validation['errors'],'code'),true),'Standalone finalization must count published day modules');
    try{$s['lifecycle']->finalize($late['id'],1,1);throw new LogicException('Five-module day finalized');}catch(RosterPlanException $e){$assert($e->publicCode==='ROSTER_INVALID','Wrong finalization rejection');}
    $assert($s['service']->getPlan($late['id'])['revision']===1&&$s['snapshots']->state($late['id'])['historyCount']===0,'Rejected finalization must not write a revision or snapshot');
    $db->pdo->prepare('DELETE ss FROM roster_session_staff ss JOIN roster_sessions rs ON rs.id=ss.roster_session_id WHERE rs.roster_plan_id=?')->execute([$early['id']]);
    $assert(!$s['lifecycle']->validate($late['id'],1)['valid'],'Raw edits must not remove frozen published day occupancy');
    $assert($s['snapshots']->state($early['id'])['current']['content_json']===$frozen,'Published snapshot changed');
    $s['plans']->saveManualModuleKeys($late['id'],['Dynamische-Globe','Earth-Watch','Klimparcours']);
    $db->pdo->prepare("UPDATE roster_sessions SET module_key='Earth-Watch' WHERE roster_plan_id=? AND module_key='Klimaat-Experience'")->execute([$late['id']]);
    $assert($s['lifecycle']->validate($late['id'],1)['valid'],'Four distinct modules across the day must fit');
    $s['lifecycle']->finalize($late['id'],1,1);
    $left=$make('School links',['Earth-Watch','Stop-de-Klimaat-Klok'],0,'2028-10-11');$right=$make('School rechts',['Earth-Watch','Stop-de-Klimaat-Klok'],0,'2028-10-11');
    $pair=$s['validator']->validatePlans([$left['id']=>1,$right['id']=>1],true);
    $assert($pair['day']['validLessons']&&$pair['day']['requiresSharedConfirmation']&&!$pair['canFinalize'],'Two valid school lessons must still require explicit shared-guide consent');
    $approved=$s['validator']->validatePlans([$left['id']=>1,$right['id']=>1],true,$pair['day']['exceptionSignature']);
    $assert($approved['canFinalize']&&count($approved['day']['warnings'])===2,'Confirmed shared sessions must remain visibly warned');
    $db->pdo->beginTransaction();$s['plans']->advanceRevision($right['id'],1);$db->pdo->commit();
    try{$s['validator']->validatePlans([$left['id']=>1,$right['id']=>1],true,$pair['day']['exceptionSignature']);throw new LogicException('Stale pair accepted');}catch(RosterPlanException $e){$assert($e->publicCode==='STALE_REVISION','Expected stale pair conflict');}
    $changed=$s['validator']->validatePlans([$left['id']=>1,$right['id']=>2],true,$pair['day']['exceptionSignature']);$assert($changed['day']['requiresSharedConfirmation'],'Changed school revision must invalidate confirmation');
    echo "PASS: joint day controls, frozen day module limit, transactional finalization rejection, shared warning/consent and stale revisions\n";
}finally{$db->drop();}

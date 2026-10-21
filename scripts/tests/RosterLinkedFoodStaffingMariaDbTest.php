<?php
declare(strict_types=1);
require __DIR__.'/Support/RosterLifecycleFixtures.php';
use GeoFort\Services\Dashboard\Roster\{RosterAutoGenerator,RosterStaffOptimizer,RosterDayRules,RosterJointOptimizationService,RosterJointStaffOptimizer,RosterSharedFoodStaffing};
use GeoFort\Services\Sql\{RosterPairSqlRepository,RosterSessionCookSqlRepository};

$assert=RosterLifecycleFixtures::assert(...);
$db=DisposableBookingMariaDb::create('linked_food_staffing');
try {
    RosterLifecycleFixtures::schema($db->pdo);
    $s=RosterLifecycleFixtures::services($db->pdo);
    $generator=new RosterAutoGenerator($db->pdo,$s['bookings'],$s['plans'],$s['sessions'],$s['config'],$s['templates'],$s['staff'],new RosterStaffOptimizer($s['staff']));
    $crew=[];
    for($i=0;$i<4;$i++) {
        $db->pdo->prepare('INSERT INTO roster_staff_members(display_name,is_active,can_guide,can_cook) VALUES (?,1,1,0)')->execute(['Food fixture guide '.$i]);
        $id=(int)$db->pdo->lastInsertId();$crew[]=$id;
        foreach(['Voedsel-Innovatie','Dynamische-Globe','Klimaat-Experience','Klimparcours'] as $n=>$key)$db->pdo->prepare('INSERT INTO roster_staff_module_preferences(staff_id,module_key,preference_rank) VALUES (?,?,?)')->execute([$id,$key,$n+1]);
    }
    $db->pdo->exec("INSERT INTO roster_staff_members(display_name,is_active,can_guide,can_cook) VALUES ('Food fixture cook',1,0,1)");
    $cook=(int)$db->pdo->lastInsertId();
    $date=new DateTimeImmutable('2028-10-10');
    $make=function(string $date,array $keys,?string $version=null,int $groups=1)use($s,$db):array{
        $p=$s['service']->createManual('Food fixture school','Testplaats',$date,'primairOnderwijs','dag',$groups*15,$groups,1)['plan'];
        $s['plans']->saveManualModuleKeys($p['id'],$keys);
        if($version!==null)$db->pdo->prepare('UPDATE roster_manual_plan_context SET program_version=? WHERE roster_plan_id=?')->execute([$version,$p['id']]);
        return $s['service']->getPlan($p['id']);
    };
    $food=static fn(array $p):array=>array_values(array_filter($p['planning']['sessions'],static fn(array $l):bool=>$l['moduleKey']==='Voedsel-Innovatie'))[0];
    foreach([null,'day-four-2027'] as $version) {
        $keys=$version===null?['Voedsel-Innovatie','Dynamische-Globe']:['Voedsel-Innovatie','Dynamische-Globe','Klimaat-Experience','Klimparcours'];
        // Two groups in one school: exactly one guide and one cook, also four modules.
        $date=$date->modify('+7 days');$p=$make($date->format('Y-m-d'),$keys,$version,2);
        $generator->apply($p['id'],$p['revision'],$p['planning']['generation']['defaultRounds'],true,1,$crew,cookStaffId:$cook);
        $p=$s['service']->getPlan($p['id']);$vi=$food($p);
        $assert(count($vi['staffAssignments'])===1 && $vi['cookStaffId']===$cook,'Single-school VI must use exactly one guide and cook');
        $report=$s['validator']->validate($p['id'],$p['revision'])->toArray();
        $assert($report['valid'],'Single-school VI invalid: '.json_encode($report['errors']));
        foreach([false,true] as $existing) {
            $date=$date->modify('+7 days');
            $one=$make($date->format('Y-m-d'),$keys,$version);
            $two=$make($date->format('Y-m-d'),$keys,$version);
            $generator->apply($one['id'],$one['revision'],$one['planning']['generation']['defaultRounds'],true,1,$crew,cookStaffId:$cook);
            $one=$s['service']->getPlan($one['id']);$oneVi=$food($one);$sharedGuide=$oneVi['staffAssignments'][0]['id'];
            if($existing) {
                $generator->apply($two['id'],$two['revision'],$two['planning']['generation']['defaultRounds'],true,1,staffingMode:'lesson_only');
                $two=$s['service']->getPlan($two['id']);
            }
            $db->pdo->beginTransaction();
            (new RosterPairSqlRepository($db->pdo))->create([$one['id'],$two['id']],$one['visitDate'],'linked',1);
            $db->pdo->commit();
            $before=serialize($s['raw']->load($one['id']));
            $targetBefore=serialize($s['raw']->load($two['id']));
            $preview=$generator->preview($two['id'],$two['planning']['generation']['defaultRounds'],$crew,cookStaffId:$cook,useExistingLessons:$existing);
            $assert($preview['canApply']&&$preview['canFinalize'],'Linked VI preview blocked: '.json_encode($preview['hardIssues']));
            $lesson=array_values(array_filter($preview['sessions'],static fn(array $l):bool=>$l['moduleKey']==='Voedsel-Innovatie'))[0];
            $assert($lesson['startTime']===$oneVi['startTime']&&$lesson['assignedStaffIds']===[$sharedGuide],'Linked food did not reuse the existing guide and time: '.json_encode([$oneVi['startTime'],$sharedGuide,$lesson['startTime'],$lesson['assignedStaffIds'],$preview['diagnostics']]));
            $assert(serialize($s['raw']->load($two['id']))===$targetBefore,'Preview changed saved lessons');
            $generator->apply($two['id'],$two['revision'],$two['planning']['generation']['defaultRounds'],true,1,$crew,cookStaffId:$cook,useExistingLessons:$existing,expectedStaffingSignature:$preview['staffingSignature']??null);
            $two=$s['service']->getPlan($two['id']);
            $assert(serialize($s['raw']->load($one['id']))===$before,'Companion school changed');
            $report=$s['validator']->validate($two['id'],$two['revision'])->toArray();
            $assert($report['valid'],'Single-school control rejects linked food: '.json_encode($report));
            $assert(!in_array('OTHER_PLAN_STAFF_CONFLICT',array_column($report['warnings'],'code'),true),'False cross-school warning remains');
            $revisions=[$one['id']=>$one['revision'],$two['id']=>$two['revision']];
            $joint=$s['validator']->validatePlans($revisions);
            $assert($joint['canFinalize'],'Joint finalization rejects valid food team: '.json_encode($joint));
            $optimization=(new RosterJointOptimizationService($db->pdo))->run($revisions,null,1);
            $assert(serialize($s['raw']->load($one['id']))===$before,'Optimization preview changed companion');
            $saved=$food($two);
            $manual=$s['sessionService']->saveGroupStaffAssignment($two['id'],$saved['id'],$two['revision'],$saved['groupIds'][0],[$sharedGuide],1,'staff');
            $two=$s['service']->getPlan($two['id']);$revisions[$two['id']]=$two['revision'];
            $finalPreview=$s['lifecycle']->joint($revisions,'preview',null,false,1);
            $assert($finalPreview['canFinalize']??$finalPreview['report']['canFinalize']??false,'Joint publication preview failed: '.json_encode($finalPreview));
            $done=$s['lifecycle']->joint($revisions,'finalize',$finalPreview['signature'],true,1);
            $assert($done['applied']&&$done['status']==='published','Shared food publication failed');
            $finalOne=$s['service']->getPlan($one['id']);$finalTwo=$s['service']->getPlan($two['id']);
            $frozen=$s['snapshots']->state($one['id'])['current']['content_json'];
            $assert($s['validator']->validatePlans([$one['id']=>$finalOne['revision'],$two['id']=>$finalTwo['revision']])['canFinalize'],'Frozen linked food no longer valid');
            $assert($s['snapshots']->state($one['id'])['current']['content_json']===$frozen,'Read control changed snapshot');
            echo 'PASS linked food '.($version??'five').', '.($existing?'existing lessons':'new generation')."; saved and manual controls, joint optimization/publication preview, unchanged companion\n";
        }
    }
    // Pure rule boundaries, including legacy shared-guide consent and unrelated schools.
    $row=static fn(int $plan,int $guide,int $cook):array=>['planId'=>$plan,'pairId'=>99,'moduleKey'=>'Voedsel-Innovatie','sessionId'=>$plan,'groupCount'=>1,'groupIds'=>[$plan],'startTime'=>'10:15','endTime'=>'11:00','staffIds'=>[$guide,$cook],'cookStaffId'=>$cook];
    $plans=static fn(array $a,array $b):array=>array_map(static fn(array $r):array=>['planId'=>$r['planId'],'pairId'=>$r['pairId']??null,'revision'=>1,'visitDate'=>'2028-10-10','schoolName'=>'Fixture','cookStaffId'=>$r['cookStaffId'],'occupancy'=>[$r]],[$a,$b]);
    $rules=new RosterDayRules($s['config']);$a=$row(1,10,20);$b=$row(2,10,20);
    $assert($rules->validate($plans($a,$b))['canFinalize'],'Linked team invalid');
    foreach(['unlinked','different_pair','partial','three_groups','other_module','cook_guide','extra_guide','extra_cook'] as $case){
        $changed=$b;
        match($case){
            'unlinked'=>$changed['pairId']=null,'different_pair'=>$changed['pairId']=100,
            'partial'=>$changed['startTime']='10:30','three_groups'=>$changed['groupCount']=2,
            'other_module'=>$changed['moduleKey']='Earth-Watch','cook_guide'=>$changed['cookStaffId']=10,
            'extra_guide'=>$changed['staffIds']=[11,20],
            'extra_cook'=>[$changed['staffIds'],$changed['cookStaffId']]=[[10,21],21],
        };
        $report=$rules->validate($plans($a,$changed),true,'legacy-consent');
        $assert(!$report['canFinalize'],'Invalid exception accepted: '.$case);
    }
    // One simultaneous hour is counted once, even with two school records.
    $optimizer=new RosterJointStaffOptimizer();
    $tasks=[...array_map(static fn(array $r):array=>[...$r,'staffId'=>20,'cook'=>true],[$a,$b]),...array_map(static fn(array $r):array=>[...$r,'staffId'=>10,'cook'=>false],[$a,$b])];
    $assignment=[20,20,10,10];$members=[10=>['id'=>10,'name'=>'Guide','employmentType'=>'paid','canGuide'=>true,'preferences'=>[]],20=>['id'=>20,'name'=>'Cook','employmentType'=>'paid','canGuide'=>false,'preferences'=>[]]];
    $assert($optimizer->valid($tasks,$assignment),'Joint optimizer blocks shared team');
    $metrics=$optimizer->metrics($tasks,$assignment,$members,$assignment);
    foreach($metrics['staff'] as $r)$assert($r['workMinutes']===45,'Shared hour counted twice');
    echo "PASS boundaries: only linked VI, two groups, matching full slot, same roles, one cook/guide; minutes counted once\n";
} finally {$db->drop();}

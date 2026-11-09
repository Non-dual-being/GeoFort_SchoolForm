<?php
declare(strict_types=1);
require __DIR__.'/Support/RosterLifecycleFixtures.php';
use GeoFort\Booking\FourModuleProgram;
use GeoFort\Services\Dashboard\Roster\{RosterJointWorkspaceService,RosterJointDraft,RosterJointStaffOptimizer,RosterJointStaffApproval,RosterPlanException};

$db=DisposableBookingMariaDb::create('ke_manual_workspace');
try {
    $pdo=$db->pdo; RosterLifecycleFixtures::schema($pdo); $s=RosterLifecycleFixtures::services($pdo);
    foreach (['single','joint'] as $kind) $pdo->exec(file_get_contents(dirname(__DIR__,2).'/database/sql/2026-11-06_create_'.$kind.'_workspace_events.sql'));
    $checks=0; $assert=function(bool $ok,string $message) use (&$checks):void {$checks++;if(!$ok)throw new RuntimeException($message);};
    $dg='Dynamische-Globe';$ke='Klimaat-Experience';$vi='Voedsel-Innovatie';$vs='Klimparcours';
    $people=[];$names=['A'=>'Frank van Kessel','B'=>'Kevin de Schepper','C'=>'Nathalja Beens','D'=>'Nelleke de With','E'=>'Wil Verhoeven'];
    foreach (['A'=>[$dg],'B'=>[$ke],'C'=>[$vi],'D'=>[],'E'=>[$dg,$ke]] as $letter=>$skills) {
        $pdo->prepare('INSERT INTO roster_staff_members(display_name,is_active,can_guide,can_cook,employment_type) VALUES (?,1,?,?,?)')->execute(['KE test '.$names[$letter],$letter==='D'?0:1,$letter==='D'?1:0,$letter==='A'?'volunteer':'paid']);
        $people[$letter]=(int)$pdo->lastInsertId();
        foreach ($skills as $rank=>$module) $pdo->prepare('INSERT INTO roster_staff_module_preferences(staff_id,module_key,preference_rank) VALUES (?,?,?)')->execute([$people[$letter],$module,$rank+1]);
    }
    $pdo->prepare("INSERT INTO aanvragen(status,schoolnaam,land,adres,postcode,plaats,school_telefoonnummer,contactpersoon_telefoonnummer,contactpersoon_voornaam,contactpersoon_achternaam,email,bezoekdatum,onderwijs_sector,programma,program_version,keuzemodule_key,aantal_leerlingen,aantal_begeleiders,voorwaarden_akkoord) VALUES ('Definitief','KE regressietest','Nederland','Testweg 1','1234 AB','Testplaats','0100000000','0612345678','Test','Planner','test@example.test','2027-03-03','primairOnderwijs','dag',?,?,120,7,1)")->execute([FourModuleProgram::VERSION,$ke]);
    $bid=(int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO aanvraag_onderwijs_selecties(aanvraag_id,sector_key,sector_label,level_key,level_label,level_position,group_key,group_label,group_position) VALUES (?,'primairOnderwijs','PO','regulier','Regulier',1,'groep8','Groep 8',1)")->execute([$bid]);
    $plan=$s['service']->createFromBooking($bid,1)['plan']; $plan=$s['service']->getPlan($plan['id']);$pid=$plan['id'];$gids=array_column($plan['groups'],'id');
    $assert(count($gids)===7,'Seven screenshot groups required');
    $s['staff']->savePlanSettings($pid,'with_staff',true,$people['D']);$s['staff']->addPlanSelection($pid,array_values($people));
    $times=[['10:15','11:00'],['11:00','11:45'],['12:15','13:00'],['13:00','13:45']];
    $layout=[[$vi,0,[1,2],['C']],[$dg,0,[3,4],['A','E']],[$ke,0,[5,6],['B']],[$vs,0,[7],[]],
        [$dg,1,[1,2],['A','E']],[$vi,1,[3,4],['C']],[$vs,1,[5,6],[]],[$ke,1,[7],['B']],
        [$ke,2,[1],['E']],[$ke,2,[2],['B']],[$vs,2,[3,4],[]],[$vi,2,[5,6],['C']],[$dg,2,[7],['A']],
        [$vs,3,[1,2],[]],[$ke,3,[3,4],['B']],[$dg,3,[5,6],['A','E']],[$vi,3,[7],['C']]];
    $byKey=[];
    foreach($layout as [$module,$round,$groups,$letters]) {
        $sid=$s['sessions']->insertActivity($pid,$module,...[...$times[$round],null,1]);
        $ids=array_map(fn($n)=>$gids[$n-1],$groups);$staff=array_map(fn($l)=>$people[$l],$letters);
        $s['sessions']->replaceGroups($sid,$ids);$s['staff']->replaceSessionAssignments($sid,$staff,'manual');$groupStaff=[];
        foreach($ids as $i=>$gid) {$groupStaff[$gid]=$staff===[]?[]:[$staff[count($staff)>1?$i:0]];$s['sessions']->setGroupSupervision($sid,$gid,$staff===[]?'school':'staff');}
        $s['staff']->replaceSessionGroupAssignments($sid,$groupStaff,'manual');
        foreach($groups as $n)$byKey[$module.':'.$n]=$sid;
    }
    $workspace=new RosterJointWorkspaceService($pdo,true);$revisions=fn()=>[$pid=>(int)$s['raw']->load($pid)['plan']['revision']];
    $options=['teamMode'=>'existing','shiftLessons'=>false,'moduleSwaps'=>true,'timePins'=>[],'staffPins'=>[],'retained'=>[],'additions'=>[],'approvedStaff'=>[],'releasedStaff'=>[]];
    $before=serialize($s['raw']->load($pid));$loaded=$workspace->run($revisions(),'read',null,[],null,1);$base=$loaded['state'];
    $assert($loaded['readiness']['canFinalize'],'Screenshot source roster invalid: '.json_encode($loaded['readiness']['report']['errors']));
    $target=fn($n)=>['planId'=>$pid,'sessionId'=>$byKey[$ke.':'.$n],'groupId'=>$gids[$n-1],'cook'=>false];
    $candidates=fn($draft,$n,$opts=null)=>$workspace->run($revisions(),'candidates',$draft,[...($opts??$options),'target'=>$target($n)],null,1)['candidates'];
    $person=fn($rows,$id)=>array_values(array_filter($rows,fn($r)=>$r['id']===$id))[0];
    $kevin=$person($candidates($base,1),$people['B']);$current=$person($candidates($base,2),$people['B']);
    if(in_array('--reproduce',$argv,true)) {echo json_encode(['Kevin_for_group_1'=>$kevin,'Kevin_current_group_2'=>$current],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE),"\n";} else {
    $assert($kevin['qualified'] && $kevin['reasons']===[] && !$kevin['assigned'],'KE guide wrongly blocked across two same-school sessions: '.json_encode($kevin));
    $assert($current['assigned']===true,'Current teacher not marked already assigned');
    $draft=$base;
    $swap=function(array &$draft,string $a,int $g,string $b,int $h)use($byKey):void {
        $aidx=array_search($byKey[$a.':'.$g],array_column($draft[0]['sessions'],'id'));$bidx=array_search($byKey[$b.':'.$h],array_column($draft[0]['sessions'],'id'));
        foreach(['start','end'] as $key)[$draft[0]['sessions'][$aidx][$key],$draft[0]['sessions'][$bidx][$key]]=[$draft[0]['sessions'][$bidx][$key],$draft[0]['sessions'][$aidx][$key]];
    };
    // The existing editor swaps the complete two-group lesson. Group 7 must move too.
    $swap($draft,$dg,3,$vs,3);
    $bad=$workspace->run($revisions(),'preview',$draft,$options,null,1);
    $assert(!$bad['report']['canApply'],'Three simultaneous DG groups incorrectly accepted');
    $assert(in_array('STAFF_OVERLAP',array_column($bad['report']['errors'],'code'),true),'Double DG teacher not reported');
    $swap($draft,$dg,7,$vs,7);
    $auto=$workspace->run($revisions(),'optimize',$draft,$options,null,1);
    $assert($auto['report']['canFinalize'],'Optimizer cannot staff the corrected lesson layout');
    $autoKe=array_values(array_filter($auto['state'][0]['sessions'],fn($row)=>in_array($row['id'],[$byKey[$ke.':1'],$byKey[$ke.':2']],true)));
    $assert(count($autoKe)===2 && $autoKe[0]['groups'][0]['staffIds']===[$people['B']] && $autoKe[1]['groups'][0]['staffIds']===[$people['B']],'Optimizer did not share Kevin across both KE groups');
    foreach($draft[0]['sessions'] as &$session)if($session['id']===$byKey[$ke.':1']){$session['groups'][0]['kind']='staff';$session['groups'][0]['staffIds']=[$people['B']];}unset($session);
    $review=$workspace->run($revisions(),'preview',$draft,$options,null,1);
    $assert($review['report']['canFinalize'] && $review['report']['errors']===[],'Working solution rejected: '.json_encode($review['report']));
    $trial=RosterJointDraft::patch([$pid=>$s['raw']->load($pid)],$draft,$options);$tasks=RosterJointDraft::tasks($trial,$s['validator'],null);
    $optimizer=new RosterJointStaffOptimizer();
    $assert($optimizer->valid($tasks,array_column($tasks,'staffId')),'Optimizer disagrees with valid KE solution');
    $wil=array_values(array_filter($tasks,fn($t)=>$t['staffId']===$people['E']));
    $assert(min(array_column($wil,'startTime'))==='11:00','Wil does not start later');
    $assert(array_sum(array_map(fn($t)=>RosterJointDraft::minutes($t['endTime'])-RosterJointDraft::minutes($t['startTime']),$wil))===135,'Wil should teach 135 minutes');
    $assert($person($candidates($draft,1),$people['B'])['assigned'],'New assignment can still be chosen again');
    $assert($person($candidates($base,1),$people['A'])['reasons']!==[],'Unqualified staff offered for KE');
    $pinned=[...$options,'approvedStaff'=>[['id'=>$people['B'],'schedule'=>RosterJointStaffApproval::signature($base,$people['B'])]]];
    $assert($person($candidates($base,1,$pinned),$people['B'])['reasons']!==[],'Approved employee can be changed');
    $duplicate=$draft;foreach($duplicate[0]['sessions'] as &$session)if($session['id']===$byKey[$ke.':1'])$session['groups'][0]['staffIds']=[$people['B'],$people['B']];unset($session);
    try{$workspace->run($revisions(),'preview',$duplicate,$options,null,1);throw new RuntimeException('Duplicate assignment accepted');}catch(RosterPlanException $e){$assert($e->publicCode==='INVALID_DRAFT','Wrong duplicate error');}
    $assert(serialize($s['raw']->load($pid))===$before,'Preview/candidate checks changed saved roster');
    // Verify transactional storage and the finalization validator on this disposable fixture only.
    $workspace->run($revisions(),'apply',$draft,$options,$review['signature'],1);
    $saved=$workspace->run($revisions(),'read',null,[],null,1);
    $assert($saved['state']===$draft && $saved['readiness']['canFinalize'],'Saved solution differs or fails finalization');
    $assert($s['validator']->validate($pid,$revisions()[$pid])->errors===[],'Standalone validation disagrees');
    $s['lifecycle']->finalize($pid,$revisions()[$pid],1);
    $assert($s['raw']->load($pid)['plan']['status']==='published','Valid shared KE solution cannot be finalized');
    $export=['names'=>$names,'times'=>$times,'groups'=>7,'report'=>$review['report'],'staff'=>[],'lessons'=>[]];
    $letters=array_flip($people);$modules=array_column($s['raw']->load($pid)['sessions'],'module_key','id');
    foreach($draft[0]['sessions'] as $session)foreach($session['groups'] as $group)$export['lessons'][]=['group'=>array_search($group['id'],$gids)+1,'module'=>$modules[$session['id']],'start'=>$session['start'],'end'=>$session['end'],'staff'=>array_map(fn($id)=>$letters[$id],$group['staffIds']),'kind'=>$group['kind'],'cook'=>$letters[$session['cook']]??null];
    foreach($people as $letter=>$id){$own=array_filter($tasks,fn($t)=>$t['staffId']===$id);$export['staff'][$letter]=['start'=>min(array_column($own,'startTime')),'end'=>max(array_column($own,'endTime'))];}
    foreach($argv as $arg)if(str_starts_with($arg,'--output='))file_put_contents(substr($arg,9),json_encode($export,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
    echo "PASS: {$checks} KE manual workspace checks; screenshot reproduced, complete solution validated/applied in disposable DB, source preserved during preview; no mail\n";
    }
} finally {$db->drop();}

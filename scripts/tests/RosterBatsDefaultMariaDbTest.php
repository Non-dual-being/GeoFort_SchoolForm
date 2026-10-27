<?php
declare(strict_types=1);
require __DIR__.'/Support/RosterLifecycleFixtures.php';
use GeoFort\Services\Dashboard\Roster\{RosterAutoGenerator,RosterStaffOptimizer,RosterLessonPreferences};

$assert=RosterLifecycleFixtures::assert(...);
$db=DisposableBookingMariaDb::create('bats_default');
try {
    RosterLifecycleFixtures::schema($db->pdo);
    $s=RosterLifecycleFixtures::services($db->pdo);
    $generator=new RosterAutoGenerator($db->pdo,$s['bookings'],$s['plans'],$s['sessions'],$s['config'],$s['templates'],$s['staff'],new RosterStaffOptimizer($s['staff']));
    $crew=[];
    foreach(range(1,5) as $n) {
        $db->pdo->prepare('INSERT INTO roster_staff_members(display_name,is_active,can_guide,can_cook) VALUES (?,1,1,0)')->execute(['Bats test guide '.$n]);
        $id=(int)$db->pdo->lastInsertId();$crew[]=$id;
        foreach(['Dynamische-Globe','Klimparcours','Klimaat-Experience','Earth-Watch'] as $i=>$key) $db->pdo->prepare('INSERT INTO roster_staff_module_preferences(staff_id,module_key,preference_rank) VALUES (?,?,?)')->execute([$id,$key,$i+1]);
    }
    $date=new DateTimeImmutable('2028-11-06');
    foreach([null,'day-four-2027'] as $version) {
        $date=$date->modify('+7 days');
        $p=$s['service']->createManual('Bats test','Testplaats',$date->format('Y-m-d'),'primairOnderwijs','dag',30,2,1)['plan'];
        $s['plans']->saveManualModuleKeys($p['id'],['Dynamische-Globe','Klimaat-Experience','Klimparcours','Earth-Watch']);
        if($version!==null)$db->pdo->prepare('UPDATE roster_manual_plan_context SET program_version=? WHERE roster_plan_id=?')->execute([$version,$p['id']]);
        $p=$s['service']->getPlan($p['id']);$rounds=$p['planning']['generation']['defaultRounds'];
        $prefs=RosterLessonPreferences::defaults($p['planning']['modules']);
        $assert($prefs['supervision']['Klimparcours']==='school','Bats default must be School in both versions');
        $before=serialize($s['raw']->load($p['id']));
        foreach(['lesson_only','with_staff'] as $mode) {
            $proposal=$generator->preview($p['id'],$rounds,$mode==='with_staff'?$crew:[],$mode);
            foreach($proposal['sessions'] as $lesson) {
                if($lesson['moduleKey']==='Klimparcours') $assert($lesson['schoolFallback'] && $lesson['assignedStaffIds']===[],'School supervision must win even with spare qualified staff');
                if($mode==='with_staff' && $lesson['moduleKey']==='Dynamische-Globe') $assert(count($lesson['assignedStaffIds'])===count($lesson['groupIds']),'Mandatory Globe lessons must be staffed');
            }
            $assert(!array_filter($proposal['warnings'],static fn($w)=>$w['code']==='SCHOOL_SUPERVISION_OVERRIDE' && str_contains($w['message'],'Vleermuizen')),'Default Bats guidance must not be called an exception');
            $choice=$prefs;$choice['supervision']['Klimparcours']='geofort';
            $geofort=$generator->preview($p['id'],$rounds,$mode==='with_staff'?$crew:[],$mode,lessonPreferences:$choice);
            foreach($geofort['sessions'] as $lesson) if($lesson['moduleKey']==='Klimparcours') {
                $assert(!$lesson['schoolFallback'],'Explicit GeoFort choice ignored');
                if($mode==='with_staff')$assert(count($lesson['assignedStaffIds'])===1,'Explicit GeoFort did not receive a qualified guide');
            }
        }
        $assert(serialize($s['raw']->load($p['id']))===$before,'Previews changed the roster');
        $generator->apply($p['id'],$p['revision'],$rounds,true,1,staffingMode:'lesson_only');
        $saved=$s['service']->getPlan($p['id']);
        $existingBefore=serialize($s['raw']->load($p['id']));
        $staffed=$generator->preview($p['id'],[],$crew,useExistingLessons:true,optimizeExistingLessons:true);
        foreach($staffed['sessions'] as $lesson)if($lesson['moduleKey']==='Klimparcours')foreach($lesson['groupStaffAssignments'] as $group)$assert($group['kind']==='school','Saved School guidance was reassigned');
        $assert(serialize($s['raw']->load($p['id']))===$existingBefore,'Existing lesson staffing changed saved data');
        echo 'PASS Bats '.($version??'five').": default School, explicit GeoFort, mandatory staffing first, persisted School and preview immutability\n";
    }
} finally { $db->drop(); }

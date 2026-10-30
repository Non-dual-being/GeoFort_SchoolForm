<?php
declare(strict_types=1);
require __DIR__ . '/Support/RosterLifecycleFixtures.php';
use GeoFort\Services\Dashboard\Roster\{RosterAutoGenerator, RosterLessonPreferences, RosterStaffOptimizer, RosterPlanException};

$db = DisposableBookingMariaDb::create('late_vi');
try {
    RosterLifecycleFixtures::schema($db->pdo); $s = RosterLifecycleFixtures::services($db->pdo);
    $assert = RosterLifecycleFixtures::assert(...);
    $generator = new RosterAutoGenerator($db->pdo,$s['bookings'],$s['plans'],$s['sessions'],$s['config'],$s['templates'],$s['staff'],new RosterStaffOptimizer($s['staff']));
    $vi = 'Voedsel-Innovatie'; $keys = [$vi,'Dynamische-Globe','Klimaat-Experience','Earth-Watch'];
    $p = $s['service']->createManual('VI middagcontrole','Testplaats','2028-11-15','primairOnderwijs','dag',19,1,1)['plan'];
    $s['plans']->saveManualModuleKeys($p['id'],$keys);
    $db->pdo->prepare('UPDATE roster_manual_plan_context SET program_version=? WHERE roster_plan_id=?')->execute(['day-four-2027',$p['id']]);
    $p = $s['service']->getPlan($p['id']); $rounds = $p['planning']['generation']['defaultRounds'];
    $normal = RosterLessonPreferences::defaults($p['planning']['modules']);
    $late = [...$normal,'allowLateVi'=>true,'moduleOrder'=>['Dynamische-Globe','Klimaat-Experience','Earth-Watch',$vi]];
    foreach ([[...$late,'allowLateVi'=>false],[...$late,'allowLateVi'=>'1']] as $invalid) {
        try { RosterLessonPreferences::validate($invalid,$p['planning']['modules']); throw new RuntimeException('Invalid VI permission accepted'); }
        catch (RosterPlanException $e) { $assert($e->publicCode==='INVALID_LESSON_PREFERENCES','Wrong preference rejection'); }
    }
    $oldPrefs = $normal; unset($oldPrefs['allowLateVi']);
    $assert(RosterLessonPreferences::forModules($oldPrefs,$p['planning']['modules'])['allowLateVi']===false,'Old roster silently releases VI');
    $start = static function(array $proposal) use($vi):string { foreach($proposal['sessions'] as $session) if($session['moduleKey']===$vi) return $session['startTime']; throw new RuntimeException('VI missing'); };
    $default = $generator->preview($p['id'],$rounds,staffingMode:'lesson_only');
    $afternoon = $generator->preview($p['id'],$rounds,staffingMode:'lesson_only',lessonPreferences:$late);
    $assert($start($default)==='10:15','Default VI no longer first');
    $assert($start($afternoon)>='12:00' && $afternoon['hardIssues']===[],'Released lesson-only VI not placed in afternoon');
    $assert($s['raw']->load($p['id'])['sessions']===[],'Preview wrote lessons');

    $db->pdo->exec("INSERT INTO roster_staff_members(display_name,is_active,can_guide,can_cook) VALUES ('Allround begeleider',1,1,0)"); $guide=(int)$db->pdo->lastInsertId();
    foreach($keys as $i=>$key) $db->pdo->prepare('INSERT INTO roster_staff_module_preferences(staff_id,module_key,preference_rank) VALUES (?,?,?)')->execute([$guide,$key,$i+1]);
    $db->pdo->exec("INSERT INTO roster_staff_members(display_name,is_active,can_guide,can_cook) VALUES ('Kok',1,0,1)"); $cook=(int)$db->pdo->lastInsertId();
    $staffed = $generator->preview($p['id'],$rounds,[$guide],cookStaffId:$cook,lessonPreferences:$late);
    $assert($start($staffed)>='12:00' && $staffed['canFinalize'],'Staffed VI afternoon invalid: ' . json_encode(['vi'=>$start($staffed),'issues'=>$staffed['hardIssues'],'staff'=>$staffed['staffing']],JSON_UNESCAPED_UNICODE));
    $generator->apply($p['id'],$p['revision'],$rounds,true,1,[$guide],cookStaffId:$cook,lessonPreferences:$late);
    $saved = $s['service']->getPlan($p['id']);
    $assert($saved['planning']['lessonPreferences']['allowLateVi']===true,'Staffed preference not persisted');
    $assert($s['validator']->validate($p['id'],$saved['revision'])->errors===[],'Afternoon breaks cook/staff rules');
    // An unchecked setting freezes the actual saved VI time, including an already-afternoon VI.
    $locked = $generator->preview($p['id'],[],[$guide],cookStaffId:$cook,lessonPreferences:$normal,useExistingLessons:true,optimizeExistingLessons:true);
    $assert($start($locked)===$start($staffed),'Locked existing VI shifted');
    $releasedEarly = [...$normal,'allowLateVi'=>true];
    $moved = $generator->preview($p['id'],[],[$guide],cookStaffId:$cook,lessonPreferences:$releasedEarly,useExistingLessons:true,optimizeExistingLessons:true);
    $assert($start($moved)==='10:15' && $moved['optimization']['changes']!==[],'Guided permission does not release VI');
    $before=$s['raw']->load($p['id']);
    try {
        $generator->apply($p['id'],$saved['revision'],[],true,1,[$guide],cookStaffId:$cook,lessonPreferences:$normal,useExistingLessons:true,expectedStaffingSignature:$moved['staffingSignature'],optimizeExistingLessons:true);
        throw new RuntimeException('Changed permission accepted without new review');
    } catch(RosterPlanException $e) { $assert($e->publicCode==='STAFFING_PREVIEW_CHANGED','Wrong stale permission rejection'); }
    $assert($before===$s['raw']->load($p['id']),'Rejected change wrote existing lessons');
    $generator->apply($p['id'],$saved['revision'],[],true,1,[$guide],cookStaffId:$cook,lessonPreferences:$releasedEarly,useExistingLessons:true,expectedStaffingSignature:$moved['staffingSignature'],optimizeExistingLessons:true);
    $current=$s['service']->getPlan($p['id']);
    $assert($current['planning']['lessonPreferences']['allowLateVi']===true,'Guided permission not persisted');
    $assert($s['validator']->validate($p['id'],$current['revision'])->errors===[],'Guided change invalid');
    echo "PASS VI permission: default/legacy locked, afternoon lesson-only and staffed, saved permission, guided shifts, stale review and cook/staff validation\n";
} finally { $db->drop(); }

<?php
declare(strict_types=1);
require __DIR__.'/Support/RosterLifecycleFixtures.php';
use GeoFort\Services\Dashboard\Roster\{RosterAutoGenerator,RosterStaffOptimizer,RosterPlanException};
$db=DisposableBookingMariaDb::create('personnel_priority');$assert=RosterLifecycleFixtures::assert(...);
try {
 RosterLifecycleFixtures::schema($db->pdo);$s=RosterLifecycleFixtures::services($db->pdo);
 $add=function(string $name,array $keys,bool $cook=false)use($db){
  $db->pdo->prepare('INSERT INTO roster_staff_members(display_name,is_active,can_guide,can_cook) VALUES (?,1,1,?)')->execute([$name,(int)$cook]);$id=(int)$db->pdo->lastInsertId();
  foreach($keys as $i=>$key)$db->pdo->prepare('INSERT INTO roster_staff_module_preferences(staff_id,module_key,preference_rank) VALUES (?,?,?)')->execute([$id,$key,$i+1]);return $id;
 };
 $guide=$add('Mandatory specialist',['Dynamische-Globe','Earth-Watch','Klimaat-Experience','Klimparcours']);
 $irrelevant=$add('Unrelated skill',['Minecraft-Programmeren']);
 $optimizer=new RosterStaffOptimizer($s['staff']);
 foreach([null,'day-four-2027'] as $version)foreach(['Klimaat-Experience','Klimparcours'] as $optional){
  $session=fn($key)=>['moduleKey'=>$key,'groupIds'=>[1],'startTime'=>'10:15','endTime'=>'11:00','programVersion'=>$version,'minimumGeoFortStaff'=>$s['config']->requiredGeoFortGuidesForSession($key,1,null,$version),'schoolSupervisionAllowed'=>$s['config']->schoolSupervisionAllowed($key)];
  $result=$optimizer->optimize([$session($optional),$session('Dynamische-Globe')],[$guide]);
  $assert($result['unfilledRequiredAssignments']===0 && $result['assignments'][1]===[$guide] && in_array(0,$result['schoolFallbackSessionIndexes'],true),'Hard role lost to optional '.$optional);
 }
 $generator=new RosterAutoGenerator($db->pdo,$s['bookings'],$s['plans'],$s['sessions'],$s['config'],$s['templates'],$s['staff'],$optimizer);
 $p=$s['service']->createManual('Preference regression','Test','2028-11-28','primairOnderwijs','dag',18,1,1)['plan'];
 $s['plans']->saveManualModuleKeys($p['id'],['Dynamische-Globe','Earth-Watch']);$p=$s['service']->getPlan($p['id']);$rounds=$p['planning']['generation']['defaultRounds'];
 foreach([['Dynamische-Globe','Earth-Watch'],['Earth-Watch','Dynamische-Globe']] as $order){
  $result=$generator->preview($p['id'],$rounds,[$guide],lessonPreferences:['moduleOrder'=>$order,'supervision'=>[]]);
  $assert($result['canFinalize'],'Ranked proposal incomplete');
  $first=array_values(array_filter($result['sessions'],fn($s)=>$s['startTime']===$rounds[0]['startTime']))[0];
  $assert($first['moduleKey']===$order[0],'Staffed generation ignored order');
 }
 try{$generator->preview($p['id'],$rounds,[$irrelevant]);throw new RuntimeException('Unqualified selected guide accepted');}catch(RosterPlanException $e){$assert(str_contains($e->getMessage(),'vaardigheid'),'Wrong qualification error');}
 $assert($s['sessions']->findByPlanId($p['id'])===[],'Preview modified roster');
 echo "PASS mandatory staffing before KE/VS for four/five modules; module-order preferences affect staffed timetable; irrelevant skills rejected; previews read-only\n";
}finally{$db->drop();}

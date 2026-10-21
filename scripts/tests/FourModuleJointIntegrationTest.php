<?php
declare(strict_types=1);
require __DIR__ . '/Support/RosterLifecycleFixtures.php';
use GeoFort\Booking\FourModuleProgram;
use GeoFort\Services\Dashboard\Roster\{RosterAutoGenerator,RosterStaffOptimizer,RosterPairService,RosterJointOptimizationService,RosterXlsxWriter};
$db = DisposableBookingMariaDb::create('four_joint');
$checks=0;
$assert=static function(bool $ok,string $message)use(&$checks):void{if(!$ok)throw new RuntimeException($message);$checks++;};
try {
 $pdo=$db->pdo; RosterLifecycleFixtures::schema($pdo); $s=RosterLifecycleFixtures::services($pdo);
 $generator=new RosterAutoGenerator($pdo,$s['bookings'],$s['plans'],$s['sessions'],$s['config'],$s['templates'],$s['staff'],new RosterStaffOptimizer($s['staff']));
 foreach(['four','five','morning'] as $case=>$variant) {
  $date='2027-03-'.(10+$case);$plans=[];$crews=[];$cooks=[];
  foreach([0,1] as $school) {
   $version=$school===0 || $variant==='four'?FourModuleProgram::VERSION:null;
   $program=$school===1 && $variant==='morning'?'ochtend':'dag';
   $choice=$program==='ochtend'?null:'Minecraft-Klimaatspeurtocht';
   $pdo->prepare("INSERT INTO aanvragen(status,schoolnaam,land,adres,postcode,plaats,school_telefoonnummer,contactpersoon_telefoonnummer,contactpersoon_voornaam,contactpersoon_achternaam,email,bezoekdatum,onderwijs_sector,programma,program_version,keuzemodule_key,aantal_leerlingen,aantal_begeleiders,voorwaarden_akkoord) VALUES ('Definitief',?,'Nederland','Testweg 1','1234 AB','Testplaats','0100000000','0612345678','Test','Planner','test@example.test',?,'primairOnderwijs',?,?,?,40,4,1)")->execute(["Combinatie {$variant} school {$school}",$date,$program,$version,$choice]);
   $bid=(int)$pdo->lastInsertId();
   $pdo->prepare("INSERT INTO aanvraag_onderwijs_selecties(aanvraag_id,sector_key,sector_label,level_key,level_label,level_position,group_key,group_label,group_position) VALUES (?,'primairOnderwijs','PO','regulier','Regulier',1,'groep8','Groep 8',1)")->execute([$bid]);
   $plans[]=$s['service']->createFromBooking($bid,1)['plan'];
  }
  $pairService=new RosterPairService($pdo,$s['service']);
  $pairService->link(array_fill_keys(array_column($plans,'id'),1),'lessons_first',1,true);
  foreach($plans as $school=>$old) {
   $plan=$s['service']->getPlan($old['id']); $crew=[];
   foreach($plan['planning']['modules'] as $module)for($i=0;$i<2;$i++) {
    $pdo->prepare('INSERT INTO roster_staff_members(display_name,is_active,can_guide,can_cook) VALUES (?,1,1,0)')->execute(["{$variant} {$school} {$module['key']} {$i}"]);
    $crew[]=$sid=(int)$pdo->lastInsertId();
    $pdo->prepare('INSERT INTO roster_staff_module_preferences(staff_id,module_key,preference_rank) VALUES (?,?,1)')->execute([$sid,$module['key']]);
   }
   $pdo->prepare('INSERT INTO roster_staff_members(display_name,is_active,can_guide,can_cook) VALUES (?,1,0,1)')->execute(["Kok {$variant} {$school}"]);
   $cook=(int)$pdo->lastInsertId();
   // Linked food groups use the same existing guide and cook, while the
   // remaining modules continue to receive separate concurrent guides.
   if($school===1 && $program!=='ochtend') {
    $crew=array_values(array_unique([...$crew,...$crews[0]]));
    $cook=$cooks[0];
   }
   $crews[$school]=$crew;$cooks[$school]=$cook;
   $rounds=$plan['planning']['generation']['defaultRounds'];
   $preview=$generator->preview($plan['id'],$rounds,$crew,'with_staff',true,$cook);
   $assert($preview['canApply'],"{$variant}: proposal failed ".json_encode($preview['hardIssues']??$preview));
   $generator->apply($plan['id'],$plan['revision'],$rounds,false,1,$crew,'with_staff',true,$cook);
   $plans[$school]=$s['service']->getPlan($plan['id']);
  }
  $revisions=array_column($plans,'revision','id');
  $report=$s['validator']->validatePlans($revisions);
  $assert($report['canFinalize'],"{$variant}: joint validation failed ".json_encode($report));
  $optimization=(new RosterJointOptimizationService($pdo))->run($revisions,null,1);
  $assert(isset($optimization['signature']),"{$variant}: no optimization preview");
  if($optimization['improved']) {
   $applied=(new RosterJointOptimizationService($pdo))->run($revisions,$optimization['signature'],1);
   $assert($applied['applied'],"{$variant}: optimization not applied");
   foreach($revisions as &$revision)$revision++;unset($revision);
  }
  $overridePlanId = $plans[0]['id'];
  $overrideData = $s['raw']->load($overridePlanId);
  $overrideSession = array_values(array_filter($overrideData['sessions'], static fn($row) => $row['module_key'] === 'Klimparcours'))[0];
  $overrideLink = array_values(array_filter($overrideData['links'], static fn($row) => $row['roster_session_id'] === $overrideSession['id']))[0];
  $saved = $s['sessionService']->saveGroupStaffAssignment($overridePlanId, $overrideSession['id'], $revisions[$overridePlanId], $overrideLink['roster_group_id'], [], 1, 'school');
  $revisions[$overridePlanId] = $saved['revision'];
  $preview=$s['lifecycle']->joint($revisions,'preview',null,false,1);
  $assert(in_array('SCHOOL_SUPERVISION_OVERRIDE',array_column($preview['warnings'],'code'),true), 'Joint school override warning missing');
  try { $s['lifecycle']->joint($revisions,'finalize',$preview['signature'],false,1); throw new RuntimeException('Missing warning consent accepted'); }
  catch (\GeoFort\Services\Dashboard\Roster\RosterPlanException $e) { $assert($e->publicCode === 'WARNINGS_NOT_ACCEPTED','Unexpected refusal'); }
  $done=$s['lifecycle']->joint($revisions,'finalize',$preview['signature'],true,1);
  $assert($done['applied'],"{$variant}: joint finalization failed");
  $frozen=array_map(fn(array $p):array=>$s['service']->getPlan($p['id']),$plans);
  $output=getenv('FOUR_MODULE_OUTPUT');
  if($output)(new RosterXlsxWriter())->writeJoint($output.'/four-plus-'.$variant.'.xlsx',$frozen);
  foreach($frozen as $p)$assert($p['finalization']['trusted'],"{$variant}: untrusted final");
  echo "PASS: four + {$variant} generation, optimization and joint publication\n";
 }
 echo "PASS: {$checks} joint integration checks\n";
} finally {$db->drop();}

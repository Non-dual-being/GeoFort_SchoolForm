<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/vendor/autoload.php';
use GeoFort\Services\Dashboard\Roster\{RosterDayRules,RosterPlanningConfig,RosterPlanException};
$rules=new RosterDayRules(new RosterPlanningConfig());$checks=0;
function assertDay(bool $ok,string $message):void{global $checks;$checks++;if(!$ok)throw new RuntimeException($message);}
function slot(int $id,string $module,int $groups,array $staff=[],string $start='10:15',string $end='11:00'):array{return ['sessionId'=>$id,'moduleKey'=>$module,'groupCount'=>$groups,'groupIds'=>range($id*10,$id*10+$groups-1),'staffIds'=>$staff,'startTime'=>$start,'endTime'=>$end];}
function school(int $id,array $slots,int $revision=1,?int $cook=null):array{return ['planId'=>$id,'revision'=>$revision,'schoolName'=>'School '.$id,'visitDate'=>'2028-10-10','cookStaffId'=>$cook,'occupancy'=>$slots];}
function has(array $report,string $code):bool{return in_array($code,array_column($report['errors'],'code'),true);}
$a=school(1,[slot(1,'Earth-Watch',2)]);$b=school(2,[slot(2,'Earth-Watch',1)]);
$r=$rules->validate([$a,$b]);assertDay(has($r,'MODULE_CAPACITY')&&!$r['validLessons'],'Three Earth groups across schools must fail');
assertDay($r['errors'][0]['groupCount']===3&&count($r['errors'][0]['schools'])===2,'Capacity diagnostics must identify both schools and groups');
$b['occupancy'][0]['startTime']='11:00';$b['occupancy'][0]['endTime']='11:45';assertDay($rules->validate([$a,$b])['validLessons'],'Touching time boundaries do not overlap');
$b['occupancy'][0]['startTime']='10:45';assertDay(!$rules->validate([$a,$b])['validLessons'],'Unequal overlapping intervals must count');
assertDay($rules->validate([school(1,[slot(1,'Klimparcours',2)]),school(2,[slot(2,'Klimparcours',1)])])['validLessons'],'Three VS groups fit');
assertDay(!$rules->validate([school(1,[slot(1,'Klimparcours',2)]),school(2,[slot(2,'Klimparcours',2)])])['validLessons'],'Four VS groups fail');
assertDay(!$rules->validate([school(1,[slot(1,'Klimaat-Experience',2)]),school(2,[slot(2,'Klimaat-Experience',1)])])['validLessons'],'Three KE groups fail');
$pair=[school(1,[slot(1,'Earth-Watch',1,[5])]),school(2,[slot(2,'Stop-de-Klimaat-Klok',1,[5])])];
assertDay(has($rules->validate($pair,true),'STAFF_DAY_OVERLAP'),'Different modules can never share a guide');
$pair[1]['occupancy'][0]['moduleKey']='Earth-Watch';
assertDay(has($rules->validate($pair),'STAFF_DAY_OVERLAP'),'Sharing defaults to forbidden');
assertDay(has($rules->validate($pair,true),'STAFF_DAY_OVERLAP'),'Only food may share across schools, even with legacy consent');
assertDay($rules->signature(array_reverse($pair))===$rules->signature($pair),'Plan ordering does not invalidate identical consent');
$changed=$pair;$changed[1]['occupancy'][0]['startTime']='10:30';assertDay(has($rules->validate($changed,true),'STAFF_DAY_OVERLAP'),'Sharing requires identical full time intervals');
$changed=$pair;$changed[1]['occupancy'][0]['groupCount']=2;assertDay(has($rules->validate($changed,true),'STAFF_DAY_OVERLAP')&&has($rules->validate($changed,true),'MODULE_CAPACITY'),'Sharing never overrides combined group capacity');
$changed=$pair;foreach($changed as &$p)$p['occupancy'][0]['moduleKey']='Dynamische-Globe';unset($p);assertDay(has($rules->validate($changed,true),'STAFF_DAY_OVERLAP'),'DG requires two guides for two groups');
$daily=[school(1,[slot(1,'Earth-Watch',1,[5]),slot(2,'Stop-de-Klimaat-Klok',1,[5],'11:00','11:45')]),school(2,[slot(3,'Dynamische-Globe',1,[5],'12:00','12:45'),slot(4,'Klimaat-Experience',1,[5],'13:00','13:45')])];
assertDay(!has($rules->validate($daily),'STAFF_DAY_MODULE_LIMIT'),'Four distinct modules across schools are allowed');
$daily[1]['occupancy'][]=slot(5,'Klimparcours',1,[5],'14:00','14:45');
assertDay(has($rules->validate($daily),'STAFF_DAY_MODULE_LIMIT'),'A fifth distinct module is rejected');
$daily[1]['occupancy'][1]['moduleKey']='Earth-Watch';assertDay(!has($rules->validate($daily),'STAFF_DAY_MODULE_LIMIT'),'Repeated module counts once per day');
$cooking=[school(1,[slot(1,'Earth-Watch',1,[5]),slot(2,'Stop-de-Klimaat-Klok',1,[5],'11:00','11:45'),slot(3,'Dynamische-Globe',1,[5],'12:00','12:45')]),school(2,[slot(4,'Voedsel-Innovatie',1,[5,6],'13:00','13:45')],1,5)];
assertDay(!has($rules->validate($cooking),'STAFF_DAY_MODULE_LIMIT'),'Cooking is not a fourth guide module');
$cooking[1]['occupancy'][0]['startTime']='10:30';$cooking[1]['occupancy'][0]['endTime']='11:15';assertDay(has($rules->validate($cooking,true),'STAFF_DAY_OVERLAP'),'Cooking cannot overlap guiding');
$external=school(3,[slot(3,'Earth-Watch',1,[5])]);assertDay(has($rules->validate([$pair[0],$external],true,null,[1,2]),'STAFF_DAY_OVERLAP'),'Consent cannot extend to an unselected school');
$unrelated=school(3,[slot(3,'Klimparcours',4)]);assertDay($rules->validate([$pair[0],$unrelated],false,null,[1])['validLessons'],'Unrelated external violation does not block the selected school');
foreach([[$pair[0],$pair[0]],[$pair[0],array_replace($pair[1],['visitDate'=>'2028-10-11'])]]as $bad){try{$rules->validate($bad);assertDay(false,'Invalid day identity accepted');}catch(RosterPlanException){assertDay(true,'Invalid identity rejected');}}
$bad=$pair;$bad[0]['occupancy'][0]['staffIds']=[-2];try{$rules->validate($bad);assertDay(false,'Invalid guide ID accepted');}catch(RosterPlanException){assertDay(true,'Invalid guide rejected');}
echo "PASS: $checks joint day rule checks; capacity, overlaps, day module limit, guide exception and stale confirmation\n";

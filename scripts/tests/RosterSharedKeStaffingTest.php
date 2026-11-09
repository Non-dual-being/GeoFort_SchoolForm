<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/vendor/autoload.php';
use GeoFort\Services\Dashboard\Roster\{RosterSharedKeStaffing,RosterJointStaffOptimizer,RosterDayRules,RosterPlanningConfig};
$checks=0;$assert=function(bool $ok,string $message)use(&$checks):void{$checks++;if(!$ok)throw new RuntimeException($message);};
$a=['planId'=>11,'sessionId'=>21,'moduleKey'=>'Klimaat-Experience','startTime'=>'12:15','endTime'=>'13:00','groupIds'=>[31],'groupCount'=>1,'staffIds'=>[1],'staffId'=>1,'cookStaffId'=>null,'cook'=>false,'availability'=>[]];
$b=[...$a,'sessionId'=>22,'groupIds'=>[32]];
$assert(RosterSharedKeStaffing::allows([$a,$b],1),'Separate same-school KE sessions refused');
$assert(RosterSharedKeStaffing::allows([[...$a,'groupCount'=>2],[...$b,'sessionId'=>21,'groupCount'=>2]],1),'Tasks from one KE session refused');
$negatives=[
 'other school'=>[$a,[...$b,'planId'=>12]],
 'third separate group'=>[$a,$b,[...$b,'sessionId'=>23,'groupIds'=>[33]]],
 'third bundled group'=>[$a,[...$b,'groupCount'=>2,'groupIds'=>[32,33]]],
 'partial overlap'=>[$a,[...$b,'startTime'=>'12:30']],
 'unequal end'=>[$a,[...$b,'endTime'=>'12:45']],
 'same group'=>[$a,[...$b,'groupIds'=>[31]]],
 'other module'=>[$a,[...$b,'moduleKey'=>'Dynamische-Globe']],
 'two Globe lessons'=>[[...$a,'moduleKey'=>'Dynamische-Globe'],[...$b,'moduleKey'=>'Dynamische-Globe']],
 'cook'=>[$a,[...$b,'cookStaffId'=>1,'cook'=>true]],
 'missing group'=>[$a,[...$b,'groupIds'=>[]]],
 'three-group session'=>[[...$a,'groupCount'=>3],$b],
];
$optimizer=new RosterJointStaffOptimizer();
foreach($negatives as $label=>$rows){
 $assert(!RosterSharedKeStaffing::allows($rows,1),$label.' accepted by shared rule');
 if(!in_array($label,['same group','missing group','three-group session'],true))$assert(!$optimizer->valid($rows,array_fill(0,count($rows),1)),$label.' accepted by optimizer');
}
$day=fn($rows)=>(new RosterDayRules(new RosterPlanningConfig()))->validate([['planId'=>11,'revision'=>1,'visitDate'=>'2027-03-03','schoolName'=>'Testschool','occupancy'=>$rows]]);
$assert($day([$a,$b])['canFinalize'],'Day validation rejects same-school KE sharing');
$assert(!$day($negatives['third separate group'])['canFinalize'],'Day validation accepts three KE groups');
$assert(!$day($negatives['partial overlap'])['canFinalize'],'Day validation accepts partial sharing');
$assert(!$day($negatives['two Globe lessons'])['canFinalize'],'Day validation accepts shared Globe teacher');
$twoSchools=(new RosterDayRules(new RosterPlanningConfig()))->validate([
 ['planId'=>11,'revision'=>1,'visitDate'=>'2027-03-03','schoolName'=>'School A','pairId'=>1,'occupancy'=>[$a]],
 ['planId'=>12,'revision'=>1,'visitDate'=>'2027-03-03','schoolName'=>'School B','pairId'=>1,'occupancy'=>[[...$b,'planId'=>12]]],
]);
$assert(!$twoSchools['canFinalize'],'Linked schools incorrectly share KE guide');
echo "PASS: {$checks} shared KE rule, daily validation and optimizer boundary checks\n";

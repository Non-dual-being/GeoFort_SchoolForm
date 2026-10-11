<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/vendor/autoload.php';
use GeoFort\Services\Dashboard\Roster\{RosterPlanningConfig,RosterDayRules,RosterPairedLessonSearch,RosterJointStaffOptimizer};
$assert=static function(bool $ok,string $why):void{if(!$ok)throw new RuntimeException($why);};
$config=new RosterPlanningConfig();$day=new RosterDayRules($config);$search=new RosterPairedLessonSearch($config);
$row=static fn(string $m,int $n=1):array=>['sessionId'=>1,'moduleKey'=>$m,'groupCount'=>$n,'groupIds'=>range(1,$n),'groupLabels'=>['Groep1'],'staffIds'=>[],'startTime'=>'10:15','endTime'=>'10:35'];
$plan=static fn(int $id,array $rows):array=>['planId'=>$id,'revision'=>1,'visitDate'=>'2027-11-10','schoolName'=>'School '.$id,'cookStaffId'=>null,'occupancy'=>$rows];
$a=[$row('Zandtafel'),$row('Rising-Risk'),$row('Expedition-Earth')];
$assert($day->validate([$plan(1,$a),$plan(2,[$row('Dynamische-Globe')])])['validLessons'],'Three separate morning stations are valid.');
$assert(!$day->validate([$plan(1,$a),$plan(2,[$row('Klimaat-Experience',2)])])['validLessons'],'Two KE groups block morning stations.');
$assert($search->score($a,[$row('Klimaat-Experience',2)])>0,'Search must penalize blocked stations.');
$assert($search->score($a,[$row('Dynamische-Globe')])===0,'Unrelated station not blocked.');
$assert($search->score([$row('Dynamische-Globe'),$row('Dynamische-Globe-Bios')],[$row('Dynamische-Globe')])>0,'Combined Globe groups count across variants and schools.');
$members=[];foreach([1,2] as $id)$members[]=['id'=>$id,'name'=>'Guide '.$id,'isActive'=>true,'canGuide'=>true,'canCook'=>false,'employmentType'=>'paid','preferences'=>[['moduleKey'=>'Klimaat-Experience','rank'=>1],['moduleKey'=>'Dynamische-Globe','rank'=>2]]];
$tasks=[];
foreach([['10:15','10:35'],['10:35','10:55'],['11:10','11:30']] as $r=>[$start,$end])foreach([1,2] as $gid){
    $tasks[]=['staffId'=>$r===1?3-$gid:$gid,'planId'=>1,'groupIds'=>[$gid],'morning'=>true,'moduleKey'=>['Zandtafel','Rising-Risk','Dynamische-Globe-Bios'][$r],'cook'=>false,'startTime'=>$start,'endTime'=>$end,'availability'=>[]];
}
$optimizer=new RosterJointStaffOptimizer();$answer=$optimizer->optimize($tasks,$members);
$assert($answer['before']['morningGroupChanges']===2,'Fixture must contain two unnecessary guide switches.');
$assert($answer['after']['morningGroupChanges']===0,'Joint optimization should restore each morning group guide.');
$assert($answer['after']['paidGapMinutes']===0,'Continuity must not create paid gaps.');
$gapTasks=[$tasks[0],$tasks[2],$tasks[4]];
$gapTasks[0]['staffId']=1;$gapTasks[1]['staffId']=2;$gapTasks[2]['staffId']=1;
$gapTasks[1]['planId']=2;$gapTasks[1]['groupIds']=[8];
$gapResult=$optimizer->optimize($gapTasks,$members);
$assert($gapResult['before']['paidGapMinutes']===20 && $gapResult['after']['paidGapMinutes']===0,'Paid gap removal takes priority across schools.');
echo "PASS: shared morning capacities, paired proposal scoring, joint group continuity and paid-gap priority.\n";

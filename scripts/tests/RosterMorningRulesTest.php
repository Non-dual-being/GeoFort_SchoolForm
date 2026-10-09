<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';
use GeoFort\Services\Dashboard\Roster\{RosterPlanningConfig,RosterModuleCapacityService,RosterValidationResult,RosterStaffOptimizer};
use GeoFort\Services\Sql\{RosterValidationSqlRepository,RosterSnapshotSqlRepository,RosterStaffSqlRepository};
$pdo=new PDO('sqlite::memory:');
$config=new RosterPlanningConfig();
$capacity=new RosterModuleCapacityService(new RosterValidationSqlRepository($pdo),new RosterSnapshotSqlRepository($pdo),$config);
$row=static fn(string $m,int $n=1,string $start='10:15',string $end='10:35',int $plan=1):array=>['planId'=>$plan,'moduleKey'=>$m,'groupCount'=>$n,'startTime'=>$start,'endTime'=>$end];
$assert=static function(bool $ok,string $why):void {if(!$ok)throw new RuntimeException($why);};
$check=static function(array $rows,bool $expected)use($capacity,$assert):void {
    $result=new RosterValidationResult(1,1);$capacity->validateOccupancy($rows,$result,1);
    $assert($result->isValid()===$expected,json_encode($result->toArray()));
};
$check([$row('Zandtafel'),$row('Rising-Risk'),$row('Expedition-Earth')],true);
foreach(['Zandtafel','Rising-Risk','Expedition-Earth'] as $m){
    $check([$row($m,2)],false);
    $check([$row($m),$row($m,1,'10:25','10:45',2)],false);
    $check([$row($m),$row('Klimaat-Experience',2,'10:15','10:35',2)],false);
    $check([$row($m),$row('Klimaat-Experience',2,'10:35','10:55',2)],true);
}
$check([$row('Dynamische-Globe'),$row('Dynamische-Globe-Bios')],true);
$check([$row('Dynamische-Globe',2),$row('Dynamische-Globe-Bios')],false);
$assert($config->requiredGeoFortGuidesForSession('Dynamische-Globe',2,false)===2,'Two Globe groups require two guides.');
$assert(in_array('Expedition-Earth',$config::qualifiedModuleKeys(['Klimaat-Experience']),true),'KE skills cover morning variants.');
$assert(!in_array('Voedsel-Innovatie',$config::qualifiedModuleKeys(['Klimaat-Experience']),true),'Unrelated skills stay separate.');
$pdo->exec('CREATE TABLE roster_staff_members(id INTEGER,display_name TEXT,is_active INTEGER,employment_type TEXT,can_guide INTEGER,can_cook INTEGER,version INTEGER,notes TEXT)');
$pdo->exec('CREATE TABLE roster_staff_module_preferences(staff_id INTEGER,module_key TEXT,preference_rank INTEGER)');
$pdo->exec('CREATE TABLE roster_staff_cost_rates(id INTEGER,staff_id INTEGER,valid_from TEXT,valid_to TEXT,hourly_cost_cents INTEGER)');
foreach(range(1,5) as $id){
    $pdo->exec("INSERT INTO roster_staff_members VALUES($id,'Guide $id',1,'paid',1,0,1,NULL)");
    $pdo->exec("INSERT INTO roster_staff_module_preferences VALUES($id,'Klimaat-Experience',1),($id,'Dynamische-Globe',2)");
}
$optimizer=new RosterStaffOptimizer(new RosterStaffSqlRepository($pdo));
$modules=['Zandtafel','Rising-Risk','Dynamische-Globe','Dynamische-Globe-Bios','Expedition-Earth'];
$rounds=[['10:15','10:35'],['10:35','10:55'],['11:10','11:30'],['11:30','11:50'],['11:50','12:10']];
foreach([2,3,4,5] as $groups){
    $sessions=[];
    foreach($rounds as $r=>[$start,$end])foreach(range(1,$groups) as $gid){
        $m=$modules[($gid-1+$r)%5];
        $sessions[]=['moduleKey'=>$m,'groupIds'=>[$gid],'startTime'=>$start,'endTime'=>$end,'minimumGeoFortStaff'=>$config->minimumGeoFortStaff($m),'schoolSupervisionAllowed'=>$config->schoolSupervisionAllowed($m)];
    }
    $answer=$optimizer->optimize($sessions,range(1,$groups));$groupTeachers=[];
    $assert($answer['unfilledRequiredAssignments']===0,'Morning staffing must be complete.');
    $assert($answer['schoolFallbackSessionIndexes']===[],'Available qualified guides should cover morning.');
    foreach($answer['assignments'] as $i=>$ids)foreach($ids as $id)$groupTeachers[$sessions[$i]['groupIds'][0]][$id]=true;
    foreach($groupTeachers as $teachers)$assert(count($teachers)===1,"Keep each of $groups groups with one guide through all five modules.");
}
echo "PASS: morning station limits, cross-school KE blocking, Globe shared capacity, inherited skills and 2/3/4/5-group continuity.\n";

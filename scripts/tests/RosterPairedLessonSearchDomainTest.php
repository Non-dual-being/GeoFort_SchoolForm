<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/vendor/autoload.php';
use GeoFort\Services\Dashboard\Roster\{RosterPairedLessonSearch,RosterPlanningConfig};
$config=new RosterPlanningConfig();$search=new RosterPairedLessonSearch($config);
$assert=static function(bool $yes,string $message):void { if(!$yes) throw new RuntimeException($message); };
$modules=['Earth-Watch','Stop-de-Klimaat-Klok','Voedsel-Innovatie'];
$rounds=[['startTime'=>'10:00','endTime'=>'10:30'],['startTime'=>'10:30','endTime'=>'11:00'],['startTime'=>'11:00','endTime'=>'11:30']];
$permutations=[[$modules[0],$modules[1],$modules[2]],[$modules[0],$modules[2],$modules[1]],[$modules[1],$modules[0],$modules[2]],[$modules[1],$modules[2],$modules[0]],[$modules[2],$modules[0],$modules[1]],[$modules[2],$modules[1],$modules[0]]];
$toProposal=static function(array $matrix) use($rounds):array {
 $result=[];foreach($matrix as $g=>$row)foreach($row as $r=>$module)$result[]=[...$rounds[$r],'moduleKey'=>$module,'groupIds'=>[$g+1],'groupLabels'=>['Groep '.($g+1)]];return $result;
};
// Independent exhaustive oracle: enumerate every assignment, then count conflicts minute by minute.
$oracle=static function(array $matrix,array $fixed)use($modules):?int {
 $counts=[];foreach($matrix as $row)foreach($row as $r=>$module)$counts[$r][$module]=($counts[$r][$module]??0)+1;
 foreach($counts as $row)foreach($row as $count)if($count>2)return null;
 $cost=0;
 for($minute=600;$minute<690;$minute++)foreach($modules as $module) {
  $own=$counts[intdiv($minute-600,30)][$module]??0;$other=0;
  foreach($fixed as $s){$a=(int)substr($s['startTime'],0,2)*60+(int)substr($s['startTime'],3,2);$b=(int)substr($s['endTime'],0,2)*60+(int)substr($s['endTime'],3,2);if($s['moduleKey']===$module&&$a<=$minute&&$b>$minute)$other+=$s['groupCount'];}
  $cost+=max(0,$own+$other-2)-max(0,$other-2);
 }return $cost;
};
for($case=0;$case<12;$case++) {
 $fixed=[];
 foreach($modules as $i=>$module)$fixed[]=['moduleKey'=>$module,'startTime'=>['10:00','10:15','10:40'][($i+$case)%3],'endTime'=>['10:45','11:00','11:20'][($i+$case)%3],'groupCount'=>($case+$i)%3];
 $minimum=PHP_INT_MAX;
 foreach($permutations as $a)foreach($permutations as $b)foreach($permutations as $c){$cost=$oracle([$a,$b,$c],$fixed);if($cost!==null)$minimum=min($minimum,$cost);}
 $matrix=$search->solve(3,$rounds,$modules,$fixed);
 $assert(count($matrix)===3,'Missing groups');
 foreach($matrix as $row){sort($row);$expected=$modules;sort($expected);$assert($row===$expected,'Each module must occur exactly once per group');}
 $assert($oracle($matrix,$fixed)===$minimum,'Search did not find the independently proven minimum');
 $assert($search->score($toProposal($matrix),$fixed)===$minimum,'Score differs from independent minute oracle');
 $assert($matrix===$search->solve(3,$rounds,$modules,$fixed),'Search is not deterministic');
}
$assert($search->solve(7,$rounds,$modules,[])===[],'Impossible own module capacities accepted');
echo "PASS paired search: twelve exhaustive independent optimality checks, partial overlaps, capacity, coverage and deterministic results\n";

// Independent exhaustive comparison of [capacity excess, rank 1, rank 2, rank 3].
// Uses the permissive search as well, proving rank weights never override capacity.
$rankScore = static function (array $matrix, array $order): array {
    $result = [];
    foreach ($order as $module) {
        $sum = 0;
        foreach ($matrix as $row) $sum += array_search($module, $row, true);
        $result[] = $sum;
    }
    return $result;
};
foreach ([[], [['moduleKey'=>'Earth-Watch','startTime'=>'10:00','endTime'=>'10:45','groupCount'=>2]]] as $fixed) {
    foreach ($permutations as $order) {
        $minimum = null;
        foreach ($permutations as $a) foreach ($permutations as $b) foreach ($permutations as $c) {
            $matrix = [$a, $b, $c];
            $cost = $oracle($matrix, $fixed);
            if ($cost === null) continue;
            $rank = [$cost, ...$rankScore($matrix, $order)];
            if ($minimum === null || $rank < $minimum) $minimum = $rank;
        }
        $matrix = $search->solve(3, $rounds, $modules, $fixed, $order);
        $assert([$oracle($matrix, $fixed), ...$rankScore($matrix, $order)] === $minimum, 'Full ranking differs from exhaustive optimum');
    }
}
echo "PASS full module ranking matches twelve exhaustive optimality checks; hard capacity outweighs every preference\n";

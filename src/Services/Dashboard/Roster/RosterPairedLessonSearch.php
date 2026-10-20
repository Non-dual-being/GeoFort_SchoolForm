<?php
declare(strict_types=1);

namespace GeoFort\Services\Dashboard\Roster;

/** Fixed rounds, same required modules for every group: minimum-cost integral transport. */
final readonly class RosterPairedLessonSearch
{
    public function __construct(
        private RosterPlanningConfig $config,
        private bool $standardOnly = false,
    ) {}

    private function capacityPools(string $module): array
    {
        return $this->standardOnly
            ? $this->config->normalCapacityPools($module)
            : $this->config->capacityPools($module);
    }

    private function minutes(string $time): int
    {
        return (int)substr($time, 0, 2) * 60 + (int)substr($time, 3, 2);
    }

    /** Excess group-minutes caused by this school, respecting actual overlap boundaries. */
    private function cost(string $module, array $round, int $own, array $fixed): int
    {
        if ($own === 0) return 0;
        $start=$this->minutes($round['startTime']); $end=$this->minutes($round['endTime']); $cost=0;
        foreach ($this->capacityPools($module) as $pool=>$capacity) {
            $rows=[]; $points=[$start,$end];
            foreach ($fixed as $row) {
                if (!isset($this->capacityPools($row['moduleKey'])[$pool])) continue;
                $a=max($start,$this->minutes($row['startTime']));$b=min($end,$this->minutes($row['endTime']));
                if($a >= $b) continue;
                $rows[]=[$a,$b,(int)$row['groupCount']];$points[]=$a;$points[]=$b;
            }
            $points=array_values(array_unique($points));sort($points);
            for($i=0;$i<count($points)-1;$i++) {
                $occupied=0;
                foreach($rows as [$a,$b,$count]) if($a <= $points[$i] && $b > $points[$i]) $occupied+=$count;
                $cost+=(max(0,$occupied+$own-$capacity)-max(0,$occupied-$capacity))*($points[$i+1]-$points[$i]);
            }
        }
        return $cost;
    }

    public function score(array $proposal, array $fixed): int
    {
        $score=0;
        foreach($this->conflicts($proposal,$fixed) as $issue) $score+=max(0,$issue['groupCount']-$issue['maxGroups']-max(0,$issue['backgroundCount']-$issue['maxGroups']))*($this->minutes($issue['endTime'])-$this->minutes($issue['startTime']));
        return $score;
    }

    public function conflicts(array $proposal, array $fixed): array
    {
        $pools=[];$limits=[];$issues=[];
        foreach($proposal as $s) {
            $s['groupCount']=count($s['groupIds']);$s['own']=true;
            foreach($this->capacityPools($s['moduleKey']) as $pool=>$limit) {$pools[$pool][]=$s;$limits[$pool]=$limit;}
        }
        foreach($fixed as $s) {
            $s['own']=false;
            foreach($this->capacityPools($s['moduleKey']) as $pool=>$limit) if(isset($pools[$pool])) $pools[$pool][]=$s;
        }
        foreach($pools as $pool=>$rows) {
            $points=[];foreach($rows as $s){$points[]=$s['startTime'];$points[]=$s['endTime'];}
            $points=array_values(array_unique($points));sort($points);
            for($i=0;$i<count($points)-1;$i++) {
                $active=array_values(array_filter($rows,static fn(array $s):bool=>$s['startTime']<=$points[$i] && $s['endTime']>$points[$i]));
                $own=array_values(array_filter($active,static fn(array $s):bool=>$s['own']));
                if($own===[])continue;
                $module=str_starts_with($pool,'KE/')?substr($pool,3):$pool;
                if(str_starts_with($pool,'KE/') && !in_array($module,array_column($active,'moduleKey'),true))continue;
                $count=array_sum(array_column($active,'groupCount'));$max=$limits[$pool];if($count<=$max)continue;
                $labels=[];foreach($own as $s)$labels=[...$labels,...($s['groupLabels']??[])];
                $issues[]=['code'=>'MODULE_CAPACITY','message'=>"Gedeelde capaciteit overschreden: {$count} groepen tegelijk; maximaal {$max} toegestaan.",
                    'moduleKey'=>$module,'moduleLabel'=>$this->config->moduleOption($module)['label'],'groupCount'=>$count,'maxGroups'=>$max,'backgroundCount'=>$count-array_sum(array_column($own,'groupCount')),
                    'startTime'=>$points[$i],'endTime'=>$points[$i+1],'groupLabels'=>array_values(array_unique($labels)),
                    'requiredStaff'=>null,'assignedStaff'=>null,'eligibleStaffNames'=>[]];
            }
        }
        return $issues;
    }

    /** @return list<list<string>> One permutation of all modules per group. */
    public function solve(int $groups, array $rounds, array $moduleKeys, array $fixed): array
    {
        $n = count($rounds);
        if ($groups < 1 || $n !== count($moduleKeys) || $n < 2 || $n > 5) return [];
        $source = 2*$n; $sink = $source+1; $vertices = $sink+1;
        $edges = []; $cells = [];
        $add = static function(int $from,int $to,int $capacity,int $cost) use (&$edges): int {
            $id=count($edges);
            $edges[]=['from'=>$from,'to'=>$to,'cap'=>$capacity,'cost'=>$cost];
            $edges[]=['from'=>$to,'to'=>$from,'cap'=>0,'cost'=>-$cost];
            return $id;
        };
        // Soft early-VI preference can never outweigh even one excess group-minute.
        $scale = $groups*$n*$n+1;
        foreach ($rounds as $r=>$round) {
            $add($source,$r,$groups,0);
            foreach ($moduleKeys as $m=>$module) {
                $previous=0;
                $maximum = $this->standardOnly ? $this->config->normalMaxParallel($module) : $this->config->maxParallel($module);
                for ($k=1;$k<=min($groups,$maximum);$k++) {
                    $cost=$this->cost($module,$round,$k,$fixed);
                    if ($this->standardOnly && $cost > 0) break;
                    $edge=$add($r,$n+$m,1,($cost-$previous)*$scale+($module==='Voedsel-Innovatie'?$r:0));
                    $cells[$r][$m][]=$edge; $previous=$cost;
                }
            }
        }
        foreach ($moduleKeys as $m=>$_module) $add($n+$m,$sink,$groups,0);
        // Successive shortest residual paths; integral capacities yield integral assignments.
        for ($flow=0;$flow<$groups*$n;$flow++) {
            $distance=array_fill(0,$vertices,PHP_INT_MAX); $parent=[]; $distance[$source]=0;
            for ($iteration=0;$iteration<$vertices-1;$iteration++) {
                $changed=false;
                foreach ($edges as $id=>$edge) {
                    if ($edge['cap']<1 || $distance[$edge['from']]===PHP_INT_MAX) continue;
                    $candidate=$distance[$edge['from']]+$edge['cost'];
                    if ($candidate<$distance[$edge['to']]) {
                        $distance[$edge['to']]=$candidate; $parent[$edge['to']]=$id; $changed=true;
                    }
                }
                if (!$changed) break;
            }
            if (!isset($parent[$sink])) return [];
            for ($v=$sink;$v!==$source;) {
                $id=$parent[$v]; $edges[$id]['cap']--; $edges[$id^1]['cap']++; $v=$edges[$id]['from'];
            }
        }
        $counts=array_fill(0,$n,array_fill(0,$n,0));
        foreach ($cells as $r=>$row) foreach ($row as $m=>$ids) {
            $counts[$r][$m]=array_sum(array_map(static fn(int $id):int=>1-$edges[$id]['cap'],$ids));
        }
        // Decompose the regular bipartite multigraph into one perfect matching per group.
        $result=[];
        for ($g=0;$g<$groups;$g++) {
            $match=[];
            $find=function(int $r,array $used) use (&$find,&$match,&$counts,$n):bool {
                if ($r===$n) return true;
                for ($m=0;$m<$n;$m++) {
                    if (isset($used[$m]) || $counts[$r][$m]<1) continue;
                    $match[$r]=$m;
                    if ($find($r+1,$used+[$m=>true])) return true;
                }
                return false;
            };
            if (!$find(0,[])) throw new \LogicException('Integral lesson allocation could not be decomposed.');
            $row=[];
            foreach ($match as $r=>$m) { $row[$r]=$moduleKeys[$m]; $counts[$r][$m]--; }
            $result[]=$row;
        }
        return $result;
    }
}

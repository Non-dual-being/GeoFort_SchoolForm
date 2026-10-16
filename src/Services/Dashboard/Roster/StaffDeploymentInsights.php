<?php
declare(strict_types=1);
namespace GeoFort\Services\Dashboard\Roster;

/** Planned deployment, never a claim about actual attendance or accounting profit. */
final class StaffDeploymentInsights
{
    public static function build(array $entries, array $rows, array $catalog, array $opportunities, array $filters, array $revenue): array
    {
        $active = array_values(array_filter($entries, fn($e) => $filters['status'] === 'Afgewezen' || $e['status'] !== 'Afgewezen'));
        $members = array_column($catalog, null, 'id');
        $days = [];
        foreach ($active as $entry) $days[$entry['staffId'].'|'.$entry['date']][] = $entry;
        $daily = [];
        $cost = 0;
        $unknown = 0;
        foreach ($days as $events) {
            $first = min(array_column($events, 'start'));
            $last = max(array_column($events, 'end'));
            $date = $events[0]['date'];
            $id = $events[0]['staffId'];
            $lessons = StaffDeploymentService::minutes($events);
            $window = [['date'=>$date, 'start'=>$first, 'end'=>$last]];
            $span = StaffDeploymentService::minutes($window);
            $possible = [];
            foreach (array_unique(array_column($events, 'planId')) as $planId) {
                foreach ($opportunities[$planId] ?? [] as $slot) {
                    $start = max($first, $slot['startTime']);
                    $end = min($last, $slot['endTime']);
                    if ($start < $end) $possible[] = ['date'=>$date,'start'=>$start,'end'=>$end];
                }
            }
            $gap = max(0, StaffDeploymentService::minutes($possible) - $lessons);
            $minutes = $filters['costBasis'] === 'span' ? $span : $lessons;
            $minutes += (int)$filters['extraMinutes'];
            $rates = array_values(array_filter($members[$id]['costRates'] ?? [], fn($r) => $r['validFrom'] <= $date && ($r['validTo'] === null || $r['validTo'] >= $date)));
            // Ambiguous overlapping rates are unknown, just like a missing rate.
            $cents = count($rates) === 1 ? (int)round($minutes * $rates[0]['hourlyCostCents'] / 60) : null;
            if ($cents === null) $unknown++; else $cost += $cents;
            $daily[] = ['staffId'=>$id,'name'=>$events[0]['name'],'employment'=>$events[0]['employment'],'date'=>$date,'minutes'=>$lessons,'spanMinutes'=>$span,'gapMinutes'=>$gap,'breakMinutes'=>max(0,$span-$lessons-$gap),'shortVisit'=>$lessons<=45,'costMinutes'=>$minutes,'costCents'=>$cents];
        }
        $groups = [];
        foreach (['paid'=>'Betaald','volunteer'=>'Vrijwilliger','unknown'=>'Onbekend'] as $key=>$label) {
            $people = array_values(array_filter($rows, fn($r) => $r['employment']===$key && $r['minutes']>0));
            $groupDays = array_values(array_filter($daily, fn($d) => $d['employment']===$key));
            $minutes = array_sum(array_column($people,'minutes'));
            $groups[] = ['key'=>$key,'label'=>$label,'staff'=>count($people),'minutes'=>$minutes,'spanMinutes'=>array_sum(array_column($groupDays,'spanMinutes')),'breakMinutes'=>array_sum(array_column($groupDays,'breakMinutes')),'staffDays'=>count($groupDays),'averageMinutes'=>count($people) ? (int)round($minutes/count($people)):0,'shortVisits'=>count(array_filter($groupDays,fn($d)=>$d['shortVisit'])),'gapMinutes'=>array_sum(array_column($groupDays,'gapMinutes'))];
        }
        $moduleGroups = [];
        foreach ($active as $event) $moduleGroups[$event['employment'].'|'.$event['module']][$event['staffId']][]=$event;
        $modules=[];
        foreach($moduleGroups as $staffEvents) {
            $example=reset($staffEvents)[0];$minutes=0;
            foreach($staffEvents as $events) $minutes+=StaffDeploymentService::minutes($events);
            $modules[]=['employment'=>$example['employment'],'module'=>$example['moduleLabel'],'minutes'=>$minutes];
        }
        usort($modules,fn($a,$b)=>strcmp($a['module'].$a['employment'],$b['module'].$b['employment']));
        $rankings = [];
        foreach (['minutes','days','schools'] as $metric) {
            $ranked = array_values(array_filter($rows,fn($r)=>$r[$metric]>0));
            usort($ranked,fn($a,$b)=>($b[$metric]<=>$a[$metric]) ?: strnatcasecmp($a['name'],$b['name']));
            $rankings[$metric] = array_map(fn($r)=>['id'=>$r['id'],'name'=>$r['name'],'value'=>$r[$metric]],array_slice($ranked,0,5));
        }
        $comparable = $filters['staff']==='all' && $filters['employment']==='all';
        $finance = ['knownCostCents'=>$cost,'unknownRateDays'=>$unknown,'scopeComplete'=>$comparable,'revenue'=>$revenue,'balanceCents'=>null];
        if ($comparable && $unknown===0 && $revenue['missing']===0 && $revenue['unlinked']===0 && $revenue['count']>0) $finance['balanceCents']=$revenue['confirmedCents']+$revenue['optionCents']-$cost;
        return ['modules'=>$modules,'minutes'=>array_sum(array_column($rows,'minutes')),'spanMinutes'=>array_sum(array_column($daily,'spanMinutes')),'gapMinutes'=>array_sum(array_column($daily,'gapMinutes')),'breakMinutes'=>array_sum(array_column($daily,'breakMinutes')),'staff'=>count(array_filter($rows,fn($r)=>$r['minutes']>0)),'days'=>count(array_unique(array_column($active,'date'))),'schools'=>count(array_unique(array_column($active,'schoolKey'))),'rankings'=>$rankings,'groups'=>$groups,'daily'=>$daily,'finance'=>$finance];
    }
}

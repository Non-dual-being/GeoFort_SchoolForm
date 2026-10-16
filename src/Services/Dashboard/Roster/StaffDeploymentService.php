<?php
declare(strict_types=1);
namespace GeoFort\Services\Dashboard\Roster;
use GeoFort\Services\Sql\StaffDeploymentSqlRepository;
use GeoFort\Booking\BookingProgramConfig;
use InvalidArgumentException;

final readonly class StaffDeploymentService
{
    public function __construct(private StaffDeploymentSqlRepository $repository, private RosterValidationService $validator) {}
    public static function filters(array $query): array
    {
        $year=(new \DateTimeImmutable('now',new \DateTimeZone('Europe/Amsterdam')))->format('Y');
        $f=['from'=>$query['from']??"$year-01-01",'to'=>$query['to']??"$year-12-31",'status'=>$query['status']??'all','roster'=>$query['roster']??'all','staff'=>$query['staff']??'all','employment'=>$query['employment']??'all'];
        $f['costBasis']=$query['costBasis']??'lessons';
        if(isset($query['extraMinutes'])&&!is_scalar($query['extraMinutes']))throw new InvalidArgumentException('Ongeldige extra minuten.');
        $f['extraMinutes']=(string)($query['extraMinutes']??'0');
        if(!in_array($f['costBasis'],['lessons','span'],true)||!preg_match('/^[0-9]{1,3}$/D',$f['extraMinutes'])||(int)$f['extraMinutes']>240)throw new InvalidArgumentException('Kies een geldige kostenbasis en 0–240 extra minuten.');
        foreach(['from','to'] as $key){$d=is_string($f[$key])?\DateTimeImmutable::createFromFormat('!Y-m-d',$f[$key]):false;if(!$d||$d->format('Y-m-d')!==$f[$key])throw new InvalidArgumentException('Kies geldige datums.');}
        if($f['from']>$f['to'] || (new \DateTimeImmutable($f['from']))->diff(new \DateTimeImmutable($f['to']))->days>3660)throw new InvalidArgumentException('Kies een periode van maximaal tien jaar, met de begindatum vóór de einddatum.');
        foreach(['status'=>['all','Definitief','In optie','Afgewezen','Zonder aanvraag'],'roster'=>['all','concept','published'],'employment'=>['all','paid','volunteer']] as $key=>$allowed)if(!in_array($f[$key],$allowed,true))throw new InvalidArgumentException('Ongeldig filter.');
        if(!is_string($f['staff'])||($f['staff']!=='all'&&!preg_match('/^[1-9][0-9]*$/D',$f['staff'])))throw new InvalidArgumentException('Kies een geldige medewerker.');
        return $f;
    }
    public function report(array $query): array
    {
        $f=self::filters($query);$catalog=$this->repository->staff();$members=array_column($catalog,null,'id');$entries=[];$opportunities=[];$bookings=[];$revenue=['confirmedCents'=>0,'optionCents'=>0,'missing'=>0,'unlinked'=>0,'count'=>0];
        foreach($this->repository->plans($f['from'],$f['to']) as $source){
            $p=$source['plan'];$d=$source['data'];$status=$p['booking_status']??'Zonder aanvraag';
            if(($f['status']!=='all'&&$status!==$f['status'])||($f['roster']!=='all'&&$p['status']!==$f['roster']))continue;
            $context=$d['plan']['planning_mode']==='custom'?$d['context']:$d['booking'];
            $school=(string)($context['school_name']??$context['schoolnaam']??'Onbekende school');
            $city=(string)($context['city']??$context['plaats']??'');
            if($status!=='Afgewezen') {
                if($p['booking_id']===null) $revenue['unlinked']++;
                elseif(!isset($bookings[$p['booking_id']])) {
                    $bookings[$p['booking_id']]=true;$revenue['count']++;
                    $price=$this->repository->price((int)$p['booking_id']);
                    if($price===null) $revenue['missing']++;
                    else $revenue[$status==='Definitief'?'confirmedCents':'optionCents']+=$price;
                }
            }
            $opportunities[(int)$p['id']]=$source['occupancy']??$this->validator->occupancy($d);
            $names=array_column($d['staff'],'display_name','id');
            foreach($source['occupancy']??$this->validator->occupancy($d) as $slot){
                foreach(array_unique($slot['staffIds']) as $id){
                    $id=(int)$id;$m=$members[$id]??null;
                    if(($f['staff']!=='all'&&$id!==(int)$f['staff'])||($f['employment']!=='all'&&($m['employmentType']??'unknown')!==$f['employment']))continue;
                    $entries[]=['staffId'=>$id,'name'=>$m['name']??$names[$id]??"Medewerker $id",'employment'=>$m['employmentType']??'unknown',
                        'date'=>$p['visit_date'],'planId'=>(int)$p['id'],'school'=>$school,'schoolKey'=>mb_strtolower(trim($school).'|'.trim($city)),
                        'status'=>$status,'rosterStatus'=>$p['status'],'start'=>$slot['startTime'],'end'=>$slot['endTime'],
                        'module'=>$slot['moduleKey'],'moduleLabel'=>BookingProgramConfig::MODULE_LABELS[$slot['moduleKey']]??$slot['moduleKey'],
                        'role'=>$id===(int)($slot['cookStaffId']??0)?'Kok':'Begeleider','sessionId'=>(int)$slot['sessionId']];
                }
            }
        }
        $rows=self::summarize($entries);
        return ['insights'=>StaffDeploymentInsights::build($entries,$rows,$catalog,$opportunities,$f,$revenue),'filters'=>$f,'staffOptions'=>array_map(fn($m)=>['id'=>$m['id'],'name'=>$m['name']],$catalog),'rows'=>self::summarize($entries),'entries'=>$entries];
    }
    /** Union of time intervals per day: shared groups/schools never double the total. */
    public static function minutes(array $entries): int
    {
        $days=[];foreach($entries as $e){$start=self::minute($e['start']);$end=self::minute($e['end']);if($end>$start)$days[$e['date']][]=[$start,$end];}
        $total=0;foreach($days as $slots){sort($slots);$a=null;$b=0;foreach($slots as [$s,$e]){if($a===null){$a=$s;$b=$e;}elseif($s<=$b){$b=max($b,$e);}else{$total+=$b-$a;$a=$s;$b=$e;}}if($a!==null)$total+=$b-$a;}return $total;
    }
    private static function minute(string $t):int{return (int)substr($t,0,2)*60+(int)substr($t,3,2);}
    public static function summarize(array $entries): array
    {
        $groups=[];foreach($entries as $e)$groups[$e['staffId']][]=$e;$out=[];
        foreach($groups as $id=>$all){
            $active=array_values(array_filter($all,fn($e)=>$e['status']!=='Afgewezen'));
            $statuses=[];foreach(['Definitief','In optie','Afgewezen','Zonder aanvraag'] as $s)$statuses[$s]=self::minutes(array_values(array_filter($all,fn($e)=>$e['status']===$s)));
            $modules=[];foreach($all as $e)$modules[$e['module'].'|'.$e['role']][]=$e;
            $details=[];foreach($modules as $rows){$live=array_values(array_filter($rows,fn($e)=>$e['status']!=='Afgewezen'));$details[]=['module'=>$rows[0]['moduleLabel'],'role'=>$rows[0]['role'],'minutes'=>self::minutes($live),'rejectedMinutes'=>self::minutes(array_values(array_filter($rows,fn($e)=>$e['status']==='Afgewezen'))),'lessons'=>count(array_unique(array_map(fn($e)=>$e['date'].'|'.$e['start'].'|'.$e['end'],$live)))];}
            usort($details,fn($a,$b)=>strcmp($a['module'].$a['role'],$b['module'].$b['role']));
            $out[]=['id'=>(int)$id,'name'=>$all[0]['name'],'employment'=>$all[0]['employment'],'minutes'=>self::minutes($active),'statuses'=>$statuses,
                'schools'=>count(array_unique(array_column($active,'schoolKey'))),'days'=>count(array_unique(array_column($active,'date'))),
                'guideMinutes'=>self::minutes(array_values(array_filter($active,fn($e)=>$e['role']==='Begeleider'))),'cookMinutes'=>self::minutes(array_values(array_filter($active,fn($e)=>$e['role']==='Kok'))),
                'conceptMinutes'=>self::minutes(array_values(array_filter($active,fn($e)=>$e['rosterStatus']==='concept'))),'publishedMinutes'=>self::minutes(array_values(array_filter($active,fn($e)=>$e['rosterStatus']==='published'))),'modules'=>$details];
        }
        usort($out,fn($a,$b)=>strnatcasecmp($a['name'],$b['name']));return $out;
    }
}

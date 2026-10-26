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
        $f=['from'=>$query['from']??'','to'=>$query['to']??'','status'=>$query['status']??'all','roster'=>$query['roster']??'all','staff'=>$query['staff']??'all','employment'=>$query['employment']??'all'];
        $f['programVariant']=\GeoFort\Booking\BookingProgramVariant::filter($query['programVariant']??'all');
        $f['year']=$query['year']??'all';
        $f['range']=$query['range']??(isset($query['from'])||isset($query['to'])?'custom':'auto');
        if (!is_string($f['year']) || ($f['year']!=='all'&&!preg_match('/^[1-9][0-9]{3}$/D',$f['year'])) || !in_array($f['range'],['auto','custom'],true)) throw new InvalidArgumentException('Kies een geldig jaar en datumbereik.');
        $f['costBasis']=$query['costBasis']??'lessons';
        if(isset($query['extraMinutes'])&&!is_scalar($query['extraMinutes']))throw new InvalidArgumentException('Ongeldige extra minuten.');
        $f['extraMinutes']=(string)($query['extraMinutes']??'0');
        if(!in_array($f['costBasis'],['lessons','span'],true)||!preg_match('/^[0-9]{1,3}$/D',$f['extraMinutes'])||(int)$f['extraMinutes']>240)throw new InvalidArgumentException('Kies een geldige kostenbasis en 0–240 extra minuten.');
        foreach(['from','to'] as $key){if($f[$key]===''&&$f['range']==='auto')continue;$d=is_string($f[$key])?\DateTimeImmutable::createFromFormat('!Y-m-d',$f[$key]):false;if(!$d||$d->format('Y-m-d')!==$f[$key])throw new InvalidArgumentException('Kies geldige datums.');}
        if($f['range']==='custom'&&$f['from']>$f['to'])throw new InvalidArgumentException('De begindatum moet vóór de einddatum liggen.');
        foreach(['status'=>['all','Definitief','In optie','Afgewezen','Zonder aanvraag'],'roster'=>['all','concept','published'],'employment'=>['all','paid','volunteer','unknown']] as $key=>$allowed)if(!in_array($f[$key],$allowed,true))throw new InvalidArgumentException('Ongeldig filter.');
        if(!is_string($f['staff'])||($f['staff']!=='all'&&!preg_match('/^[1-9][0-9]*$/D',$f['staff'])))throw new InvalidArgumentException('Kies een geldige medewerker.');
        return $f;
    }
    public function report(array $query): array
    {
        $f=self::filters($query);$catalog=$this->repository->staff();$members=array_column($catalog,null,'id');$entries=[];$opportunities=[];
        foreach($this->repository->plans() as $source){
            $p=$source['plan'];$d=$source['data'];$status=$p['booking_status']??'Zonder aanvraag';
            $context=$d['plan']['planning_mode']==='custom'?$d['context']:$d['booking'];
            $variant=\GeoFort\Booking\BookingProgramVariant::key($context['programma']??$context['program_key']??null,$context['program_version']??null,$d['plan']['planning_mode']==='custom');
            $school=(string)($context['school_name']??$context['schoolnaam']??'Onbekende school');
            $city=(string)($context['city']??$context['plaats']??'');
            $opportunities[(int)$p['id']]=$source['occupancy']??$this->validator->occupancy($d);
            $names=array_column($d['staff'],'display_name','id');
            foreach($opportunities[(int)$p['id']] as $slot){
                foreach(array_unique($slot['staffIds']) as $id){
                    $id=(int)$id;$m=$members[$id]??null;
                    $entries[]=['staffId'=>$id,'name'=>$m['name']??$names[$id]??"Medewerker $id",'employment'=>$m['employmentType']??'unknown',
                        'date'=>$p['visit_date'],'planId'=>(int)$p['id'],'school'=>$school,'schoolKey'=>mb_strtolower(trim($school).'|'.trim($city)),
                        'programVariant'=>$variant,'programLabel'=>\GeoFort\Booking\BookingProgramVariant::label($variant),
                        'status'=>$status,'rosterStatus'=>$p['status'],'start'=>$slot['startTime'],'end'=>$slot['endTime'],
                        'module'=>$slot['moduleKey'],'moduleLabel'=>BookingProgramConfig::MODULE_LABELS[$slot['moduleKey']]??$slot['moduleKey'],
                        'role'=>$id===(int)($slot['cookStaffId']??0)?'Kok':'Begeleider','sessionId'=>(int)$slot['sessionId']];
                }
            }
        }
        $scope=StaffDeploymentScope::select($entries,$f);$f=$scope['filters'];$entries=$scope['entries'];
        $rows=self::summarize($entries,$f['status']==='Afgewezen');
        // Financial preparation remains internal; this edition exposes only time analytics.
        $insights=StaffDeploymentInsights::build($entries,$rows,$catalog,$opportunities,$f,['confirmedCents'=>0,'optionCents'=>0,'missing'=>0,'unlinked'=>0,'count'=>0]);
        unset($insights['finance']);
        foreach($insights['daily'] as &$day){unset($day['costCents'],$day['costMinutes']);}unset($day);
        unset($f['costBasis'],$f['extraMinutes']);
        return ['insights'=>$insights,'filters'=>$f,'options'=>$scope['options'],'dateBounds'=>$scope['dateBounds'],'rows'=>$rows,'entries'=>$entries];
    }
    /** Union of time intervals per day: shared groups/schools never double the total. */
    public static function minutes(array $entries): int
    {
        $days=[];foreach($entries as $e){$start=self::minute($e['start']);$end=self::minute($e['end']);if($end>$start)$days[$e['date']][]=[$start,$end];}
        $total=0;foreach($days as $slots){sort($slots);$a=null;$b=0;foreach($slots as [$s,$e]){if($a===null){$a=$s;$b=$e;}elseif($s<=$b){$b=max($b,$e);}else{$total+=$b-$a;$a=$s;$b=$e;}}if($a!==null)$total+=$b-$a;}return $total;
    }
    private static function minute(string $t):int{return (int)substr($t,0,2)*60+(int)substr($t,3,2);}
    public static function summarize(array $entries, bool $includeRejected=false): array
    {
        $groups=[];foreach($entries as $e)$groups[$e['staffId']][]=$e;$out=[];
        foreach($groups as $id=>$all){
            $active=array_values(array_filter($all,fn($e)=>$includeRejected||$e['status']!=='Afgewezen'));
            $statuses=[];foreach(['Definitief','In optie','Afgewezen','Zonder aanvraag'] as $s)$statuses[$s]=self::minutes(array_values(array_filter($all,fn($e)=>$e['status']===$s)));
            $modules=[];foreach($all as $e)$modules[$e['module'].'|'.$e['role']][]=$e;
            $details=[];foreach($modules as $rows){$live=array_values(array_filter($rows,fn($e)=>$includeRejected||$e['status']!=='Afgewezen'));$details[]=['module'=>$rows[0]['moduleLabel'],'role'=>$rows[0]['role'],'minutes'=>self::minutes($live),'rejectedMinutes'=>self::minutes(array_values(array_filter($rows,fn($e)=>$e['status']==='Afgewezen'))),'lessons'=>count(array_unique(array_map(fn($e)=>$e['date'].'|'.$e['start'].'|'.$e['end'],$live)))];}
            usort($details,fn($a,$b)=>strcmp($a['module'].$a['role'],$b['module'].$b['role']));
            $out[]=['id'=>(int)$id,'name'=>$all[0]['name'],'employment'=>$all[0]['employment'],'minutes'=>self::minutes($active),'statuses'=>$statuses,
                'schools'=>count(array_unique(array_column($active,'schoolKey'))),'days'=>count(array_unique(array_column($active,'date'))),
                'guideMinutes'=>self::minutes(array_values(array_filter($active,fn($e)=>$e['role']==='Begeleider'))),'cookMinutes'=>self::minutes(array_values(array_filter($active,fn($e)=>$e['role']==='Kok'))),
                'conceptMinutes'=>self::minutes(array_values(array_filter($active,fn($e)=>$e['rosterStatus']==='concept'))),'publishedMinutes'=>self::minutes(array_values(array_filter($active,fn($e)=>$e['rosterStatus']==='published'))),'modules'=>$details];
        }
        usort($out,fn($a,$b)=>strnatcasecmp($a['name'],$b['name']));return $out;
    }
}

<?php
declare(strict_types=1);
if(PHP_VERSION_ID<80100){fwrite(STDERR,"SKIP/FAIL: voer migratietests expliciet uit met PHP 8.1 of hoger.\n");exit(2);}

use GeoFort\Services\Migration\LegacyBookingRollbackService;
use GeoFort\Services\Migration\LegacyRollbackTarget;
require dirname(__DIR__,2).'/vendor/autoload.php';

final class FakeRollbackTarget implements LegacyRollbackTarget
{
    public array $native=[900=>'native'];public array $legacy=[901=>7,902=>8];public array $selections=[901=>2,902=>1];public array $log=[1=>'keep'];public bool $rolledBack=false;public bool $failDeleteRun=false;private array $backup=[];
    public function inspectRun(int$runId,string$system):?array{if($system!=='legacy-school-db'||!in_array($runId,$this->legacy,true))return null;$ids=array_keys(array_filter($this->legacy,static fn($r)=>$r===$runId));return['run_id'=>$runId,'booking_count'=>count($ids),'selection_count'=>array_sum(array_intersect_key($this->selections,array_flip($ids))),'booking_ids'=>$ids];}
    public function integritySnapshot():array{return['native'=>$this->native,'log'=>$this->log];}
    public function begin():void{$this->backup=[$this->legacy,$this->selections];}
    public function deleteRunBookings(int$runId,string$system):int{$n=0;foreach($this->legacy as$id=>$run){if($run===$runId){unset($this->legacy[$id],$this->selections[$id]);$n++;}}return$n;}
    public function deleteRun(int$runId,string$system):int{if($this->failDeleteRun)throw new RuntimeException('geforceerde fout');return 1;}
    public function assertRollbackIntegrity(array$before,array$plan):void{if($before!==$this->integritySnapshot())throw new RuntimeException('integriteit');}
    public function commit():void{$this->backup=[];}
    public function rollback():void{[$this->legacy,$this->selections]=$this->backup;$this->rolledBack=true;}
}

$fail=[];$assert=static function(bool$c,string$m)use(&$fail):void{if(!$c)$fail[]=$m;};$service=new LegacyBookingRollbackService();$target=new FakeRollbackTarget();$plan=$service->plan(7,$target);$assert($plan['booking_ids']===[901],'Plan mag alleen gekozen run bevatten.');$result=$service->execute(7,$target);$assert($result['deleted_bookings']===1&&!isset($target->legacy[901]),'Gekozen legacy-aanvraag moet verdwijnen.');$assert(isset($target->legacy[902])&&$target->native===[900=>'native'],'Andere legacy- en niet-legacy-aanvragen moeten blijven.');$assert($target->log===[1=>'keep'],'form_submit_log moet blijven.');$failure=new FakeRollbackTarget();$failure->failDeleteRun=true;try{$service->execute(7,$failure);}catch(RuntimeException$e){}$assert($failure->rolledBack&&isset($failure->legacy[901])&&isset($failure->selections[901]),'Fout moet aanvragen en selecties terugrollen.');if($fail!==[]){foreach($fail as$f)fwrite(STDERR,"FAIL: {$f}\n");exit(1);}fwrite(STDOUT,"OK: rollbackservicechecks geslaagd.\n");

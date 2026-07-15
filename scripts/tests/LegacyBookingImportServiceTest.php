<?php

declare(strict_types=1);

if (PHP_VERSION_ID < 80100) { fwrite(STDERR, "SKIP/FAIL: voer migratietests expliciet uit met PHP 8.1 of hoger.\n"); exit(2); }

use GeoFort\Services\Migration\LegacyBookingImportService;
use GeoFort\Services\Migration\LegacyDatabaseConfigGuard;
use GeoFort\Services\Migration\LegacyMigrationTarget;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

final class FakeLegacyTarget implements LegacyMigrationTarget
{
    public array $existing=[]; public array $bookings=[900=>['native'=>true]]; public array $selections=[]; public array $submitLog=[1=>'preserve']; public bool $transaction=false; public bool $rolledBack=false; public bool $failSelection=false; private int $nextId=901;
    public function findSourceRecord(string $system,int $id):?array{return$this->existing[$system.'|'.$id]??null;}
    public function targetIdExists(int $id):bool{return isset($this->bookings[$id]);}
    public function begin():void{$this->transaction=true;}
    public function commit():void{$this->transaction=false;}
    public function rollback():void{$this->rolledBack=true;$this->transaction=false;}
    public function createImportRun(string $system,string $filename,string $checksum,int $warnings):int{return 7;}
    public function insertBooking(array $booking):int{$id=$this->nextId++;$this->bookings[$id]=$booking;$this->existing[$booking['source_system'].'|'.$booking['source_record_id']]=['source_record_checksum'=>$booking['source_record_checksum']];return$id;}
    public function insertSelection(array $selection):void{if($this->failSelection)throw new RuntimeException('testfout');$this->selections[]=$selection;}
    public function completeImportRun(int $runId,int $imported,int $skipped,int $warnings):void{}
    public function integritySnapshot():array{return['native'=>$this->bookings[900],'submit'=>$this->submitLog];}
    public function assertIntegrity(array $before,int $runId,int $imported,int $selectionCount):void{if($before!==$this->integritySnapshot())throw new RuntimeException('integriteit');}
}

$failures=[];$assert=static function(bool $condition,string $message)use(&$failures):void{if(!$condition)$failures[]=$message;};
$item=['legacy_id'=>12,'source_checksum'=>str_repeat('a',64),'booking'=>['schoolnaam'=>'test'],'selections'=>[['level_key'=>'havo']]];
$service=new LegacyBookingImportService();$target=new FakeLegacyTarget();$result=$service->execute([$item],$target,'dump.sql',str_repeat('b',64),0);
$assert($result['imported']===1,'Eerste import moet invoegen.');$assert(isset($target->bookings[900]),'Bestaande nieuwe aanvraag moet behouden blijven.');$assert(array_key_first(array_filter($target->bookings,static fn($b)=>isset($b['source_record_id'])))!==12,'Legacy-ID mag geen doel-ID worden.');$assert($target->selections[0]['aanvraag_id']===901,'Selectie moet gegenereerde doel-ID gebruiken.');$assert($target->submitLog===[1=>'preserve'],'form_submit_log moet behouden blijven.');
$second=$service->plan([$item],$target);$assert($second['skip']===[12],'Tweede identieke import moet overslaan.');$changed=$item;$changed['source_checksum']=str_repeat('c',64);$assert($service->plan([$changed],$target)['changed']===[12],'Gewijzigde bron moet worden gerapporteerd.');
$dryTarget=new FakeLegacyTarget();$before=$dryTarget->bookings;$service->plan([$item],$dryTarget);$assert($dryTarget->bookings===$before&&!$dryTarget->transaction,'Dry-runplan mag geen writes of transactie starten.');
$sameBlocked=false;try{LegacyDatabaseConfigGuard::assertSeparated('db','3306','schema','DB','3306','SCHEMA');}catch(RuntimeException $e){$sameBlocked=true;}$assert($sameBlocked,'Gelijke bron en doel moeten blokkeren.');
$aliasPassed=true;try{LegacyDatabaseConfigGuard::assertSeparated('localhost','3306','schema','127.0.0.1','3306','schema');}catch(RuntimeException $e){$aliasPassed=false;}$assert($aliasPassed,'Vroege guard mag hostaliassen overlaten aan de serveridentiteitscontrole.');
$identityBlocked=false;try{LegacyDatabaseConfigGuard::assertDistinctIdentities(['database'=>'schema','hostname'=>'db-host','port'=>'3306','server_uuid'=>'uuid-1'],['database'=>'SCHEMA','hostname'=>'db-host','port'=>'3306','server_uuid'=>'uuid-1']);}catch(RuntimeException $e){$identityBlocked=true;}$assert($identityBlocked,'Identieke server/schema-identiteit moet blokkeren.');
$differentAllowed=true;try{LegacyDatabaseConfigGuard::assertDistinctIdentities(['database'=>'legacy','hostname'=>'db-host','port'=>'3306','server_uuid'=>'uuid-1'],['database'=>'target','hostname'=>'db-host','port'=>'3306','server_uuid'=>'uuid-1']);}catch(RuntimeException $e){$differentAllowed=false;}$assert($differentAllowed,'Verschillende schema’s op dezelfde server moeten toegestaan blijven.');
$conflictTarget=new FakeLegacyTarget();$conflictTarget->bookings[12]=['native'=>true];$service->execute([$item],$conflictTarget,'dump.sql',str_repeat('d',64),0);$assert($conflictTarget->bookings[12]===['native'=>true],'Gelijk legacy-ID mag bestaande doelrij niet overschrijven.');
$rollbackTarget=new FakeLegacyTarget();$rollbackTarget->failSelection=true;try{$service->execute([$item],$rollbackTarget,'dump.sql',str_repeat('e',64),0);}catch(RuntimeException $e){}$assert($rollbackTarget->rolledBack,'Fout moet rollback activeren.');
if($failures!==[]){foreach($failures as$f)fwrite(STDERR,"FAIL: {$f}\n");exit(1);}fwrite(STDOUT,"OK: importservicechecks geslaagd.\n");

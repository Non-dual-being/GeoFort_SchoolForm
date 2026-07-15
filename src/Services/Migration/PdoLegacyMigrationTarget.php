<?php

declare(strict_types=1);

namespace GeoFort\Services\Migration;

use PDO;
use RuntimeException;

final class PdoLegacyMigrationTarget implements LegacyMigrationTarget
{
    private PDO $pdo;
    public function __construct(PDO $pdo){$this->pdo=$pdo;}
    public function findSourceRecord(string $sourceSystem,int $sourceRecordId):?array{$s=$this->pdo->prepare('SELECT id, source_record_checksum FROM aanvragen WHERE source_system=:system AND source_record_id=:record_id');$s->execute(['system'=>$sourceSystem,'record_id'=>$sourceRecordId]);$r=$s->fetch();return$r===false?null:$r;}
    public function targetIdExists(int $targetId):bool{$s=$this->pdo->prepare('SELECT 1 FROM aanvragen WHERE id=:id');$s->execute(['id'=>$targetId]);return$s->fetchColumn()!==false;}
    public function begin():void{if(!$this->pdo->beginTransaction())throw new RuntimeException('Doeltransactie kon niet worden gestart.');}
    public function commit():void{$this->pdo->commit();}
    public function rollback():void{if($this->pdo->inTransaction())$this->pdo->rollBack();}
    public function createImportRun(string $sourceSystem,string $filename,string $checksum,int $warningCount):int{$s=$this->pdo->prepare("INSERT INTO legacy_booking_import_runs (source_system,source_filename,source_checksum,started_at,status,warning_count) VALUES (:system,:filename,:checksum,NOW(),'running',:warnings)");$s->execute(['system'=>$sourceSystem,'filename'=>$filename,'checksum'=>$checksum,'warnings'=>$warningCount]);return(int)$this->pdo->lastInsertId();}
    public function insertBooking(array $booking):int{$columns=array_keys($booking);$sql='INSERT INTO aanvragen (`'.implode('`,`',$columns).'`) VALUES (:'.implode(',:',$columns).')';$this->pdo->prepare($sql)->execute($booking);return(int)$this->pdo->lastInsertId();}
    public function insertSelection(array $selection):void{$columns=array_keys($selection);$sql='INSERT INTO aanvraag_onderwijs_selecties (`'.implode('`,`',$columns).'`) VALUES (:'.implode(',:',$columns).')';$this->pdo->prepare($sql)->execute($selection);}
    public function completeImportRun(int $runId,int $imported,int $skipped,int $warnings):void{$s=$this->pdo->prepare("UPDATE legacy_booking_import_runs SET completed_at=NOW(),status='completed',imported_count=:imported,skipped_count=:skipped,warning_count=:warnings WHERE id=:id AND status='running'");$s->execute(['imported'=>$imported,'skipped'=>$skipped,'warnings'=>$warnings,'id'=>$runId]);if($s->rowCount()!==1)throw new RuntimeException('Import-runstatus kon niet worden voltooid.');}
    public function integritySnapshot():array{return['non_legacy'=>$this->fingerprint("SELECT * FROM aanvragen WHERE source_system IS NULL ORDER BY id"),'submit_log'=>$this->fingerprint('SELECT * FROM form_submit_log ORDER BY id')];}
    public function assertIntegrity(array $before,int $runId,int $imported,int $selectionCount):void
    {
        $after=$this->integritySnapshot();if($after!==$before)throw new RuntimeException('Bestaande aanvragen of form_submit_log zijn gewijzigd.');
        $s=$this->pdo->prepare('SELECT COUNT(*) FROM aanvragen WHERE source_import_run_id=:run');$s->execute(['run'=>$runId]);if((int)$s->fetchColumn()!==$imported)throw new RuntimeException('Aantal geïmporteerde aanvragen wijkt af.');
        $s=$this->pdo->prepare('SELECT COUNT(*) FROM aanvraag_onderwijs_selecties s JOIN aanvragen a ON a.id=s.aanvraag_id WHERE a.source_import_run_id=:run');$s->execute(['run'=>$runId]);if((int)$s->fetchColumn()!==$selectionCount)throw new RuntimeException('Aantal geïmporteerde selecties wijkt af.');
        $orphans=(int)$this->pdo->query('SELECT COUNT(*) FROM aanvraag_onderwijs_selecties s LEFT JOIN aanvragen a ON a.id=s.aanvraag_id WHERE a.id IS NULL')->fetchColumn();if($orphans!==0)throw new RuntimeException('Orphan onderwijsselecties gevonden.');
        $duplicates=(int)$this->pdo->query('SELECT COUNT(*) FROM (SELECT source_system,source_record_id FROM aanvragen WHERE source_system IS NOT NULL GROUP BY source_system,source_record_id HAVING COUNT(*)>1) d')->fetchColumn();if($duplicates!==0)throw new RuntimeException('Dubbele herkomstsleutels gevonden.');
    }
    private function fingerprint(string $sql):string{$hash=hash_init('sha256');$s=$this->pdo->query($sql);while(($row=$s->fetch())!==false)hash_update($hash,json_encode($row,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n");return hash_final($hash);}
}

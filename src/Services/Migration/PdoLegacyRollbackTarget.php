<?php
declare(strict_types=1);
namespace GeoFort\Services\Migration;

use PDO;
use RuntimeException;

final class PdoLegacyRollbackTarget implements LegacyRollbackTarget
{
    private PDO $pdo;
    public function __construct(PDO $pdo){$this->pdo=$pdo;}
    public function inspectRun(int $runId,string $sourceSystem):?array{$s=$this->pdo->prepare('SELECT id FROM legacy_booking_import_runs WHERE id=:id AND source_system=:system');$s->execute(['id'=>$runId,'system'=>$sourceSystem]);if($s->fetchColumn()===false)return null;$ids=$this->pdo->prepare('SELECT id FROM aanvragen WHERE source_import_run_id=:run AND source_system=:system ORDER BY id');$ids->execute(['run'=>$runId,'system'=>$sourceSystem]);$bookingIds=array_map('intval',$ids->fetchAll(PDO::FETCH_COLUMN));$selectionCount=0;if($bookingIds!==[]){$q=implode(',',array_fill(0,count($bookingIds),'?'));$sel=$this->pdo->prepare("SELECT COUNT(*) FROM aanvraag_onderwijs_selecties WHERE aanvraag_id IN ({$q})");$sel->execute($bookingIds);$selectionCount=(int)$sel->fetchColumn();}return['run_id'=>$runId,'booking_count'=>count($bookingIds),'selection_count'=>$selectionCount,'booking_ids'=>$bookingIds];}
    public function integritySnapshot():array{return['non_legacy'=>$this->fingerprint("SELECT * FROM aanvragen WHERE source_system IS NULL ORDER BY id"),'other_legacy'=>$this->fingerprint("SELECT * FROM aanvragen WHERE source_system IS NOT NULL ORDER BY id"),'submit_log'=>$this->fingerprint('SELECT * FROM form_submit_log ORDER BY id')];}
    public function begin():void{if(!$this->pdo->beginTransaction())throw new RuntimeException('Rollbacktransactie kon niet starten.');}
    public function deleteRunBookings(int $runId,string $sourceSystem):int{$s=$this->pdo->prepare('DELETE FROM aanvragen WHERE source_import_run_id=:run AND source_system=:system');$s->execute(['run'=>$runId,'system'=>$sourceSystem]);return$s->rowCount();}
    public function deleteRun(int $runId,string $sourceSystem):int{$s=$this->pdo->prepare('DELETE FROM legacy_booking_import_runs WHERE id=:id AND source_system=:system');$s->execute(['id'=>$runId,'system'=>$sourceSystem]);return$s->rowCount();}
    public function assertRollbackIntegrity(array $before,array $plan):void{$after=$this->integritySnapshot();if($after['non_legacy']!==$before['non_legacy']||$after['submit_log']!==$before['submit_log'])throw new RuntimeException('Niet-legacy aanvragen of form_submit_log zijn gewijzigd.');foreach($plan['booking_ids']as$id){$s=$this->pdo->prepare('SELECT COUNT(*) FROM aanvraag_onderwijs_selecties WHERE aanvraag_id=:id');$s->execute(['id'=>$id]);if((int)$s->fetchColumn()!==0)throw new RuntimeException('Selectiecascade is onvolledig.');}}
    public function commit():void{$this->pdo->commit();}
    public function rollback():void{if($this->pdo->inTransaction())$this->pdo->rollBack();}
    private function fingerprint(string$sql):string{$h=hash_init('sha256');$s=$this->pdo->query($sql);while(($r=$s->fetch())!==false)hash_update($h,json_encode($r,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n");return hash_final($h);}
}

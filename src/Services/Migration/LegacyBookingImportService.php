<?php

declare(strict_types=1);

namespace GeoFort\Services\Migration;

use RuntimeException;
use Throwable;

final class LegacyBookingImportService
{
    public const SOURCE_SYSTEM = 'legacy-school-db';

    /** @param list<array<string, mixed>> $mapped */
    public function plan(array $mapped, LegacyMigrationTarget $target): array
    {
        $result=['insert'=>[],'skip'=>[],'changed'=>[],'legacy_id_conflicts'=>[]];
        foreach($mapped as$item){$id=(int)$item['legacy_id'];if($target->targetIdExists($id))$result['legacy_id_conflicts'][]=$id;$existing=$target->findSourceRecord(self::SOURCE_SYSTEM,$id);if($existing===null){$result['insert'][]=$id;}elseif(hash_equals((string)$existing['source_record_checksum'],(string)$item['source_checksum'])){$result['skip'][]=$id;}else{$result['changed'][]=$id;}}
        return$result;
    }

    /** @param list<array<string, mixed>> $mapped */
    public function execute(array $mapped, LegacyMigrationTarget $target, string $filename, string $dumpChecksum, int $warningCount): array
    {
        $plan=$this->plan($mapped,$target);
        if($plan['changed']!==[])throw new RuntimeException('Gewijzigde reeds geïmporteerde bronrecords blokkeren execute.');
        $before=$target->integritySnapshot();$imported=0;$selectionCount=0;
        $target->begin();
        try{$runId=$target->createImportRun(self::SOURCE_SYSTEM,$filename,$dumpChecksum,$warningCount);foreach($mapped as$item){if(in_array($item['legacy_id'],$plan['skip'],true))continue;$booking=$item['booking'];$booking['source_system']=self::SOURCE_SYSTEM;$booking['source_record_id']=$item['legacy_id'];$booking['source_record_checksum']=$item['source_checksum'];$booking['source_import_run_id']=$runId;$targetId=$target->insertBooking($booking);foreach($item['selections']as$selection){$selection['aanvraag_id']=$targetId;$target->insertSelection($selection);$selectionCount++;}$imported++;}$target->completeImportRun($runId,$imported,count($plan['skip']),$warningCount);$target->assertIntegrity($before,$runId,$imported,$selectionCount);$target->commit();return['run_id'=>$runId,'imported'=>$imported,'skipped'=>count($plan['skip']),'selections'=>$selectionCount];}catch(Throwable $e){$target->rollback();throw$e;}
    }
}

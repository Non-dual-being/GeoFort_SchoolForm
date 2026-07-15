<?php
declare(strict_types=1);
namespace GeoFort\Services\Migration;

use RuntimeException;
use Throwable;

final class LegacyBookingRollbackService
{
    public function plan(int $runId, LegacyRollbackTarget $target): array
    {
        if($runId<=0)throw new RuntimeException('Run-ID moet positief zijn.');
        $plan=$target->inspectRun($runId,LegacyBookingImportService::SOURCE_SYSTEM);
        if($plan===null)throw new RuntimeException('Import-run bestaat niet of hoort niet bij legacy-school-db.');
        return$plan;
    }
    public function execute(int $runId, LegacyRollbackTarget $target): array
    {
        $plan=$this->plan($runId,$target);$before=$target->integritySnapshot();$target->begin();
        try{$deleted=$target->deleteRunBookings($runId,LegacyBookingImportService::SOURCE_SYSTEM);if($deleted!==(int)$plan['booking_count'])throw new RuntimeException('Verwijderd aanvraagaantal wijkt af.');if($target->deleteRun($runId,LegacyBookingImportService::SOURCE_SYSTEM)!==1)throw new RuntimeException('Import-run kon niet exclusief worden verwijderd.');$target->assertRollbackIntegrity($before,$plan);$target->commit();return['run_id'=>$runId,'deleted_bookings'=>$deleted,'deleted_selections'=>(int)$plan['selection_count'],'booking_ids'=>$plan['booking_ids']];}catch(Throwable $e){$target->rollback();throw$e;}
    }
}

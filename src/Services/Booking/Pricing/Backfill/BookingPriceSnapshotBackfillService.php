<?php
declare(strict_types=1);

namespace GeoFort\Services\Booking\Pricing\Backfill;

use GeoFort\Services\Booking\Pricing\BookingPriceCalculator;
use GeoFort\Services\Booking\Pricing\BookingPriceSnapshot;
use GeoFort\Services\Booking\Pricing\BookingPriceSnapshotService;
use GeoFort\Services\Booking\Pricing\StoredBookingPricingInput;
use GeoFort\Services\Booking\Pricing\StoredBookingPricingInputFactory;
use GeoFort\Services\Migration\LegacyBookingImportService;
use GeoFort\Services\Sql\BookingPriceBackfillSqlRepository;
use GeoFort\Services\Sql\StoredBookingSqlRepository;
use InvalidArgumentException;
use PDO;
use RuntimeException;
use Throwable;

final readonly class BookingPriceSnapshotBackfillService
{
    public const EXPECTED_TOTAL = 140;
    public const EXPECTED_LEGACY = 140;
    public const EXPECTED_NATIVE = 0;
    public const EXPECTED_MINIMUM_SOURCE_RECORD_ID = 1;
    public const EXPECTED_MAXIMUM_SOURCE_RECORD_ID = 155;

    public function __construct(private PDO $pdo,private BookingPriceBackfillSqlRepository $inventory,private StoredBookingSqlRepository $bookings,private StoredBookingPricingInputFactory $inputs,private BookingPriceCalculator $calculator,private BookingPriceSnapshotService $snapshots) {}

    public function analyze(): BookingPriceBackfillAnalysis
    {
        $legacy=0;$native=0;$unknown=0;$with=0;$without=0;$calculable=0;$states=[];$candidates=[];$exceptions=[];$legacySourceRecordIds=[];$legacyWithoutSourceRecordId=0;
        foreach($this->inventory->inventory() as $row){
            $source=$this->sourceCategory($row['source_system'],$row['source_record_id']);
            if($source==='legacy')++$legacy;elseif($source==='native')++$native;else++$unknown;
            if($row['source_system']===LegacyBookingImportService::SOURCE_SYSTEM){if($row['source_record_id']===null||$row['source_record_id']<=0)++$legacyWithoutSourceRecordId;else$legacySourceRecordIds[]=$row['source_record_id'];}
            if($row['calculation_state']!==null){++$with;$states[$row['calculation_state']]=($states[$row['calculation_state']]??0)+1;continue;}
            ++$without;
            try{$booking=$this->bookings->findById($row['id'])??throw new RuntimeException('Boeking bestaat niet meer.');$input=$this->inputs->fromStoredBooking($booking);$this->inputs->calculate($input,$this->calculator);++$calculable;$candidates[]=['bookingId'=>$row['id'],'sourceCategory'=>$source,'sourceSystem'=>$row['source_system'],'sourceRecordId'=>$row['source_record_id'],'snapshotReason'=>$this->reason($source)];}
            catch(Throwable $exception){$exceptions[]=$this->exception($row['id'],$row['source_system'],$row['source_record_id'],$row['calculation_state'],$exception);}
        }
        $states['prospective_complete']=$calculable;ksort($states);
        $uniqueIds=array_values(array_unique($legacySourceRecordIds));sort($uniqueIds);
        return new BookingPriceBackfillAnalysis($legacy+$native+$unknown,$legacy,$native,$unknown,count($legacySourceRecordIds),count($uniqueIds),$legacyWithoutSourceRecordId,$uniqueIds[0]??null,$uniqueIds===[]?null:$uniqueIds[array_key_last($uniqueIds)],$without,$with,$calculable,count($exceptions),$states,$candidates,$exceptions);
    }

    public function execute(): BookingPriceBackfillAnalysis
    {
        try{
            if(!$this->pdo->beginTransaction())throw new RuntimeException('BACKFILL_TRANSACTION_FAILED');
            $analysis=$this->analyze();
            if(!$this->productionReconciliationMatches($analysis))throw new RuntimeException('BACKFILL_RECONCILIATION_MISMATCH');
            $added=0;$exceptions=$analysis->exceptions;
            foreach($analysis->candidates as $candidate){
                $id=(int)$candidate['bookingId'];
                $booking=$this->bookings->findByIdForUpdate($id)??throw new RuntimeException("BOOKING_NOT_FOUND:{$id}");
                if($this->snapshots->latest($id)!==null)continue;
                try{$input=$this->inputs->fromStoredBooking($booking);$this->inputs->calculate($input,$this->calculator);}
                catch(InvalidArgumentException $exception){$exceptions[]=$this->exception($id,$booking->source->sourceSystem,$booking->source->sourceRecordId,null,$exception);continue;}
                $this->snapshots->appendUsingActiveVersion($id,$this->pricingInput($input),(string)$candidate['snapshotReason']);++$added;
            }
            if(!$this->pdo->commit())throw new RuntimeException('BACKFILL_COMMIT_FAILED');
        }catch(Throwable $exception){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $exception;}
        return $analysis->withExecution($added,$exceptions);
    }

    public function productionReconciliationMatches(BookingPriceBackfillAnalysis $analysis):bool{return $analysis->reconciliationMatches(self::EXPECTED_TOTAL,self::EXPECTED_LEGACY,self::EXPECTED_NATIVE,self::EXPECTED_MINIMUM_SOURCE_RECORD_ID,self::EXPECTED_MAXIMUM_SOURCE_RECORD_ID);}
    private function sourceCategory(?string $source,?int $sourceRecordId):string{return $source===LegacyBookingImportService::SOURCE_SYSTEM&&$sourceRecordId!==null&&$sourceRecordId>0?'legacy':($source===null&&$sourceRecordId===null?'native':'unknown');}
    private function reason(string $sourceCategory):string{return $sourceCategory==='legacy'?BookingPriceSnapshot::REASON_LEGACY_ACCEPTANCE:BookingPriceSnapshot::REASON_SUBMISSION;}
    private function pricingInput(StoredBookingPricingInput $input): \GeoFort\Services\Booking\Pricing\BookingPricingInput{return new \GeoFort\Services\Booking\Pricing\BookingPricingInput($input->schoolSector,$input->program,$input->studentCount,$input->supervisorCount,$input->foodAndDrinkSelection);}

    /** @return array<string, mixed> */
    private function exception(int $id,?string $source,?int $sourceRecordId,?string $state,Throwable $exception):array
    {
        return ['bookingId'=>$id,'sourceCategory'=>$this->sourceCategory($source,$sourceRecordId),'sourceSystem'=>$source,'sourceRecordId'=>$sourceRecordId,'calculationState'=>$state??'missing','reason'=>$exception->getMessage(),'invalidFields'=>$this->invalidFields($id)];
    }
    /** @return list<string> */
    private function invalidFields(int $id):array
    {
        try{$booking=$this->bookings->findById($id);if($booking===null)return['booking'];$fields=[];if($booking->studentCount===null||$booking->studentCount<=0)$fields[]='aantal_leerlingen';if($booking->supervisorCount===null||$booking->supervisorCount<=0)$fields[]='aantal_begeleiders';return$fields!==[]?$fields:['onderwijs_sector','programma','catering'];}catch(Throwable){return['opgeslagen_boeking'];}
    }
}

<?php
declare(strict_types=1);
namespace GeoFort\Services\Booking\Program;

use DateTimeImmutable;
use GeoFort\Booking\BookingPolicy;
use GeoFort\Booking\BookingProgramConfig;
use GeoFort\Booking\Capacity\{BookingCapacityValidator,CapacityLimitProvider,CapacityValidationCode,DayCapacityTotals,EffectiveDayCapacity};
use GeoFort\Booking\Program\{BookingProgramChangeCode,BookingProgramChangeCommand,BookingProgramChangeResult};
use GeoFort\Booking\Rules\{AuthenticatedAdminBookingOverrideAuthorizationService,BookingOverrideAuthorizationService,BookingRuleContextFingerprint,BookingRuleOverridePolicy,UsedBookingRuleOverride};
use GeoFort\Booking\Stored\StoredBooking;
use GeoFort\Booking\Validation\{BookingValidationContext,BookingValidationCoordinator,BookingValidationProfile,StoredBookingIssue,StoredBookingIssueCategory,StoredBookingProgramValidator,StoredBookingVisitDateValidator};
use GeoFort\Services\Sql\{BookingCalendarSqlService,BookingChangeHistorySqlRepository,BookingDaySettingsSqlRepository,BookingProgramSqlRepository,BookingRuleOverrideSqlRepository,StoredBookingSqlRepository};
use PDO;
use RuntimeException;
use Throwable;
use GeoFort\Services\Booking\Pricing\{BookingPriceSnapshot,BookingPriceSnapshotService,BookingPricingInput};

final readonly class BookingProgramChangeService
{
    public function __construct(
        private PDO $pdo,private StoredBookingSqlRepository $bookings,private BookingProgramSqlRepository $programs,
        private BookingChangeHistorySqlRepository $history,private BookingDaySettingsSqlRepository $daySettings,
        private BookingCalendarSqlService $calendar,private StoredBookingProgramValidator $programValidator,
        private StoredBookingVisitDateValidator $dateValidator,private BookingValidationCoordinator $coordinator,
        private CapacityLimitProvider $capacityLimits,private BookingCapacityValidator $capacityValidator,
        private BookingRuleOverrideSqlRepository $overrideAudit,private BookingRuleOverridePolicy $overridePolicy=new BookingRuleOverridePolicy(),
        private BookingOverrideAuthorizationService $authorization=new AuthenticatedAdminBookingOverrideAuthorizationService(),
        private BookingRuleContextFingerprint $fingerprint=new BookingRuleContextFingerprint(),private ?BookingPriceSnapshotService $priceSnapshots=null,
    ) {}

    public function change(BookingProgramChangeCommand $command,?DateTimeImmutable $today=null):BookingProgramChangeResult
    {
        $previous=null;
        try{
            if(!$this->pdo->beginTransaction())throw new RuntimeException('Transactie kon niet worden gestart.');
            $snapshot=$this->bookings->findById($command->bookingId);
            if(!$snapshot)return $this->rollback($command,BookingProgramChangeCode::BookingNotFound);
            $settings=$snapshot->status===BookingPolicy::STATUS_CONFIRMED?$this->daySettings->lockDate($snapshot->visitDate):null;
            $booking=$this->bookings->findByIdForUpdate($command->bookingId);
            if(!$booking)return $this->rollback($command,BookingProgramChangeCode::BookingNotFound);
            if($booking->visitDate!==$snapshot->visitDate)return $this->rollback($command,BookingProgramChangeCode::ProgramConflict,$booking);
            $previous=$booking;
            if($booking->program!==$command->expectedProgram)return $this->rollback($command,BookingProgramChangeCode::ProgramConflict,$booking);
            if($booking->program===$command->proposedProgram)return $this->rollback($command,BookingProgramChangeCode::NoProgramChange,$booking,success:true);
            if(!BookingProgramConfig::programExists($command->proposedProgram))return $this->rollback($command,BookingProgramChangeCode::InvalidProgramSelection,$booking);
            if(!BookingPolicy::isAllowedStatus($booking->status))return $this->rollback($command,BookingProgramChangeCode::InvalidStoredBooking,$booking,[
                new StoredBookingIssue('INVALID_STATUS',StoredBookingIssueCategory::Structural,'status'),
            ]);
            $proposed=$booking->withProgram($command->proposedProgram);
            $confirmed=$booking->status===BookingPolicy::STATUS_CONFIRMED;
            $profile=$confirmed?BookingValidationProfile::ChangeProgramConfirmed:BookingValidationProfile::ChangeProgramDraft;
            $base=$this->programValidator->validateChange($booking,$proposed)->issues;
            $totals=null;$limits=null;$used=[];
            if($confirmed){
                array_push($base,...$this->dateValidator->validateDate($proposed,true,$today??new DateTimeImmutable('today'))->issues);
                $stats=$this->calendar->getBookingStatsForDate($proposed->visitDate,$booking->id);
                $totals=new DayCapacityTotals($stats['bookedSchools'],$stats['bookedStudents']);
                if($settings===null)throw new RuntimeException('Datumlock ontbreekt voor definitieve boeking.');
                $limits=$this->effectiveCapacity($proposed->visitDate,$settings->maxSchoolsOverride,$settings->maxStudentsOverride);
                $capacity=$this->capacityValidator->validate($totals,$proposed->studentCount,$limits);
                $capacityIssue=$capacity->allowed?null:$this->capacityIssue($capacity->code,$proposed,$totals,$limits,$capacity->projectedSchools,$capacity->projectedStudents);
                $validation=$this->coordinator->validate($profile,$proposed,new BookingValidationContext($base,$capacityIssue));
            }else{
                $validation=$this->coordinator->validate($profile,$proposed,new BookingValidationContext($base));
                if($command->requestedOverrides!==[])return $this->rollback($command,BookingProgramChangeCode::OverrideNotAllowed,$booking,$validation->issues);
            }
            $hard=array_filter($validation->issues,static fn(StoredBookingIssue $issue):bool=>!$issue->overridable);
            if($hard!==[])return $this->rollback($command,BookingProgramChangeCode::InvalidStoredBooking,$booking,$validation->issues);
            if($confirmed){
                $overrideResult=$this->validateOverrides($command,$booking,$proposed,$validation->issues,$totals,$limits);
                if($overrideResult instanceof BookingProgramChangeCode)return $this->rollback($command,$overrideResult,$booking,$validation->issues);
                $used=$overrideResult;
            }
            if(!$this->programs->guardedUpdate($booking->id,$command->expectedProgram,$proposed->program))return $this->rollback($command,BookingProgramChangeCode::ProgramConflict,$booking);
            if($this->priceSnapshots?->latest($booking->id)?->isComplete())$this->priceSnapshots->appendUsingExistingVersion($booking->id,BookingPricingInput::fromStoredBooking($proposed),BookingPriceSnapshot::REASON_PLANNER_UPDATE,$command->actingAdminId);
            $historyId=$this->history->insertProgramChange($booking->id,['programma'=>['before'=>$booking->program,'after'=>$proposed->program]],$command->actingAdminId);
            foreach($used as $override)$this->overrideAudit->insertForChange($booking->id,$historyId,$override,$command->actingAdminId);
            if(!$this->pdo->commit())throw new RuntimeException('Transactie kon niet worden vastgelegd.');
            return new BookingProgramChangeResult(BookingProgramChangeCode::Success,true,$booking->id,$booking->program,$proposed->program,$validation->issues,$used,$historyId);
        }catch(Throwable $exception){
            error_log(sprintf('Booking program change failure: exception=%s booking=%d',$exception::class,$command->bookingId));
            $this->rollbackIfActive();
            return new BookingProgramChangeResult(BookingProgramChangeCode::DatabaseError,false,$command->bookingId,$previous?->program,$previous?->program);
        }
    }

    private function capacityIssue(CapacityValidationCode $code,StoredBooking $proposed,DayCapacityTotals $totals,EffectiveDayCapacity $limits,int $projectedSchools,?int $projectedStudents):StoredBookingIssue
    {
        if($code===CapacityValidationCode::InvalidStudentCount)return new StoredBookingIssue($code->value,StoredBookingIssueCategory::Capacity,'aantalLeerlingen',metadata:['bookingStudents'=>$proposed->studentCount]);
        $metadata=$code===CapacityValidationCode::SchoolLimitExceeded?['confirmedSchoolsExcludingBooking'=>$totals->confirmedSchools,'projectedSchools'=>$projectedSchools,'maximumSchools'=>$limits->effectiveMaxSchools]:['confirmedStudentsExcludingBooking'=>$totals->confirmedStudents,'proposedBookingStudents'=>$proposed->studentCount,'projectedStudents'=>$projectedStudents,'maximumStudents'=>$limits->effectiveMaxStudents];
        return new StoredBookingIssue($code->value,StoredBookingIssueCategory::Capacity,$code===CapacityValidationCode::SchoolLimitExceeded?'bezoekdatum':'aantalLeerlingen',metadata:$metadata);
    }
    /** @param list<StoredBookingIssue> $issues @return list<UsedBookingRuleOverride>|BookingProgramChangeCode */
    private function validateOverrides(BookingProgramChangeCommand $command,StoredBooking $previous,StoredBooking $proposed,array $issues,?DayCapacityTotals $totals,?EffectiveDayCapacity $limits):array|BookingProgramChangeCode
    {
        $actual=[];foreach($issues as $issue)if($issue->overridable)$actual[$issue->code]=$issue;
        $requested=[];foreach($command->requestedOverrides as $request){if(isset($requested[$request->ruleCode]))return BookingProgramChangeCode::InvalidOverrideRequest;if(!$this->overridePolicy->definition($request->ruleCode)->overridable)return BookingProgramChangeCode::OverrideNotAllowed;$requested[$request->ruleCode]=$request;}
        if(array_diff_key($requested,$actual)!==[]||array_diff_key($actual,$requested)!==[])return $actual!==[]&&$requested===[]?BookingProgramChangeCode::OverrideRequired:BookingProgramChangeCode::InvalidOverrideRequest;
        $used=[];foreach($actual as $code=>$issue){$definition=$this->overridePolicy->definition($code);if(!$this->authorization->isAllowed($command->actingAdminId,$definition))return BookingProgramChangeCode::OverridePermissionDenied;$used[]=new UsedBookingRuleOverride($code,$requested[$code]->reason,$this->fingerprint->createForProgramChange($code,$previous,$proposed,$issue->metadata,$totals,$limits),$issue->metadata);}
        return $used;
    }
    private function effectiveCapacity(string $date,?int $schools,?int $students):EffectiveDayCapacity{$base=$this->capacityLimits->forDate($date);return new EffectiveDayCapacity($base->standardMaxSchools,$base->standardMaxStudents,$schools,$students,$schools??$base->effectiveMaxSchools,$students??$base->effectiveMaxStudents);}
    /** @param list<StoredBookingIssue> $issues */
    private function rollback(BookingProgramChangeCommand $command,BookingProgramChangeCode $code,?StoredBooking $booking=null,array $issues=[],bool $success=false):BookingProgramChangeResult{$this->rollbackIfActive();return new BookingProgramChangeResult($code,$success,$command->bookingId,$booking?->program,$booking?->program,$issues);}
    private function rollbackIfActive():void{if($this->pdo->inTransaction())try{$this->pdo->rollBack();}catch(Throwable){}}
}

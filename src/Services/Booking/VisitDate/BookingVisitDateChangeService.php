<?php
declare(strict_types=1);
namespace GeoFort\Services\Booking\VisitDate;

use DateTimeImmutable;
use GeoFort\Booking\BookingPolicy;
use GeoFort\Booking\Capacity\{BookingCapacityValidator,CapacityLimitProvider,CapacityValidationCode,DayCapacityTotals,EffectiveDayCapacity};
use GeoFort\Booking\Rules\{AuthenticatedAdminBookingOverrideAuthorizationService,BookingOverrideAuthorizationService,BookingRuleContextFingerprint,BookingRuleOverridePolicy,UsedBookingRuleOverride};
use GeoFort\Booking\Stored\StoredBooking;
use GeoFort\Booking\Validation\{BookingValidationContext,BookingValidationCoordinator,BookingValidationProfile,StoredBookingIssue,StoredBookingIssueCategory,StoredBookingVisitDateValidator};
use GeoFort\Booking\VisitDate\{BookingVisitDateChangeCode,BookingVisitDateChangeCommand,BookingVisitDateChangeResult};
use GeoFort\Services\Sql\{BookingCalendarSqlService,BookingChangeHistorySqlRepository,BookingDaySettingsSqlRepository,BookingRuleOverrideSqlRepository,BookingVisitDateSqlRepository,StoredBookingSqlRepository};
use PDO;
use RuntimeException;
use Throwable;

final readonly class BookingVisitDateChangeService
{
    public function __construct(
        private PDO $pdo,
        private StoredBookingSqlRepository $bookings,
        private BookingVisitDateSqlRepository $dates,
        private BookingChangeHistorySqlRepository $history,
        private BookingDaySettingsSqlRepository $daySettings,
        private BookingCalendarSqlService $calendar,
        private StoredBookingVisitDateValidator $dateValidator,
        private BookingValidationCoordinator $coordinator,
        private CapacityLimitProvider $capacityLimits,
        private BookingCapacityValidator $capacityValidator,
        private BookingRuleOverrideSqlRepository $overrideAudit,
        private BookingRuleOverridePolicy $overridePolicy=new BookingRuleOverridePolicy(),
        private BookingOverrideAuthorizationService $authorization=new AuthenticatedAdminBookingOverrideAuthorizationService(),
        private BookingRuleContextFingerprint $fingerprint=new BookingRuleContextFingerprint(),
    ) {}

    public function change(BookingVisitDateChangeCommand $command, ?DateTimeImmutable $today=null): BookingVisitDateChangeResult
    {
        $previous=null;
        try {
            if(!$this->pdo->beginTransaction()) throw new RuntimeException('Transactie kon niet worden gestart.');
            $booking=$this->bookings->findByIdForUpdate($command->bookingId);
            if(!$booking) return $this->rollback($command,BookingVisitDateChangeCode::BookingNotFound);
            $previous=$booking;
            if($booking->visitDate!==$command->expectedVisitDate) return $this->rollback($command,BookingVisitDateChangeCode::VisitDateConflict,$booking);
            if($booking->visitDate===$command->proposedVisitDate)return $this->rollback($command,BookingVisitDateChangeCode::NoVisitDateChange,$booking,success:true);

            $proposed=$booking->withVisitDate($command->proposedVisitDate);
            $confirmed=$booking->status===BookingPolicy::STATUS_CONFIRMED;
            $profile=$confirmed?BookingValidationProfile::ChangeVisitDateConfirmed:BookingValidationProfile::ChangeVisitDateDraft;
            $totals=null;$limits=null;$used=[];

            if($confirmed){
                $settings=$this->daySettings->lockDate($proposed->visitDate);
                $base=$this->dateValidator->validate($proposed,true,$today??new DateTimeImmutable('today'));
                $stats=$this->calendar->getBookingStatsForDate($proposed->visitDate,$booking->id);
                $totals=new DayCapacityTotals($stats['bookedSchools'],$stats['bookedStudents']);
                $limits=$this->effectiveCapacity($proposed->visitDate,$settings->maxSchoolsOverride,$settings->maxStudentsOverride);
                $capacity=$this->capacityValidator->validate($totals,$proposed->studentCount,$limits);
                $capacityIssue=$capacity->allowed?null:$this->capacityIssue($capacity->code,$booking,$proposed,$totals,$limits,$capacity->projectedSchools,$capacity->projectedStudents);
                $validation=$this->coordinator->validate($profile,$proposed,new BookingValidationContext($base->issues,$capacityIssue));
            } else {
                $base=$this->dateValidator->validate($proposed,false,$today??new DateTimeImmutable('today'));
                $validation=$this->coordinator->validate($profile,$proposed,new BookingValidationContext($base->issues));
                if($command->requestedOverrides!==[]){
                    return $this->rollback($command,BookingVisitDateChangeCode::OverrideNotAllowed,$booking,$validation->issues);
                }
            }

            $hard=array_filter($validation->issues,static fn(StoredBookingIssue $issue):bool=>!$issue->overridable);
            if($hard!==[]){
                $code=array_filter($validation->issues,static fn(StoredBookingIssue $issue):bool=>$issue->code==='INVALID_VISIT_DATE')!==[]
                    ? BookingVisitDateChangeCode::InvalidVisitDate
                    : BookingVisitDateChangeCode::InvalidStoredBooking;
                return $this->rollback($command,$code,$booking,$validation->issues);
            }
            if($confirmed){
                $overrideResult=$this->validateOverrides($command,$booking,$proposed,$validation->issues,$totals,$limits);
                if($overrideResult instanceof BookingVisitDateChangeCode)return $this->rollback($command,$overrideResult,$booking,$validation->issues);
                $used=$overrideResult;
            }
            if(!$this->dates->guardedUpdate($booking->id,$command->expectedVisitDate,$proposed->visitDate))return $this->rollback($command,BookingVisitDateChangeCode::VisitDateConflict,$booking);
            $historyId=$this->history->insertVisitDateChange($booking->id,['bezoekdatum'=>['before'=>$booking->visitDate,'after'=>$proposed->visitDate]],$command->actingAdminId);
            foreach($used as $override)$this->overrideAudit->insertForChange($booking->id,$historyId,$override,$command->actingAdminId);
            if(!$this->pdo->commit())throw new RuntimeException('Transactie kon niet worden vastgelegd.');
            return new BookingVisitDateChangeResult(BookingVisitDateChangeCode::Success,true,$booking->id,$booking->visitDate,$proposed->visitDate,$validation->issues,$used,$historyId);
        } catch(Throwable $exception){
            error_log(sprintf('Booking visit date change failure: exception=%s booking=%d',$exception::class,$command->bookingId));
            $this->rollbackIfActive();
            return new BookingVisitDateChangeResult(BookingVisitDateChangeCode::DatabaseError,false,$command->bookingId,$previous?->visitDate,$previous?->visitDate);
        }
    }

    private function capacityIssue(CapacityValidationCode $code,StoredBooking $previous,StoredBooking $proposed,DayCapacityTotals $totals,EffectiveDayCapacity $limits,int $projectedSchools,?int $projectedStudents):StoredBookingIssue
    {
        if($code===CapacityValidationCode::InvalidStudentCount)return new StoredBookingIssue($code->value,StoredBookingIssueCategory::Capacity,'aantalLeerlingen',metadata:['bookingStudents'=>$proposed->studentCount]);
        $metadata=$code===CapacityValidationCode::SchoolLimitExceeded
            ?['confirmedSchoolsExcludingBooking'=>$totals->confirmedSchools,'projectedSchools'=>$projectedSchools,'maximumSchools'=>$limits->effectiveMaxSchools]
            :['confirmedStudentsExcludingBooking'=>$totals->confirmedStudents,'previousBookingStudents'=>$previous->studentCount,'proposedBookingStudents'=>$proposed->studentCount,'projectedStudents'=>$projectedStudents,'maximumStudents'=>$limits->effectiveMaxStudents];
        return new StoredBookingIssue($code->value,StoredBookingIssueCategory::Capacity,$code===CapacityValidationCode::SchoolLimitExceeded?'bezoekdatum':'aantalLeerlingen',metadata:$metadata);
    }

    /** @param list<StoredBookingIssue> $issues @return list<UsedBookingRuleOverride>|BookingVisitDateChangeCode */
    private function validateOverrides(BookingVisitDateChangeCommand $command,StoredBooking $previous,StoredBooking $proposed,array $issues,?DayCapacityTotals $totals,?EffectiveDayCapacity $limits):array|BookingVisitDateChangeCode
    {
        $actual=[];foreach($issues as $issue)if($issue->overridable)$actual[$issue->code]=$issue;
        $requested=[];foreach($command->requestedOverrides as $request){if(isset($requested[$request->ruleCode]))return BookingVisitDateChangeCode::InvalidOverrideRequest;if(!$this->overridePolicy->definition($request->ruleCode)->overridable)return BookingVisitDateChangeCode::OverrideNotAllowed;$requested[$request->ruleCode]=$request;}
        if(array_diff_key($requested,$actual)!==[]||array_diff_key($actual,$requested)!==[])return $actual!==[]&&$requested===[]?BookingVisitDateChangeCode::OverrideRequired:BookingVisitDateChangeCode::InvalidOverrideRequest;
        $used=[];foreach($actual as $code=>$issue){$definition=$this->overridePolicy->definition($code);if(!$this->authorization->isAllowed($command->actingAdminId,$definition))return BookingVisitDateChangeCode::OverridePermissionDenied;$used[]=new UsedBookingRuleOverride($code,$requested[$code]->reason,$this->fingerprint->createForVisitDateChange($code,$previous,$proposed,$issue->metadata,$totals,$limits),$issue->metadata);}
        return $used;
    }
    private function effectiveCapacity(string $date,?int $schools,?int $students):EffectiveDayCapacity{$base=$this->capacityLimits->forDate($date);return new EffectiveDayCapacity($base->standardMaxSchools,$base->standardMaxStudents,$schools,$students,$schools??$base->effectiveMaxSchools,$students??$base->effectiveMaxStudents);}
    /** @param list<StoredBookingIssue> $issues */
    private function rollback(BookingVisitDateChangeCommand $command,BookingVisitDateChangeCode $code,?StoredBooking $booking=null,array $issues=[],bool $success=false):BookingVisitDateChangeResult{$this->rollbackIfActive();return new BookingVisitDateChangeResult($code,$success,$command->bookingId,$booking?->visitDate,$booking?->visitDate,$issues);}
    private function rollbackIfActive():void{if($this->pdo->inTransaction())try{$this->pdo->rollBack();}catch(Throwable){}}
}

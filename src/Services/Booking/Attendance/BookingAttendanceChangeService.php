<?php
declare(strict_types=1);
namespace GeoFort\Services\Booking\Attendance;

use DateTimeImmutable;
use GeoFort\Booking\Attendance\{BookingAttendanceCapacity,BookingAttendanceChangeCode,BookingAttendanceChangeCommand,BookingAttendanceChangeResult};
use GeoFort\Booking\BookingPolicy;
use GeoFort\Booking\Capacity\{BookingCapacityValidator,CapacityLimitProvider,CapacityValidationCode,DayCapacityTotals,EffectiveDayCapacity};
use GeoFort\Booking\Rules\{AuthenticatedAdminBookingOverrideAuthorizationService,BookingOverrideAuthorizationService,BookingRuleContextFingerprint,BookingRuleOverridePolicy,BookingRuleSeverity,UsedBookingRuleOverride};
use GeoFort\Booking\Stored\StoredBooking;
use GeoFort\Booking\Validation\{StoredBookingIssue,StoredBookingValidationResult,StoredBookingValidator};
use GeoFort\Services\Sql\{BookingAttendanceSqlRepository,BookingCalendarSqlService,BookingChangeHistorySqlRepository,BookingDaySettingsSqlRepository,BookingRuleOverrideSqlRepository,StoredBookingSqlRepository};
use PDO;
use RuntimeException;
use Throwable;

final readonly class BookingAttendanceChangeService
{
    public function __construct(
        private PDO $pdo, private StoredBookingSqlRepository $bookings,
        private BookingAttendanceSqlRepository $attendance, private BookingChangeHistorySqlRepository $history,
        private BookingDaySettingsSqlRepository $daySettings, private BookingCalendarSqlService $calendar,
        private StoredBookingValidator $validator, private CapacityLimitProvider $capacityLimits,
        private BookingCapacityValidator $capacityValidator, private BookingRuleOverrideSqlRepository $overrideAudit,
        private BookingRuleOverridePolicy $overridePolicy = new BookingRuleOverridePolicy(),
        private BookingOverrideAuthorizationService $authorization = new AuthenticatedAdminBookingOverrideAuthorizationService(),
        private BookingRuleContextFingerprint $fingerprint = new BookingRuleContextFingerprint(),
    ) {}

    public function change(BookingAttendanceChangeCommand $command, ?DateTimeImmutable $today = null): BookingAttendanceChangeResult
    {
        $previous = null;
        try {
            if (!$this->pdo->beginTransaction()) throw new RuntimeException('Transactie kon niet worden gestart.');
            $booking = $this->bookings->findByIdForUpdate($command->bookingId);
            if (!$booking) return $this->rollback($command, BookingAttendanceChangeCode::BookingNotFound);
            $previous = $booking;
            if ($booking->studentCount !== $command->expectedStudentCount || $booking->supervisorCount !== $command->expectedSupervisorCount) {
                return $this->rollback($command, BookingAttendanceChangeCode::AttendanceConflict, $booking);
            }
            if ($command->newStudentCount === $booking->studentCount && $command->newSupervisorCount === $booking->supervisorCount) {
                return $this->rollback($command, BookingAttendanceChangeCode::NoChanges, $booking);
            }
            if (!$this->technicallyValid($booking, $command->newStudentCount, $command->newSupervisorCount)) {
                return $this->rollback($command, BookingAttendanceChangeCode::InvalidRequest, $booking);
            }
            $proposed = $booking->withAttendance($command->newStudentCount, $command->newSupervisorCount);
            $isConfirmed = $booking->status === BookingPolicy::STATUS_CONFIRMED;
            $validation = new StoredBookingValidationResult([]);
            $capacity = null;
            $usedOverrides = [];
            if ($isConfirmed) {
                $settings = $this->daySettings->lockDate($booking->visitDate);
                $totalsArray = $this->calendar->getBookingStatsForDate($booking->visitDate, $booking->id);
                $totals = new DayCapacityTotals($totalsArray['bookedSchools'], $totalsArray['bookedStudents']);
                $limits = $this->effectiveCapacity($booking->visitDate, $settings->maxSchoolsOverride, $settings->maxStudentsOverride);
                $capacityResult = $this->capacityValidator->validate($totals, $proposed->studentCount, $limits);
                $capacity = new BookingAttendanceCapacity($totals->confirmedSchools, $totals->confirmedStudents, $booking->studentCount, $proposed->studentCount, $capacityResult->projectedSchools, $capacityResult->projectedStudents ?? $totals->confirmedStudents, $limits->effectiveMaxSchools, $limits->effectiveMaxStudents);
                $validation = $this->classifiedValidation($proposed, $today ?? new DateTimeImmutable('today'), $capacityResult->code, $capacity, $capacityResult->allowed);
                $hard = array_filter($validation->issues, static fn(StoredBookingIssue $issue): bool => !$issue->overridable);
                if ($hard !== []) return $this->rollback($command, BookingAttendanceChangeCode::InvalidStoredBooking, $booking, $validation->issues, $capacity);
                $overrideResult = $this->validateOverrides($command, $booking, $proposed, $validation->issues, $totals, $limits);
                if ($overrideResult instanceof BookingAttendanceChangeCode) return $this->rollback($command, $overrideResult, $booking, $validation->issues, $capacity);
                $usedOverrides = $overrideResult;
            } elseif ($command->requestedOverrides !== []) {
                return $this->rollback($command, BookingAttendanceChangeCode::OverrideNotAllowed, $booking, $validation->issues, $capacity);
            }
            if (!$this->attendance->guardedUpdate($booking->id, $command->expectedStudentCount, $command->expectedSupervisorCount, $command->newStudentCount, $command->newSupervisorCount)) {
                return $this->rollback($command, BookingAttendanceChangeCode::AttendanceConflict, $booking);
            }
            $fields=[];
            if ($booking->studentCount !== $proposed->studentCount) $fields['aantal_leerlingen']=['before'=>$booking->studentCount,'after'=>$proposed->studentCount];
            if ($booking->supervisorCount !== $proposed->supervisorCount) $fields['aantal_begeleiders']=['before'=>$booking->supervisorCount,'after'=>$proposed->supervisorCount];
            $historyId=$this->history->insertAttendanceChange($booking->id,$fields,$command->actingAdminId);
            foreach ($usedOverrides as $override) $this->overrideAudit->insertForChange($booking->id,$historyId,$override,$command->actingAdminId);
            if (!$this->pdo->commit()) throw new RuntimeException('Transactie kon niet worden vastgelegd.');
            return new BookingAttendanceChangeResult(BookingAttendanceChangeCode::Success,true,$booking->id,$booking->studentCount,$proposed->studentCount,$booking->supervisorCount,$proposed->supervisorCount,$validation->issues,$capacity,$usedOverrides,$historyId);
        } catch (Throwable $exception) {
            error_log(sprintf('Booking attendance change failure: exception=%s booking=%d',$exception::class,$command->bookingId));
            $this->rollbackIfActive();
            return new BookingAttendanceChangeResult(BookingAttendanceChangeCode::DatabaseError,false,$command->bookingId,$previous?->studentCount,$previous?->studentCount,$previous?->supervisorCount,$previous?->supervisorCount);
        }
    }

    private function technicallyValid(StoredBooking $booking, int $students, int $supervisors): bool
    { return $students > 0 && $students <= BookingPolicy::getMaxStudentsOfProgram($booking->program) && $supervisors >= 0 && $supervisors <= BookingPolicy::MAX_SUPERVISORS_PER_BOOKING; }

    private function classifiedValidation(StoredBooking $booking, DateTimeImmutable $today, CapacityValidationCode $capacityCode, BookingAttendanceCapacity $capacity, bool $capacityAllowed): StoredBookingValidationResult
    {
        $issues=array_map(fn(StoredBookingIssue $i)=>$this->classify($i),$this->validator->validateForTargetStatus($booking,BookingPolicy::STATUS_CONFIRMED,$today)->issues);
        if (!$capacityAllowed) {
            $metadata=$capacityCode===CapacityValidationCode::SchoolLimitExceeded
                ? ['confirmedSchoolsExcludingBooking'=>$capacity->confirmedSchoolsExcludingBooking,'projectedSchools'=>$capacity->projectedSchools,'maximumSchools'=>$capacity->maximumSchools]
                : ['confirmedStudentsExcludingBooking'=>$capacity->confirmedStudentsExcludingBooking,'previousBookingStudents'=>$capacity->previousBookingStudents,'proposedBookingStudents'=>$capacity->proposedBookingStudents,'projectedStudents'=>$capacity->projectedStudents,'maximumStudents'=>$capacity->maximumStudents];
            $issues[]=$this->classify(new StoredBookingIssue($capacityCode->value,\GeoFort\Booking\Validation\StoredBookingIssueCategory::Capacity,'bezoekdatum',metadata:$metadata));
        }
        return new StoredBookingValidationResult($issues);
    }
    private function classify(StoredBookingIssue $issue): StoredBookingIssue
    {
        $definition=$this->overridePolicy->definition($issue->code);
        return new StoredBookingIssue($issue->code,$issue->category,$issue->field,$definition->overridable?$definition->severity:BookingRuleSeverity::Error,$definition->overridable,$definition->title,$definition->description,$issue->metadata);
    }
    /** @param list<StoredBookingIssue> $issues @return list<UsedBookingRuleOverride>|BookingAttendanceChangeCode */
    private function validateOverrides(BookingAttendanceChangeCommand $command, StoredBooking $previous, StoredBooking $proposed, array $issues, DayCapacityTotals $totals, EffectiveDayCapacity $limits): array|BookingAttendanceChangeCode
    {
        $actual=[]; foreach($issues as $issue) if($issue->overridable) $actual[$issue->code]=$issue;
        $requested=[]; foreach($command->requestedOverrides as $request){ if(isset($requested[$request->ruleCode])) return BookingAttendanceChangeCode::InvalidOverrideRequest; if(!$this->overridePolicy->definition($request->ruleCode)->overridable) return BookingAttendanceChangeCode::OverrideNotAllowed; $requested[$request->ruleCode]=$request; }
        if(array_diff_key($requested,$actual)!==[] || array_diff_key($actual,$requested)!==[]) return $actual!==[]&&$requested===[]?BookingAttendanceChangeCode::OverrideRequired:BookingAttendanceChangeCode::InvalidOverrideRequest;
        $used=[]; foreach($actual as $code=>$issue){$definition=$this->overridePolicy->definition($code); if(!$this->authorization->isAllowed($command->actingAdminId,$definition)) return BookingAttendanceChangeCode::OverridePermissionDenied; $used[]=new UsedBookingRuleOverride($code,$requested[$code]->reason,$this->fingerprint->createForAttendanceChange($code,$previous,$proposed,$issue->metadata,$totals,$limits),$issue->metadata);}
        return $used;
    }
    private function effectiveCapacity(string $date,?int $schools,?int $students):EffectiveDayCapacity{$base=$this->capacityLimits->forDate($date);return new EffectiveDayCapacity($base->standardMaxSchools,$base->standardMaxStudents,$schools,$students,$schools??$base->effectiveMaxSchools,$students??$base->effectiveMaxStudents);}
    /** @param list<StoredBookingIssue> $issues */
    private function rollback(BookingAttendanceChangeCommand $c,BookingAttendanceChangeCode $code,?StoredBooking $b=null,array $issues=[],?BookingAttendanceCapacity $capacity=null):BookingAttendanceChangeResult{$this->rollbackIfActive();return new BookingAttendanceChangeResult($code,false,$c->bookingId,$b?->studentCount,$b?->studentCount,$b?->supervisorCount,$b?->supervisorCount,$issues,$capacity);}
    private function rollbackIfActive():void{if($this->pdo->inTransaction())try{$this->pdo->rollBack();}catch(Throwable){}}
}

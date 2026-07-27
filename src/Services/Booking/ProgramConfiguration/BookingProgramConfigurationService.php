<?php
declare(strict_types=1);
namespace GeoFort\Services\Booking\ProgramConfiguration;

use DateTimeImmutable;
use GeoFort\Booking\BookingPolicy;
use GeoFort\Booking\BookingProgramConfig;
use GeoFort\Booking\Capacity\{BookingCapacityValidator,CapacityLimitProvider,CapacityValidationCode,DayCapacityTotals,EffectiveDayCapacity};
use GeoFort\Booking\ProgramConfiguration\{BookingProgramConfigurationCode,BookingProgramConfigurationCommand,BookingProgramConfigurationResult,BookingProgramConfigurationSnapshot,ProgramConfigurationIssueFactory};
use GeoFort\Booking\Rules\{AuthenticatedAdminBookingOverrideAuthorizationService,BookingOverrideAuthorizationService,BookingRuleContextFingerprint,BookingRuleOverrideRequest,ProgramConfigurationOverridePolicy,UsedBookingRuleOverride};
use GeoFort\Booking\Stored\StoredBooking;
use GeoFort\Booking\Validation\{ProgramStudentLimitValidator,StoredBookingIssue,StoredBookingIssueCategory,StoredBookingStudentCountValidator};
use GeoFort\Services\Sql\{BookingCalendarSqlService,BookingChangeHistorySqlRepository,BookingDaySettingsSqlRepository,BookingProgramConfigurationSqlRepository,BookingRuleOverrideSqlRepository,StoredBookingSqlRepository};
use GeoFort\Validation\{ChoiceModuleSelectionValidator,EducationSelectionValidator,FieldValidationException};
use PDO;
use RuntimeException;
use Throwable;

final readonly class BookingProgramConfigurationService
{
    public function __construct(
        private PDO $pdo,
        private StoredBookingSqlRepository $bookings,
        private BookingProgramConfigurationSqlRepository $configurations,
        private BookingChangeHistorySqlRepository $history,
        private BookingRuleOverrideSqlRepository $overrideAudit,
        private BookingDaySettingsSqlRepository $daySettings,
        private BookingCalendarSqlService $calendar,
        private CapacityLimitProvider $capacityLimits,
        private BookingCapacityValidator $capacityValidator,
        private ProgramConfigurationOverridePolicy $overridePolicy = new ProgramConfigurationOverridePolicy(),
        private BookingOverrideAuthorizationService $authorization = new AuthenticatedAdminBookingOverrideAuthorizationService(),
        private BookingRuleContextFingerprint $fingerprint = new BookingRuleContextFingerprint(),
        private EducationSelectionValidator $educationValidator = new EducationSelectionValidator(),
        private ChoiceModuleSelectionValidator $moduleValidator = new ChoiceModuleSelectionValidator(),
        private StoredBookingStudentCountValidator $studentValidator = new StoredBookingStudentCountValidator(),
        private ProgramStudentLimitValidator $programLimitValidator = new ProgramStudentLimitValidator(),
    ) {}

    public function change(BookingProgramConfigurationCommand $command): BookingProgramConfigurationResult
    {
        $current = null;
        try {
            if (!$this->pdo->beginTransaction()) throw new RuntimeException('Transactie kon niet worden gestart.');
            $stored = $this->bookings->findByIdForUpdate($command->bookingId);
            if ($stored === null) return $this->rollback($command, BookingProgramConfigurationCode::NotFound);
            $current = BookingProgramConfigurationSnapshot::fromBooking($stored);
            if (!$current->equals($command->expected)) return $this->rollback($command, BookingProgramConfigurationCode::Conflict, $current);
            if ($this->configurationEquals($current, $command->proposed)) return $this->rollback($command, BookingProgramConfigurationCode::NoChange, $current, success:true);
            if (!BookingPolicy::isAllowedStatus($stored->status)) {
                return $this->rollback($command, BookingProgramConfigurationCode::InvalidConfiguration, $current, [
                    ProgramConfigurationIssueFactory::create('INVALID_STATUS', 'status', category: StoredBookingIssueCategory::Structural),
                ]);
            }

            [$proposed, $issues] = $this->buildAndValidate($stored, $command->proposed);
            if ($proposed === null) return $this->rollback($command, BookingProgramConfigurationCode::InvalidConfiguration, $current, $issues);

            $totals = null;
            $limits = null;
            if ($stored->status === BookingPolicy::STATUS_CONFIRMED) {
                $settings = $this->daySettings->lockDate($stored->visitDate);
                $stats = $this->calendar->getBookingStatsForDate($stored->visitDate, $stored->id);
                $totals = new DayCapacityTotals($stats['bookedSchools'], $stats['bookedStudents']);
                $limits = $this->effectiveCapacity($stored->visitDate, $settings->maxSchoolsOverride, $settings->maxStudentsOverride);
                $capacity = $this->capacityValidator->validate($totals, $proposed->studentCount, $limits);
                if (!$capacity->allowed) $issues[] = $this->capacityIssue($capacity->code, $proposed, $totals, $limits, $capacity->projectedSchools, $capacity->projectedStudents);
            }

            $programLimit = $this->programLimitValidator->validate($proposed);
            if ($programLimit !== null) $issues[] = $programLimit;
            $issues = $this->classify($issues, $stored->status === BookingPolicy::STATUS_CONFIRMED);
            $hard = array_filter($issues, static fn(StoredBookingIssue $issue): bool => !$issue->overridable);
            if ($hard !== []) return $this->rollback($command, BookingProgramConfigurationCode::InvalidConfiguration, $current, $issues);
            $overrides = $this->validateOverrides($command->requestedOverrides, $command->actingAdminId, $stored, $proposed, $issues, $totals, $limits);
            if ($overrides instanceof BookingProgramConfigurationCode) return $this->rollback($command, $overrides, $current, $issues);

            if (!$this->configurations->guardedUpdate($stored->id, $command->expected, BookingProgramConfigurationSnapshot::fromBooking($proposed))) {
                return $this->rollback($command, BookingProgramConfigurationCode::Conflict, $current);
            }
            $changed = $this->changedFields($stored, $proposed);
            $historyId = $this->history->insertProgramConfigurationChange($stored->id, $changed, $command->actingAdminId);
            foreach ($overrides as $override) $this->overrideAudit->insertForChange($stored->id, $historyId, $override, $command->actingAdminId);
            if (!$this->pdo->commit()) throw new RuntimeException('Transactie kon niet worden vastgelegd.');
            return new BookingProgramConfigurationResult(BookingProgramConfigurationCode::Success, true, $stored->id, BookingProgramConfigurationSnapshot::fromBooking($proposed), $issues, $overrides, $historyId);
        } catch (Throwable $exception) {
            error_log(sprintf('Booking program configuration failure: exception=%s booking=%d', $exception::class, $command->bookingId));
            $this->rollbackIfActive();
            return new BookingProgramConfigurationResult(BookingProgramConfigurationCode::DatabaseError, false, $command->bookingId, $current);
        }
    }

    /** @return array{?StoredBooking,list<StoredBookingIssue>} */
    private function buildAndValidate(StoredBooking $stored, BookingProgramConfigurationSnapshot $input): array
    {
        $issues = [];
        if (!BookingProgramConfig::programExists($input->program)) {
            return [null, [ProgramConfigurationIssueFactory::create('INVALID_CONFIGURATION_KEY', 'program', category: StoredBookingIssueCategory::Structural)]];
        }
        if (!in_array($stored->schoolSector, BookingProgramConfig::PROGRAMS[$input->program]['allowedSchoolTypes'], true)) {
            $issues[] = new StoredBookingIssue('PROGRAM_SCHOOL_SECTOR_MISMATCH', StoredBookingIssueCategory::Policy, 'programma');
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $stored->visitDate);
        if ($date === false || $date->format('Y-m-d') !== $stored->visitDate) {
            return [null, [ProgramConfigurationIssueFactory::create('INVALID_VISIT_DATE', 'visitDate', category: StoredBookingIssueCategory::Structural)]];
        }
        if (!in_array((int)$date->format('N'), BookingProgramConfig::PROGRAMS[$input->program]['allowedWeekdays'], true)) {
            $issues[] = new StoredBookingIssue(ProgramConfigurationOverridePolicy::PROGRAM_WEEKDAY_MISMATCH, StoredBookingIssueCategory::Policy, 'programma', metadata:['program'=>$input->program,'visitDate'=>$stored->visitDate,'weekday'=>(int)$date->format('N')]);
        }
        try {
            $educationIssue = $this->educationIssue($input, $stored->schoolSector);
            if ($educationIssue !== null) {
                return [null, [$educationIssue]];
            }
            $education = $this->educationValidator->validate($input->educationSelection->toJson(), $stored->schoolSector);
            $this->studentValidator->validate($input->studentCount, $stored->schoolSector, $input->program);
            if (!is_string($input->choiceModule) || trim($input->choiceModule) === '') {
                return [null, [ProgramConfigurationIssueFactory::create('MISSING_CHOICE_MODULE', 'choiceModule')]];
            }
            $module = $this->moduleValidator->validate($input->choiceModule, $stored->schoolSector, $input->program, $education);
        } catch (FieldValidationException $exception) {
            $issues[] = $exception->getField() === 'keuzemodule'
                ? ProgramConfigurationIssueFactory::create('INVALID_CHOICE_MODULE', 'choiceModule')
                : (in_array($exception->getField(), ['aantalLeerlingen','aantal_leerlingen','studentCount'], true)
                    ? ProgramConfigurationIssueFactory::create('INVALID_STUDENT_COUNT', 'studentCount')
                    : ProgramConfigurationIssueFactory::create('INVALID_EDUCATION_SELECTION', $exception->getField()));
            return [null, $issues];
        } catch (\InvalidArgumentException|\LogicException) {
            return [null, [ProgramConfigurationIssueFactory::create('INVALID_CONFIGURATION_KEY', 'configuration', category: StoredBookingIssueCategory::Structural)]];
        }
        return [$stored->withProgramConfiguration($input->program, $input->studentCount, $education, $module === '' ? null : $module), $issues];
    }

    private function educationIssue(BookingProgramConfigurationSnapshot $input, string $sector): ?StoredBookingIssue
    {
        $selection = $input->educationSelection;
        if ($selection->sector !== $sector || !isset(BookingProgramConfig::SCHOOL_LEVELS[$sector], BookingProgramConfig::SCHOOL_LEVEL_SELECTION_RULES[$sector])) {
            return ProgramConfigurationIssueFactory::create('INVALID_EDUCATION_SELECTION', 'educationSelection');
        }
        $levels = BookingProgramConfig::SCHOOL_LEVELS[$sector];
        $rules = BookingProgramConfig::SCHOOL_LEVEL_SELECTION_RULES[$sector];
        $selected = array_values(array_unique($selection->selectedLevels));
        if (count($selected) > (int) $rules['maxLevels']) {
            return ProgramConfigurationIssueFactory::create('LEVEL_SELECTION_LIMIT_EXCEEDED', 'educationSelection.selectedLevels', [
                'sector'=>$sector, 'maximum'=>(int)$rules['maxLevels'], 'selected'=>count($selected),
            ]);
        }
        foreach ($selected as $level) {
            if (!isset($levels[$level])) return ProgramConfigurationIssueFactory::create('UNKNOWN_EDUCATION_LEVEL', 'educationSelection.selectedLevels', ['sector'=>$sector,'level'=>$level]);
        }
        foreach ($selection->selectedGroupsByLevel as $level => $groups) {
            if ($groups !== [] && !in_array($level, $selected, true)) return ProgramConfigurationIssueFactory::create('GROUP_WITHOUT_SELECTED_LEVEL', "educationSelection.selectedGroupsByLevel.{$level}", ['sector'=>$sector,'level'=>$level]);
        }
        foreach ($selected as $level) {
            $groups = array_values(array_unique($selection->selectedGroupsByLevel[$level] ?? []));
            if (count($groups) > (int) $rules['maxGroupsPerLevel']) {
                return ProgramConfigurationIssueFactory::create('GROUP_SELECTION_LIMIT_EXCEEDED', "educationSelection.selectedGroupsByLevel.{$level}", [
                    'sector'=>$sector, 'level'=>$level, 'maximum'=>(int)$rules['maxGroupsPerLevel'], 'selected'=>count($groups),
                ]);
            }
            foreach ($groups as $group) {
                if (!isset($levels[$level]['groups'][$group])) return ProgramConfigurationIssueFactory::create('UNKNOWN_EDUCATION_GROUP', "educationSelection.selectedGroupsByLevel.{$level}", ['sector'=>$sector,'level'=>$level,'group'=>$group]);
            }
        }
        return null;
    }

    /** @param list<StoredBookingIssue> $issues @return list<StoredBookingIssue> */
    private function classify(array $issues, bool $allowGeneralOverrides): array
    {
        return array_map(function(StoredBookingIssue $issue) use ($allowGeneralOverrides): StoredBookingIssue {
            $definition = $this->overridePolicy->definition($issue->code);
            $overridable = $definition->overridable
                && ($allowGeneralOverrides || $issue->code === ProgramConfigurationOverridePolicy::PROGRAM_WEEKDAY_MISMATCH);
            return new StoredBookingIssue($issue->code, $issue->category, $issue->field, $overridable ? $definition->severity : $issue->severity, $overridable, $definition->title, $definition->description, $issue->metadata);
        }, $issues);
    }

    /** @param list<BookingRuleOverrideRequest> $requests @param list<StoredBookingIssue> $issues @return list<UsedBookingRuleOverride>|BookingProgramConfigurationCode */
    private function validateOverrides(array $requests, int $adminId, StoredBooking $previous, StoredBooking $proposed, array $issues, ?DayCapacityTotals $totals, ?EffectiveDayCapacity $limits): array|BookingProgramConfigurationCode
    {
        $actual=[]; foreach($issues as $issue) if($issue->overridable) $actual[$issue->code]=$issue;
        $requested=[]; foreach($requests as $request) {
            if(isset($requested[$request->ruleCode])) return BookingProgramConfigurationCode::InvalidOverrideRequest;
            if(!$this->overridePolicy->definition($request->ruleCode)->overridable) return BookingProgramConfigurationCode::OverrideNotAllowed;
            $requested[$request->ruleCode]=$request;
        }
        if(array_diff_key($requested,$actual)!==[]||array_diff_key($actual,$requested)!==[]) return $actual!==[]&&$requested===[] ? BookingProgramConfigurationCode::OverrideRequired : BookingProgramConfigurationCode::InvalidOverrideRequest;
        $used=[]; foreach($actual as $code=>$issue) {
            $definition=$this->overridePolicy->definition($code);
            if(!$this->authorization->isAllowed($adminId,$definition)) return BookingProgramConfigurationCode::OverridePermissionDenied;
            $used[]=new UsedBookingRuleOverride($code,$requested[$code]->reason,$this->fingerprint->createForProgramConfigurationChange($code,$previous,$proposed,$issue->metadata,$totals,$limits),$issue->metadata);
        }
        return $used;
    }

    /** @return array<string,array{before:mixed,after:mixed}> */
    private function changedFields(StoredBooking $before, StoredBooking $after): array
    {
        $values=[
            'programma'=>[$before->program,$after->program],
            'aantal_leerlingen'=>[$before->studentCount,$after->studentCount],
            'education_selection'=>[$before->educationSelection->toArray(),$after->educationSelection->toArray()],
            'keuzemodule_key'=>[$before->choiceModuleKey,$after->choiceModuleKey],
        ];
        $changed=[]; foreach($values as $field=>[$old,$new]) if($old!==$new) $changed[$field]=['before'=>$old,'after'=>$new];
        return $changed;
    }

    private function configurationEquals(BookingProgramConfigurationSnapshot $a, BookingProgramConfigurationSnapshot $b): bool
    {
        return $a->program===$b->program && $a->studentCount===$b->studentCount && $a->choiceModule===$b->choiceModule && $a->educationSelection->toArray()===$b->educationSelection->toArray();
    }
    private function effectiveCapacity(string $date,?int $schools,?int $students):EffectiveDayCapacity{$base=$this->capacityLimits->forDate($date);return new EffectiveDayCapacity($base->standardMaxSchools,$base->standardMaxStudents,$schools,$students,$schools??$base->effectiveMaxSchools,$students??$base->effectiveMaxStudents);}
    private function capacityIssue(CapacityValidationCode $code,StoredBooking $proposed,DayCapacityTotals $totals,EffectiveDayCapacity $limits,int $projectedSchools,?int $projectedStudents):StoredBookingIssue
    {
        if($code===CapacityValidationCode::InvalidStudentCount)return new StoredBookingIssue($code->value,StoredBookingIssueCategory::Capacity,'aantalLeerlingen');
        $metadata=$code===CapacityValidationCode::SchoolLimitExceeded?['confirmedSchoolsExcludingBooking'=>$totals->confirmedSchools,'projectedSchools'=>$projectedSchools,'maximumSchools'=>$limits->effectiveMaxSchools]:['confirmedStudentsExcludingBooking'=>$totals->confirmedStudents,'proposedBookingStudents'=>$proposed->studentCount,'projectedStudents'=>$projectedStudents,'maximumStudents'=>$limits->effectiveMaxStudents];
        return new StoredBookingIssue($code->value,StoredBookingIssueCategory::Capacity,$code===CapacityValidationCode::SchoolLimitExceeded?'bezoekdatum':'aantalLeerlingen',metadata:$metadata);
    }
    /** @param list<StoredBookingIssue> $issues */
    private function rollback(BookingProgramConfigurationCommand $command,BookingProgramConfigurationCode $code,?BookingProgramConfigurationSnapshot $current=null,array $issues=[],bool $success=false):BookingProgramConfigurationResult{$this->rollbackIfActive();return new BookingProgramConfigurationResult($code,$success,$command->bookingId,$current,$issues);}
    private function rollbackIfActive():void{if($this->pdo->inTransaction())try{$this->pdo->rollBack();}catch(Throwable){}}
}

<?php

declare(strict_types=1);

namespace GeoFort\Services\Booking\Status;

use DateTimeImmutable;
use GeoFort\Booking\BookingPolicy;
use GeoFort\Booking\Capacity\BookingCapacityValidator;
use GeoFort\Booking\Capacity\CapacityLimitProvider;
use GeoFort\Booking\Capacity\CapacityValidationCode;
use GeoFort\Booking\Capacity\CapacityValidationResult;
use GeoFort\Booking\Capacity\DayCapacityTotals;
use GeoFort\Booking\Capacity\EffectiveDayCapacity;
use GeoFort\Booking\Status\BookingStatusChangeCode;
use GeoFort\Booking\Status\BookingStatusChangeCommand;
use GeoFort\Booking\Status\BookingStatusChangeResult;
use GeoFort\Booking\Status\BookingStatusMailMode;
use GeoFort\Booking\Status\BookingStatusTransitionPolicy;
use GeoFort\Booking\Status\BookingTransitionCode;
use GeoFort\Booking\Rules\AuthenticatedAdminBookingOverrideAuthorizationService;
use GeoFort\Booking\Rules\BookingOverrideAuthorizationService;
use GeoFort\Booking\Rules\BookingRuleContextFingerprint;
use GeoFort\Booking\Rules\BookingRuleOverridePolicy;
use GeoFort\Booking\Rules\UsedBookingRuleOverride;
use GeoFort\Booking\Validation\StoredBookingIssue;
use GeoFort\Booking\Validation\StoredBookingIssueCategory;
use GeoFort\Booking\Validation\StoredBookingValidationResult;
use GeoFort\Booking\Validation\StoredBookingValidator;
use GeoFort\Services\Sql\BookingCalendarSqlService;
use GeoFort\Services\Sql\BookingDaySettingsSqlRepository;
use GeoFort\Services\Sql\BookingStatusHistorySqlRepository;
use GeoFort\Services\Sql\BookingStatusSqlRepository;
use GeoFort\Services\Sql\BookingRuleOverrideSqlRepository;
use GeoFort\Services\Sql\DisabledDatesSqlService;
use GeoFort\Services\Sql\StoredBookingSqlRepository;
use GeoFort\Services\Booking\Pricing\BookingPriceCalculator;
use GeoFort\Services\Booking\Pricing\StoredBookingPricingInputFactory;
use PDO;
use RuntimeException;
use Throwable;

final readonly class BookingStatusChangeService
{
    public function __construct(
        private PDO $pdo,
        private StoredBookingSqlRepository $storedBookings,
        private BookingStatusSqlRepository $statuses,
        private BookingStatusHistorySqlRepository $history,
        private BookingDaySettingsSqlRepository $daySettings,
        private BookingCalendarSqlService $calendar,
        private DisabledDatesSqlService $disabledDates,
        private StoredBookingValidator $storedBookingValidator,
        private BookingStatusTransitionPolicy $transitionPolicy,
        private CapacityLimitProvider $capacityLimitProvider,
        private BookingCapacityValidator $capacityValidator,
        private StoredBookingPricingInputFactory $pricingInputFactory,
        private BookingPriceCalculator $priceCalculator,
        private BookingStatusMailSenderInterface $mailSender,
        private ?BookingRuleOverrideSqlRepository $overrideAudit = null,
        private ?BookingRuleOverridePolicy $overridePolicy = null,
        private ?BookingOverrideAuthorizationService $overrideAuthorization = null,
        private ?BookingRuleContextFingerprint $fingerprint = null,
    ) {
        if (
            !$this->disabledDates->usesConnection($this->pdo)
            || !$this->storedBookingValidator->usesConnection($this->pdo)
        ) {
            throw new RuntimeException('Disabled-datecontrole moet dezelfde databaseverbinding gebruiken.');
        }
    }

    public function change(BookingStatusChangeCommand $command, ?DateTimeImmutable $today = null): BookingStatusChangeResult
    {
        $previousStatus = null;

        try {
            if (!$this->pdo->beginTransaction()) {
                throw new RuntimeException('Statusmutatietransactie kon niet worden gestart.');
            }
            $booking = $this->storedBookings->findByIdForUpdate($command->bookingId);
            if ($booking === null) {
                return $this->rollbackResult($command, BookingStatusChangeCode::BookingNotFound, null, null);
            }

            $previousStatus = $booking->status;
            if ($previousStatus !== $command->expectedCurrentStatus) {
                return $this->rollbackResult($command, BookingStatusChangeCode::StatusConflict, $previousStatus, $previousStatus);
            }

            $decision = $this->transitionPolicy->decide($previousStatus, $command->targetStatus);
            if (!$decision->allowed) {
                return $this->rollbackResult($command, $this->transitionCode($decision->code), $previousStatus, $previousStatus);
            }

            if ($command->targetStatus !== BookingPolicy::STATUS_CONFIRMED && $command->requestedOverrides !== []) {
                return $this->rollbackResult($command, BookingStatusChangeCode::OverrideNotAllowed, $previousStatus, $previousStatus);
            }

            if ($command->mailMode === BookingStatusMailMode::Send && $command->targetStatus === BookingPolicy::STATUS_OPTION) {
                return $this->rollbackResult($command, BookingStatusChangeCode::MailNotSupportedForTargetStatus, $previousStatus, $previousStatus);
            }

            $settings = $this->daySettings->lockDate($booking->visitDate);
            $validation = null;
            $capacity = null;
            $usedOverrides = [];
            if ($command->targetStatus === BookingPolicy::STATUS_CONFIRMED) {
                $rawValidation = $this->storedBookingValidator->validateForTargetStatus(
                    $booking,
                    $command->targetStatus,
                    $today ?? new DateTimeImmutable('today'),
                );
                $validation = new StoredBookingValidationResult(array_map(
                    fn (StoredBookingIssue $issue): StoredBookingIssue => $this->classifyIssue($issue),
                    $rawValidation->issues,
                ));
                $stats = $this->calendar->getBookingStatsForDate($booking->visitDate, $booking->id);
                $limits = $this->effectiveCapacity(
                    $booking->visitDate,
                    $settings->maxSchoolsOverride,
                    $settings->maxStudentsOverride,
                );
                $capacity = $this->capacityValidator->validate(
                    $totals = new DayCapacityTotals($stats['bookedSchools'], $stats['bookedStudents']),
                    $booking->studentCount,
                    $limits,
                );
                if (!$capacity->allowed) {
                    if ($capacity->code === CapacityValidationCode::InvalidStudentCount) {
                        $capacityCode = CapacityValidationCode::InvalidStudentCount->value;
                        $capacityMetadata = ['bookingStudents' => $booking->studentCount];
                    } elseif ($capacity->code === CapacityValidationCode::SchoolLimitExceeded) {
                        $capacityCode = $capacity->code->value;
                        $capacityMetadata = ['confirmedSchools' => $totals->confirmedSchools, 'bookingAddsSchool' => true, 'projectedSchools' => $capacity->projectedSchools, 'maximumSchools' => $limits->effectiveMaxSchools];
                    } else {
                        $capacityCode = $capacity->code->value;
                        $capacityMetadata = ['confirmedStudents' => $totals->confirmedStudents, 'bookingStudents' => $booking->studentCount, 'projectedStudents' => $capacity->projectedStudents, 'maximumStudents' => $limits->effectiveMaxStudents];
                    }
                    $validation = new StoredBookingValidationResult([...$validation->issues, $this->classifyIssue(new StoredBookingIssue($capacityCode, StoredBookingIssueCategory::Capacity, 'bezoekdatum', metadata: $capacityMetadata))]);
                }

                $hardIssues = array_values(array_filter($validation->issues, static fn (StoredBookingIssue $issue): bool => !$issue->overridable));
                if ($hardIssues !== []) {
                    $hardValidation = new StoredBookingValidationResult($hardIssues);
                    $hardCode = $hardValidation->hasCode(CapacityValidationCode::InvalidStudentCount->value)
                        ? BookingStatusChangeCode::InvalidStudentCount
                        : $this->validationCode($hardValidation);
                    return $this->rollbackResult($command, $hardCode, $previousStatus, $previousStatus, $validation, $capacity);
                }

                $overrideResult = $this->validateOverrides($command, $booking, $validation->issues, $totals, $limits);
                if ($overrideResult instanceof BookingStatusChangeCode) {
                    return $this->rollbackResult($command, $overrideResult, $previousStatus, $previousStatus, $validation, $capacity);
                }
                $usedOverrides = $overrideResult;
            }

            $quote = null;
            if ($command->mailMode === BookingStatusMailMode::Send && $command->targetStatus === BookingPolicy::STATUS_CONFIRMED) {
                $quote = $this->pricingInputFactory->calculate(
                    $this->pricingInputFactory->fromStoredBooking($booking),
                    $this->priceCalculator,
                );
            }

            if (!$this->statuses->guardedUpdate($booking->id, $command->expectedCurrentStatus, $command->targetStatus)) {
                return $this->rollbackResult($command, BookingStatusChangeCode::StatusConflict, $previousStatus, $previousStatus, $validation, $capacity);
            }

            if ($command->mailMode === BookingStatusMailMode::Send) {
                try {
                    if ($command->targetStatus === BookingPolicy::STATUS_CONFIRMED) {
                        $this->mailSender->sendConfirmation($booking, $quote ?? throw new RuntimeException('Prijsquote ontbreekt.'));
                    } else {
                        $this->mailSender->sendRejection($booking);
                    }
                } catch (Throwable $exception) {
                    error_log(sprintf(
                        'Booking status mail failure: exception=%s booking=%d target=%s mode=%s',
                        $exception::class, $command->bookingId, $command->targetStatus, $command->mailMode->value,
                    ));
                    return $this->rollbackResult($command, BookingStatusChangeCode::MailSendFailed, $previousStatus, $previousStatus);
                }
            }

            $historyId = $this->history->insert(
                $booking->id, $previousStatus, $command->targetStatus, $command->actingAdminId,
                $command->mailMode->value, $command->mailMode === BookingStatusMailMode::Send,
            );
            $overrideAudit = $this->overrideAudit ?? new BookingRuleOverrideSqlRepository($this->pdo);
            foreach ($usedOverrides as $override) {
                $overrideAudit->insert($booking->id, $historyId, $override, $command->actingAdminId);
            }
            if (!$this->pdo->commit()) {
                throw new RuntimeException('Statusmutatietransactie kon niet worden vastgelegd.');
            }

            return new BookingStatusChangeResult(
                BookingStatusChangeCode::Success,
                true,
                $command->bookingId,
                $previousStatus,
                $command->targetStatus,
                $validation?->issues ?? [],
                $capacity,
                $command->mailMode,
                $command->mailMode === BookingStatusMailMode::Send,
                $usedOverrides,
            );
        } catch (Throwable $exception) {
            error_log(sprintf(
                'Booking status change technical failure: exception=%s booking=%d target=%s mode=%s',
                $exception::class, $command->bookingId, $command->targetStatus, $command->mailMode->value,
            ));
            $this->rollbackIfActive();

            return new BookingStatusChangeResult(
                BookingStatusChangeCode::DatabaseError,
                false,
                $command->bookingId,
                $previousStatus,
                $previousStatus,
                mailMode: $command->mailMode,
            );
        }
    }

    private function classifyIssue(StoredBookingIssue $issue): StoredBookingIssue
    {
        $definition = ($this->overridePolicy ?? new BookingRuleOverridePolicy())->definition($issue->code);
        $overridable = $definition->overridable;
        $metadata = $issue->metadata;
        if ($issue->code === 'INCOMPLETE_CJP_DETAILS') $metadata = ['field' => 'cjpPasnummer', 'cjpSelected' => true];

        return new StoredBookingIssue(
            $issue->code, $issue->category, $issue->field,
            $overridable ? $definition->severity : \GeoFort\Booking\Rules\BookingRuleSeverity::Error,
            $overridable, $definition->title, $definition->description, $metadata,
        );
    }

    /** @param list<StoredBookingIssue> $issues @return list<UsedBookingRuleOverride>|BookingStatusChangeCode */
    private function validateOverrides(BookingStatusChangeCommand $command, \GeoFort\Booking\Stored\StoredBooking $booking, array $issues, DayCapacityTotals $totals, EffectiveDayCapacity $limits): array|BookingStatusChangeCode
    {
        $actual = [];
        foreach ($issues as $issue) if ($issue->overridable) $actual[$issue->code] = $issue;
        $requested = [];
        foreach ($command->requestedOverrides as $override) {
            if (isset($requested[$override->ruleCode])) return BookingStatusChangeCode::InvalidOverrideRequest;
            $definition = ($this->overridePolicy ?? new BookingRuleOverridePolicy())->definition($override->ruleCode);
            if (!$definition->overridable) return BookingStatusChangeCode::OverrideNotAllowed;
            $requested[$override->ruleCode] = $override;
        }
        if ($actual === [] && $requested !== []) return BookingStatusChangeCode::InvalidOverrideRequest;
        foreach ($requested as $code => $_) if (!isset($actual[$code])) return BookingStatusChangeCode::InvalidOverrideRequest;
        foreach ($actual as $code => $_) if (!isset($requested[$code])) return BookingStatusChangeCode::OverrideRequired;

        $used = [];
        foreach ($actual as $code => $issue) {
            $definition = ($this->overridePolicy ?? new BookingRuleOverridePolicy())->definition($code);
            if (!(($this->overrideAuthorization ?? new AuthenticatedAdminBookingOverrideAuthorizationService())->isAllowed($command->actingAdminId, $definition))) {
                return BookingStatusChangeCode::OverridePermissionDenied;
            }
            $used[] = new UsedBookingRuleOverride(
                $code,
                $requested[$code]->reason,
                ($this->fingerprint ?? new BookingRuleContextFingerprint())->create($code, $booking, $command->targetStatus, $issue->metadata, $totals, $limits),
                $issue->metadata,
            );
        }
        return $used;
    }

    private function transitionCode(BookingTransitionCode $code): BookingStatusChangeCode
    {
        return match ($code) {
            BookingTransitionCode::NoStatusChange => BookingStatusChangeCode::NoStatusChange,
            BookingTransitionCode::InvalidCurrentStatus => BookingStatusChangeCode::InvalidCurrentStatus,
            BookingTransitionCode::InvalidTargetStatus => BookingStatusChangeCode::InvalidTargetStatus,
            BookingTransitionCode::Allowed => throw new RuntimeException('Toegestane transitie heeft geen foutcode.'),
        };
    }

    private function validationCode(StoredBookingValidationResult $validation): BookingStatusChangeCode
    {
        if ($validation->hasCode('HISTORICAL_VISIT_DATE')) return BookingStatusChangeCode::HistoricalDate;

        return BookingStatusChangeCode::InvalidStoredBooking;
    }

    private function capacityCode(CapacityValidationResult $capacity): BookingStatusChangeCode
    {
        return match ($capacity->code) {
            CapacityValidationCode::SchoolLimitExceeded => BookingStatusChangeCode::SchoolLimitExceeded,
            CapacityValidationCode::StudentLimitExceeded => BookingStatusChangeCode::StudentLimitExceeded,
            CapacityValidationCode::InvalidStudentCount => BookingStatusChangeCode::InvalidStudentCount,
            CapacityValidationCode::Available => throw new RuntimeException('Beschikbare capaciteit heeft geen foutcode.'),
        };
    }

    private function effectiveCapacity(string $visitDate, ?int $maxSchoolsOverride, ?int $maxStudentsOverride): EffectiveDayCapacity
    {
        $base = $this->capacityLimitProvider->forDate($visitDate);

        return new EffectiveDayCapacity(
            $base->standardMaxSchools,
            $base->standardMaxStudents,
            $maxSchoolsOverride,
            $maxStudentsOverride,
            $maxSchoolsOverride ?? $base->effectiveMaxSchools,
            $maxStudentsOverride ?? $base->effectiveMaxStudents,
        );
    }

    private function rollbackResult(
        BookingStatusChangeCommand $command,
        BookingStatusChangeCode $code,
        ?string $previousStatus,
        ?string $currentStatus,
        ?StoredBookingValidationResult $validation = null,
        ?CapacityValidationResult $capacity = null,
    ): BookingStatusChangeResult {
        $this->rollbackIfActive();

        return new BookingStatusChangeResult(
            $code,
            false,
            $command->bookingId,
            $previousStatus,
            $currentStatus,
            $validation?->issues ?? [],
            $capacity,
            $command->mailMode,
        );
    }

    private function rollbackIfActive(): void
    {
        if (!$this->pdo->inTransaction()) return;

        try {
            $this->pdo->rollBack();
        } catch (Throwable) {
            // The public result deliberately contains no database details.
        }
    }
}

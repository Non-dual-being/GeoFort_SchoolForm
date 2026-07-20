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
use GeoFort\Booking\Validation\StoredBookingValidationResult;
use GeoFort\Booking\Validation\StoredBookingValidator;
use GeoFort\Services\Sql\BookingCalendarSqlService;
use GeoFort\Services\Sql\BookingDaySettingsSqlRepository;
use GeoFort\Services\Sql\BookingStatusHistorySqlRepository;
use GeoFort\Services\Sql\BookingStatusSqlRepository;
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

            if ($command->mailMode === BookingStatusMailMode::Send && $command->targetStatus === BookingPolicy::STATUS_OPTION) {
                return $this->rollbackResult($command, BookingStatusChangeCode::MailNotSupportedForTargetStatus, $previousStatus, $previousStatus);
            }

            $settings = $this->daySettings->lockDate($booking->visitDate);
            $validation = null;
            $capacity = null;
            if ($command->targetStatus === BookingPolicy::STATUS_CONFIRMED) {
                $validation = $this->storedBookingValidator->validateForTargetStatus(
                    $booking,
                    $command->targetStatus,
                    $today ?? new DateTimeImmutable('today'),
                );
                if (!$validation->isValid()) {
                    return $this->rollbackResult(
                        $command,
                        $this->validationCode($validation),
                        $previousStatus,
                        $previousStatus,
                        $validation,
                    );
                }

                $stats = $this->calendar->getBookingStatsForDate($booking->visitDate, $booking->id);
                $limits = $this->effectiveCapacity(
                    $booking->visitDate,
                    $settings->maxSchoolsOverride,
                    $settings->maxStudentsOverride,
                );
                $capacity = $this->capacityValidator->validate(
                    new DayCapacityTotals($stats['bookedSchools'], $stats['bookedStudents']),
                    $booking->studentCount,
                    $limits,
                );
                if (!$capacity->allowed) {
                    return $this->rollbackResult(
                        $command,
                        $this->capacityCode($capacity),
                        $previousStatus,
                        $previousStatus,
                        $validation,
                        $capacity,
                    );
                }
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

            $this->history->insert(
                $booking->id, $previousStatus, $command->targetStatus, $command->actingAdminId,
                $command->mailMode->value, $command->mailMode === BookingStatusMailMode::Send,
            );
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
        if ($validation->hasCode('DISABLED_VISIT_DATE')) return BookingStatusChangeCode::DisabledDate;
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

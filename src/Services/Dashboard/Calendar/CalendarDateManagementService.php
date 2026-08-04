<?php
declare(strict_types=1);

namespace GeoFort\Services\Dashboard\Calendar;

use DateInterval;
use DatePeriod;
use DateTimeImmutable;
use GeoFort\Dashboard\Calendar\CalendarDateManagementCommand;
use GeoFort\Dashboard\Calendar\CalendarDateManagementIssue;
use GeoFort\Dashboard\Calendar\CalendarDateManagementPolicy;
use GeoFort\Dashboard\Calendar\CalendarDateManagementResult;
use GeoFort\Services\Sql\BookingDaySettingsSqlRepository;
use GeoFort\Services\Sql\CalendarDateManagementSqlRepository;
use PDO;
use RuntimeException;
use Throwable;

final readonly class CalendarDateManagementService
{
    public function __construct(
        private PDO $pdo,
        private BookingDaySettingsSqlRepository $daySettings,
        private CalendarDateManagementSqlRepository $dates,
        private CalendarDateManagementPreviewService $previews,
    ) {}

    public function change(CalendarDateManagementCommand $command, ?DateTimeImmutable $today = null): CalendarDateManagementResult
    {
        $reason = $command->reason === null ? null : trim($command->reason);
        if ($command->isBlock()) {
            if (!in_array($command->disabledType, CalendarDateManagementPolicy::MANAGEABLE_TYPES, true)) {
                return $this->failure($command, 'INVALID_DISABLED_DATE_TYPE', 'disabledType', 'Ongeldig blokkadetype', 'Kies Vakantie of Anders.');
            }
            if ($reason === null || mb_strlen($reason) < CalendarDateManagementPolicy::MIN_REASON_LENGTH) {
                return $this->failure($command, 'MISSING_BLOCK_REASON', 'reason', 'Reden is verplicht', 'Vul een reden van minimaal ' . CalendarDateManagementPolicy::MIN_REASON_LENGTH . ' tekens in.');
            }
            if (mb_strlen($reason) > CalendarDateManagementPolicy::MAX_REASON_LENGTH) {
                return $this->failure($command, 'BLOCK_REASON_TOO_LONG', 'reason', 'Reden is te lang', 'De reden mag maximaal ' . CalendarDateManagementPolicy::MAX_REASON_LENGTH . ' tekens bevatten.');
            }
        }
        if (!$command->confirmed) {
            return $this->failure($command, 'CONFIRMATION_REQUIRED', 'confirmed', 'Bevestiging is verplicht', 'Bevestig de gekozen wijziging voordat u doorgaat.');
        }
        $validation = $this->previews->preview($command->action, $command->startDate, $command->endDate, $command->disabledType, $today);
        if (!$validation->success) return $validation;

        try {
            if (!$this->pdo->beginTransaction()) throw new RuntimeException('Transactie kon niet worden gestart.');
            $lockDates = [];
            $endExclusive = (new DateTimeImmutable($command->endDate))->add(new DateInterval('P1D'));
            foreach (new DatePeriod(new DateTimeImmutable($command->startDate), new DateInterval('P1D'), $endExclusive) as $date) {
                if ((int) $date->format('N') < 6) $lockDates[] = $date->format('Y-m-d');
            }
            $this->daySettings->lockDates($lockDates);
            $current = $this->previews->preview($command->action, $command->startDate, $command->endDate, $command->disabledType, $today, true);
            if (!$current->success || $current->preview === null) return $this->rollback($current);
            $preview = $current->preview;

            if ($command->expectedFingerprint === null || !hash_equals($preview->fingerprint, $command->expectedFingerprint)) {
                return $this->rollback(new CalendarDateManagementResult(
                    'CALENDAR_DATE_CONFLICT',
                    false,
                    $command->startDate,
                    $command->endDate,
                    preview: $preview,
                    issues: [new CalendarDateManagementIssue(
                        'CALENDAR_DATE_CONFLICT',
                        'previewFingerprint',
                        'Kalenderselectie is gewijzigd',
                        'De actuele periode wijkt af van de bevestigde preview. Controleer de vernieuwde preview en bevestig opnieuw.',
                    )],
                ));
            }

            if ($command->isBlock() && $preview->bookingCount > 0) {
                if ($command->isPeriod()) {
                    return $this->rollback(new CalendarDateManagementResult(
                        'ACTIVE_BOOKINGS_IN_PERIOD',
                        false,
                        $command->startDate,
                        $command->endDate,
                        preview: $preview,
                        issues: [new CalendarDateManagementIssue(
                            'ACTIVE_BOOKINGS_IN_PERIOD',
                            'dateRange',
                            'Periode bevat actieve boekingen',
                            'Een periode kan alleen worden geblokkeerd wanneer geen enkele relevante datum actieve boekingen bevat.',
                            ['activeBookingDates' => $preview->categories['activeBookingDates']],
                        )],
                    ));
                }
                if ($command->expectedActiveBookingsFingerprint === null
                    || !hash_equals($preview->activeBookingsFingerprint, $command->expectedActiveBookingsFingerprint)) {
                    return $this->rollback(new CalendarDateManagementResult(
                        'EXISTING_BOOKINGS_CONFIRMATION_REQUIRED',
                        false,
                        $command->startDate,
                        $command->endDate,
                        preview: $preview,
                        issues: [new CalendarDateManagementIssue(
                            'EXISTING_BOOKINGS_CONFIRMATION_REQUIRED',
                            'existingBookingsAccepted',
                            'Actieve boekingen zijn gewijzigd',
                            'De actieve boekingen zijn sinds de preview gewijzigd. Controleer de actuele boekingen en bevestig opnieuw.',
                        )],
                    ));
                }
                if (!$command->existingBookingsAccepted) {
                    return $this->rollback(new CalendarDateManagementResult(
                        'EXISTING_BOOKINGS_CONFIRMATION_REQUIRED',
                        false,
                        $command->startDate,
                        $command->endDate,
                        preview: $preview,
                        issues: [new CalendarDateManagementIssue(
                            'EXISTING_BOOKINGS_CONFIRMATION_REQUIRED',
                            'existingBookingsAccepted',
                            'Bestaande boekingen moeten worden bevestigd',
                            "Op deze datum staan al {$preview->bookingCount} actieve boekingen met in totaal {$preview->studentCount} leerlingen. Deze boekingen blijven bestaan. Alleen nieuwe boekingen worden geblokkeerd.",
                        )],
                    ));
                }
            }

            /** @var list<string> $affected */
            $affected = $preview->categories['affectedDates'];
            if ($affected === []) {
                if ($command->action === 'block_single' && $preview->categories['existingPlannerDates'] !== []) {
                    $stored = $preview->categories['existingPlannerDates'][0];
                    if ($stored['type'] !== $command->disabledType || $stored['reason'] !== $reason) {
                        return $this->rollback(new CalendarDateManagementResult(
                            'CALENDAR_DATE_CONFLICT',
                            false,
                            $command->startDate,
                            $command->endDate,
                            preview: $preview,
                            issues: [new CalendarDateManagementIssue(
                                'CALENDAR_DATE_CONFLICT',
                                'reason',
                                'Datum is al handmatig geblokkeerd',
                                'Geef de bestaande blokkade eerst vrij voordat u een ander type of een andere reden vastlegt.',
                            )],
                        ));
                    }
                }
                if ($command->action === 'block_single' && $preview->categories['otherBlockedDates'] !== []) {
                    return $this->rollback(new CalendarDateManagementResult(
                        'CALENDAR_DATE_CONFLICT',
                        false,
                        $command->startDate,
                        $command->endDate,
                        preview: $preview,
                        issues: [new CalendarDateManagementIssue(
                            'CALENDAR_DATE_CONFLICT',
                            'dateRange',
                            'Datum heeft een andere blokkade',
                            'Een bestaande niet-handmatige blokkade kan niet worden overschreven.',
                        )],
                    ));
                }
                $onlyWeekends = count($preview->categories['weekendDates']) === $preview->calendarDayCount;
                $code = $onlyWeekends ? 'NO_ELIGIBLE_DATES' : 'NO_CHANGE';
                return $this->rollback(new CalendarDateManagementResult($code, true, $command->startDate, $command->endDate, preview: $preview));
            }

            $children = [];
            if ($command->isBlock()) {
                foreach ($affected as $date) {
                    $this->dates->insertPlannerBlock($date, (string) $command->disabledType, (string) $reason);
                    $children[] = [
                        'date' => $date,
                        'before' => false,
                        'after' => $command->disabledType === CalendarDateManagementPolicy::MANUAL_BLOCK_TYPE,
                        'typeBefore' => null, 'typeAfter' => $command->disabledType,
                        'reasonBefore' => null, 'reasonAfter' => $reason,
                    ];
                }
            } else {
                $plannerByDate = [];
                foreach ($preview->categories['existingPlannerDates'] as $planner) $plannerByDate[$planner['date']] = $planner;
                foreach ($affected as $date) {
                    $stored = $plannerByDate[$date];
                    if (!$this->dates->releaseBlock($date, $stored['type'], $stored['source'], $command->actingAdminId)) throw new RuntimeException('Kalenderblokkade kon niet worden verwijderd.');
                    $children[] = [
                        'date' => $date,
                        'before' => $stored['type'] === CalendarDateManagementPolicy::MANUAL_BLOCK_TYPE,
                        'after' => false,
                        'typeBefore' => $stored['type'], 'typeAfter' => null,
                        'reasonBefore' => $stored['reason'], 'reasonAfter' => null,
                    ];
                }
            }
            $this->dates->insertAudit(
                $command->isBlock() ? 'calendar_date_blocked' : 'calendar_date_released',
                $command->isPeriod() ? 'period' : 'single',
                $command->startDate,
                $command->endDate,
                $command->isBlock() ? $reason : null,
                $preview->toArray(),
                $children,
                $command->actingAdminId,
            );
            if (!$this->pdo->commit()) throw new RuntimeException('Transactie kon niet worden vastgelegd.');
            return new CalendarDateManagementResult('SUCCESS', true, $command->startDate, $command->endDate, count($affected), $preview);
        } catch (Throwable $exception) {
            error_log('[CalendarDateManagementService] Mutatie mislukt: ' . $exception::class);
            $this->rollbackIfActive();
            return $this->failure($command, 'DATABASE_ERROR', 'dateRange', 'Wijziging mislukt', 'De kalenderwijziging kon niet worden opgeslagen. Probeer het later opnieuw.');
        }
    }

    private function failure(CalendarDateManagementCommand $command, string $code, string $field, string $title, string $description): CalendarDateManagementResult
    {
        return new CalendarDateManagementResult($code, false, $command->startDate, $command->endDate, issues: [
            new CalendarDateManagementIssue($code, $field, $title, $description),
        ]);
    }

    private function rollback(CalendarDateManagementResult $result): CalendarDateManagementResult
    {
        $this->rollbackIfActive();
        return $result;
    }

    private function rollbackIfActive(): void
    {
        if ($this->pdo->inTransaction()) $this->pdo->rollBack();
    }
}

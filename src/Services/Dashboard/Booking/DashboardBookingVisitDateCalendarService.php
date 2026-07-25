<?php
declare(strict_types=1);

namespace GeoFort\Services\Dashboard\Booking;

use DateInterval;
use DatePeriod;
use DateTimeImmutable;
use GeoFort\Booking\BookingPolicy;
use GeoFort\Booking\Capacity\{BookingCapacityValidator,DayCapacityTotals,EffectiveDayCapacity,PolicyCapacityLimitProvider};
use GeoFort\Booking\Rules\BookingRuleSeverity;
use GeoFort\Booking\Validation\{BookingValidationContext,BookingValidationCoordinator,BookingValidationProfile,StoredBookingIssue,StoredBookingVisitDateValidator};
use GeoFort\Services\Sql\{BookingCalendarSqlService,BookingDaySettingsSqlRepository,DisabledDatesSqlService,StoredBookingSqlRepository};

final readonly class DashboardBookingVisitDateCalendarService
{
    public function __construct(
        private StoredBookingSqlRepository $bookings,
        private BookingCalendarSqlService $calendar,
        private BookingDaySettingsSqlRepository $daySettings,
        private DisabledDatesSqlService $disabledDates,
        private StoredBookingVisitDateValidator $dateValidator,
        private BookingValidationCoordinator $coordinator = new BookingValidationCoordinator(),
        private PolicyCapacityLimitProvider $capacityLimits = new PolicyCapacityLimitProvider(),
        private BookingCapacityValidator $capacityValidator = new BookingCapacityValidator(),
    ) {}

    /** @return array<string, mixed>|null */
    public function get(int $bookingId, string $startDate, string $endDate, ?DateTimeImmutable $today = null): ?array
    {
        $booking = $this->bookings->findById($bookingId);
        if ($booking === null) return null;
        $today ??= new DateTimeImmutable('today');
        $confirmed = $booking->status === BookingPolicy::STATUS_CONFIRMED;
        $stats = $this->calendar->getBookingStatsByDateForRange($startDate, $endDate, $bookingId);
        $settings = $this->daySettings->findForRange($startDate, $endDate);
        $disabled = [];
        foreach ($this->disabledDates->getDisabledDatesDetailed($startDate, $endDate) as $row) {
            $disabled[(string) $row['datum']] = $row;
        }

        $days = [];
        $start = new DateTimeImmutable($startDate);
        $endExclusive = (new DateTimeImmutable($endDate))->add(new DateInterval('P1D'));
        foreach (new DatePeriod($start, new DateInterval('P1D'), $endExclusive) as $date) {
            $ymd = $date->format('Y-m-d');
            $proposed = $booking->withVisitDate($ymd);
            $base = $this->dateValidator->validate($proposed, false, $today);
            $issues = $base->issues;
            $dateIsPast = $date < $today->setTime(0, 0);
            if ($confirmed && $dateIsPast) {
                $issues[] = new StoredBookingIssue('HISTORICAL_VISIT_DATE', \GeoFort\Booking\Validation\StoredBookingIssueCategory::HistoricalDate, 'bezoekdatum');
            }
            if ($confirmed && isset($disabled[$ymd])) {
                $issues[] = new StoredBookingIssue('DISABLED_VISIT_DATE', \GeoFort\Booking\Validation\StoredBookingIssueCategory::DisabledDate, 'bezoekdatum');
            }

            $dayStats = $stats[$ymd] ?? ['bookedSchools' => 0, 'bookedStudents' => 0, 'programStudents' => []];
            $standard = $this->capacityLimits->forDate($ymd);
            $daySetting = $settings[$ymd] ?? null;
            $limits = new EffectiveDayCapacity(
                $standard->standardMaxSchools,
                $standard->standardMaxStudents,
                $daySetting?->maxSchoolsOverride,
                $daySetting?->maxStudentsOverride,
                $daySetting?->maxSchoolsOverride ?? $standard->effectiveMaxSchools,
                $daySetting?->maxStudentsOverride ?? $standard->effectiveMaxStudents,
            );
            if ($confirmed) {
                $capacity = $this->capacityValidator->validate(
                    new DayCapacityTotals($dayStats['bookedSchools'], $dayStats['bookedStudents']),
                    $booking->studentCount,
                    $limits,
                );
                if (!$capacity->allowed) {
                    $issues[] = new StoredBookingIssue(
                        $capacity->code->value,
                        \GeoFort\Booking\Validation\StoredBookingIssueCategory::Capacity,
                        $capacity->code->value === 'SCHOOL_LIMIT_EXCEEDED' ? 'bezoekdatum' : 'aantalLeerlingen',
                    );
                }
            }
            $profile = $confirmed ? BookingValidationProfile::ChangeVisitDateConfirmed : BookingValidationProfile::ChangeVisitDateDraft;
            $validation = $this->coordinator->validate($profile, $proposed, new BookingValidationContext($issues));
            $reasons = array_map(static fn(StoredBookingIssue $issue): array => [
                'code' => $issue->code,
                'title' => $issue->title,
                'description' => $issue->description,
                'severity' => $issue->severity->value,
                'overridable' => $issue->overridable,
            ], $validation->issues);
            if (!$confirmed && $dateIsPast) {
                $reasons[] = ['code' => 'HISTORICAL_VISIT_DATE', 'title' => 'Datum ligt in het verleden', 'description' => 'Deze draftstatus kan volgens de bestaande opslagflow nog naar deze datum worden verplaatst.', 'severity' => BookingRuleSeverity::Warning->value, 'overridable' => false];
            }
            if (!$confirmed && isset($disabled[$ymd])) {
                $reasons[] = ['code' => 'DISABLED_VISIT_DATE', 'title' => 'Deze bezoekdatum is geblokkeerd', 'description' => 'Voor deze draftstatus is dit informatief; de bestaande opslagflow laat de datum toe.', 'severity' => BookingRuleSeverity::Warning->value, 'overridable' => false];
            }
            $hasHard = count(array_filter($validation->issues, static fn(StoredBookingIssue $issue): bool => !$issue->overridable)) > 0;
            $hasOverride = count(array_filter($validation->issues, static fn(StoredBookingIssue $issue): bool => $issue->overridable)) > 0;
            $state = $hasHard ? ($dateIsPast ? 'past' : 'blocked') : ($hasOverride ? 'override_required' : ($reasons !== [] ? 'warning' : 'available'));
            $programLimit = BookingPolicy::isAllowedProgram($booking->program) ? BookingPolicy::getMaxStudentsOfProgram($booking->program) : null;
            $days[] = [
                'date' => $ymd,
                'state' => $state,
                'selectable' => !$hasHard,
                'isCurrentVisitDate' => $ymd === $booking->visitDate,
                'reasons' => $reasons,
                'capacity' => [
                    'confirmedSchools' => $dayStats['bookedSchools'],
                    'schoolLimit' => $limits->effectiveMaxSchools,
                    'confirmedStudents' => $dayStats['bookedStudents'],
                    'studentLimit' => $limits->effectiveMaxStudents,
                    'confirmedProgramStudents' => $dayStats['programStudents'][$booking->program] ?? 0,
                    'programStudentLimit' => $programLimit,
                ],
            ];
        }
        return [
            'bookingId' => $booking->id,
            'status' => $booking->status,
            'program' => $booking->program,
            'programLabel' => BookingPolicy::isAllowedProgram($booking->program) ? BookingPolicy::getProgramLabel($booking->program) : $booking->program,
            'currentVisitDate' => $booking->visitDate,
            'days' => $days,
        ];
    }
}

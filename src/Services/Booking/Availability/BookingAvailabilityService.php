<?php

declare(strict_types=1);

namespace GeoFort\Services\Booking\Availability;

use DateTimeImmutable;
use DateTimeZone;
use GeoFort\Booking\BookingPolicy;
use GeoFort\Services\Sql\BookingCalendarSqlService;
use GeoFort\Services\Sql\DisabledDatesSqlService;
use GeoFort\Validation\FieldValidationException;

final class BookingAvailabilityService
{
    private DateTimeZone $timezone;

    public function __construct(
        private readonly BookingCalendarSqlService $calendarSql,
        private readonly DisabledDatesSqlService $disabledDatesSql,
    ) {
        $this->timezone = new DateTimeZone('Europe/Amsterdam');
    }

    public function assertDateIsValid(string $date): DateTimeImmutable
    {
        $visitDate = $this->parseDate($date);
        $today = new DateTimeImmutable('today', $this->timezone);

        $maxYearDate = $today->modify(
            '+' . BookingPolicy::BOOKABLE_YEARS_AHEAD . ' years',
        );

        $maxDate = $maxYearDate->setDate(
            (int) $maxYearDate->format('Y'),
            12,
            31,
        );

        if ($visitDate < $today) {
            throw new FieldValidationException(
                'bezoekdatum',
                'Kies geen datum in het verleden.',
            );
        }

        if ($visitDate > $maxDate) {
            throw new FieldValidationException(
                'bezoekdatum',
                'Deze datum ligt te ver in de toekomst.',
            );
        }

        if ($this->isWeekend($visitDate)) {
            throw new FieldValidationException(
                'bezoekdatum',
                'In het weekend zijn geen onderwijsbezoeken mogelijk.',
            );
        }

        if ($this->disabledDatesSql->isDateDisabled($date)) {
            throw new FieldValidationException(
                'bezoekdatum',
                'De gekozen datum is niet beschikbaar.',
            );
        }

        return $visitDate;
    }

    public function getAvailabilityForDate(DateTimeImmutable $date): array
    {
        $dateYmd = $date->format('Y-m-d');

        $stats = $this->calendarSql->getBookingStatsForDate($dateYmd);

        return $this->buildAvailabilityDetail(
            dateYmd: $dateYmd,
            bookedSchools: $stats['bookedSchools'],
            bookedStudents: $stats['bookedStudents'],
        );
    }

    public function getAvailabilityDetailsForRange(
        DateTimeImmutable $minDate,
        DateTimeImmutable $maxDate,
    ): array {
        $statsByDate = $this->calendarSql->getBookingStatsByDateForRange(
            $minDate->format('Y-m-d'),
            $maxDate->format('Y-m-d'),
        );

        $details = [];

        foreach ($statsByDate as $dateYmd => $stats) {
            $details[] = $this->buildAvailabilityDetail(
                dateYmd: $dateYmd,
                bookedSchools: $stats['bookedSchools'],
                bookedStudents: $stats['bookedStudents'],
            );
        }

        return $details;
    }

    public function assertCapacityAvailable(
        DateTimeImmutable $visitDate,
        int $requestedStudents,
        string $program,
    ): void {
        $availability = $this->getAvailabilityForDate($visitDate);

        if ($availability['remainingSchoolSlots'] <= 0) {
            throw new FieldValidationException(
                'bezoekdatum',
                'Deze datum is volgeboekt. Er staan al maximaal twee scholen ingepland.',
            );
        }

        if (!BookingPolicy::isAllowedProgram($program)) {
            throw new FieldValidationException(
                'programma',
                'Kies een geldig programma.',
            );
        }

        $programMaxStudents = BookingPolicy::getMaxStudentsOfProgram($program);

        if ($requestedStudents > $programMaxStudents) {
            throw new FieldValidationException(
                'aantalLeerlingen',
                "Voor dit programma kunt u maximaal {$programMaxStudents} leerlingen aanmelden.",
            );
        }

        if ($requestedStudents > $availability['availableStudents']) {
            throw new FieldValidationException(
                'aantalLeerlingen',
                "Op deze datum zijn nog maximaal {$availability['availableStudents']} plaatsen beschikbaar.",
            );
        }
    }

    public function getCapacityForFrontend(): array
    {
        return [
            'maxStudentsTotal' => BookingPolicy::MAX_STUDENTS_TOTAL_PER_DAY,
            'maxSchoolsPerDay' => BookingPolicy::MAX_SCHOOLS_PER_DAY,
        ];
    }

    private function buildAvailabilityDetail(
        string $dateYmd,
        int $bookedSchools,
        int $bookedStudents,
    ): array {
        $bookedSchools = max(0, $bookedSchools);
        $bookedStudents = max(0, $bookedStudents);

        $remainingSchoolSlots = max(
            0,
            BookingPolicy::MAX_SCHOOLS_PER_DAY - $bookedSchools,
        );

        $availableStudents = max(
            0,
            BookingPolicy::MAX_STUDENTS_TOTAL_PER_DAY - $bookedStudents,
        );

        $status = 'available';

        if ($remainingSchoolSlots <= 0 || $availableStudents <= 0) {
            $status = 'fully_booked';
        } elseif ($bookedSchools > 0 || $bookedStudents > 0) {
            $status = 'limited';
        }

        return [
            'datum' => $dateYmd,

            'bookedSchools' => $bookedSchools,
            'bookedStudents' => $bookedStudents,

            'remainingSchoolSlots' => $remainingSchoolSlots,
            'availableStudents' => $availableStudents,

            'maxSchoolsPerDay' => BookingPolicy::MAX_SCHOOLS_PER_DAY,
            'maxStudentsTotal' => BookingPolicy::MAX_STUDENTS_TOTAL_PER_DAY,

            'status' => $status,
        ];
    }

    private function parseDate(string $date): DateTimeImmutable
    {
        $dt = DateTimeImmutable::createFromFormat(
            '!Y-m-d',
            $date,
            $this->timezone,
        );

        if (!$dt || $dt->format('Y-m-d') !== $date) {
            throw new FieldValidationException(
                'bezoekdatum',
                'Ongeldige bezoekdatum.',
            );
        }

        return $dt;
    }

    private function isWeekend(DateTimeImmutable $date): bool
    {
        return in_array((int) $date->format('N'), [6, 7], true);
    }
}
<?php 
declare(strict_types=1);
namespace GeoFort\Services\Booking;

use DateTimeImmutable;
use DateTimeZone;
use GeoFort\Booking\BookingPolicy;
use GeoFort\Services\Sql\BookingCalenderSqlService;
use GeoFort\Services\Sql\DisabledDatesSqlService;
use GeoFort\Validation\FieldValidationException;

final class BookingAvailabilityService
{
    private DateTimeZone $timezone;
    
    public function __construct(
       // private readonly BookingCalendarSqlService $calendarSql,
        private readonly DisabledDatesSqlService $disabledDatesSql
    ) {
        $this->timezone = new DateTimeZone('Europe/Amsterdam');
    }

    public function assertDateIsValid(string $date): DateTimeImmutable {
        $visitDate = $this->parseDate($date);
        $today = new DateTimeImmutable('today', $this->timezone);
        $maxDate = $today
            ->modify('+' . BookingPolicy::BOOKABLE_YEARS_AHEAD . ' years')
            ->setDate(
                (int) $today->modify('+' . BookingPolicy::BOOKABLE_YEARS_AHEAD . ' years')->format('Y'),
                12,
                31
            );
        if ($visitDate < $today) {
            throw new FieldValidationException(
                'bezoekdatum',
                'Kies geen datum in het verleden.'
            );
        }

        if ($visitDate > $maxDate) {
            throw new FieldValidationException(
                'bezoekdatum',
                'Deze datum ligt te ver in de toekomst.'
            );
        }

        if ($this->isWeekend($visitDate)) {
            throw new FieldValidationException(
                'bezoekdatum',
                'In het weekend zijn geen onderwijsbezoeken mogelijk.'
            );
        }

        if ($this->disabledDatesSql->isDateDisabled($date)) {
            throw new FieldValidationException(
                'bezoekdatum',
                'De gekozen datum is niet beschikbaar.'
            );
        }

        return $visitDate;
        
    }

/*     public function fullAssertDateIsBookable(
        string $date,
        int $requestStudents,
        string $program
    ): void {
        $validVisitDate = $this->assertDateIsValid($date);

        $stats = $this->calendarSql->getBookingStatsForDate($date);

        $bookedSchools = $stats['bookedSchools'];
        $bookedStudents = $stats['bookedStudents'];

        if (!$this->checkValidProgram($program))  throw new FieldValidationException(
                'bezoekdatum',
                'Er is een ongeldig programma gekozen'
            );

        if ($program === 'Ochtend' && !$this->checkIsWednesday($date)) throw new FieldValidationException(
                'bezoekdatum',
                'Het ochtend programma is alleen beschikbaar op woensdag'
            );

        $maxStudentsOfProgram = BookingPolicy::getMaxStudentsOfProgram($program);

        if ($bookedSchools >= BookingPolicy::MAX_SCHOOLS_PER_DAY) {
            throw new FieldValidationException(
                'bezoekdatum',
                'Deze datum is volgeboekt.'
            );
        }

        if ($requestedStudents > BookingPolicy::getMaxStudentsOfProgram($program)) {
            $max = BookingPolicy::getMaxStudentsOfProgram($program);
             throw new FieldValidationException(
                'aantalLeerlingen',
                "Er kunnen max $max leerlingen aangemeld worden voor het gekozen programma: een boeking van $requestedStudents leerlingen is niet mogelijk"
            );

        }

        if (
            $bookedStudents + $requestedStudents >
            BookingPolicy::MAX_STUDENTS_TOTAL_PER_DAY
        ) {
            $remaining = $maxStudentsOfProgram  - $bookedStudents;

            throw new FieldValidationException(
                'aantalLeerlingen',
                "Er zijn op deze datum nog maximaal $remaining plaatsen beschikbaar."
            );
        }
        
    } */

    private function parseDate(string $date): DateTimeImmutable
    {
        $dt = DateTimeImmutable::createFromFormat(
            '!Y-m-d',
            $date,
            $this->timezone
        );

        if (!$dt || $dt->format('Y-m-d') !== $date) {
            throw new FieldValidationException(
                'bezoekdatum',
                'Ongeldige bezoekdatum.'
            );
        }

        return $dt;
    }

    public function getStringDate(DateTimeImmutable $date): string {
        return $date->format(Y-m-d);
    }

    private function isWeekend(DateTimeImmutable $date): bool
    {
        return in_array((int) $date->format('N'), [6, 7], true);
    }

    private function isWednesdayMorningProgram(
        DateTimeImmutable $date,
        string $program
    ): bool {
        return (int) $date->format('N') === 3
            && $program === BookingPolicy::WEDNESDAY_MORNING_PROGRAM;
    }

    private function checkValidProgram(
        string $program
    ): bool {
        return (bool) in_array($program, BookingPolicy::ALLOWED_PROGRAMS);
    }

    private function checkIsWednesday(
        DateTimeImmutable $date
    ): bool {
        return (bool) $date->format('N') === 3;
    }


}
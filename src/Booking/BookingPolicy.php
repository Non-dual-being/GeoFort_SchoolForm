<?php
declare(strict_types=1);
namespace GeoFort\Booking;

use InvalidArgumentException;

final class BookingPolicy
{
    public const ALLOWED_PROGRAMS = ['Regulier', 'Ochtend'];

    public const MAX_SCHOOLS_PER_DAY = 2;
    public const MAX_STUDENTS_TOTAL_PER_DAY = 160;
    public const MAX_STUDENTS_PER_DAY_REGULAR_PROGRAM = 160;
    public const MAX_STUDENTS_PER_DAY_MORNING_PROGRAM = 80;
    public const MAX_STUDENTS_PROGRAM_ARRAY = [
        'Regulier'  => self::MAX_STUDENTS_PER_DAY_REGULAR_PROGRAM,
        'ochtend'   => self::MAX_STUDENTS_PER_DAT_MORNING_PROGRAM 
    ];


    public const BOOKABLE_YEARS_AHEAD = 2;

   
    public const ACTIVE_STATUSES = [
        'In optie',
        'Definitief'
    ];

    public static function getMaxStudentsOfProgram (
        string $program
    ): int {
        if (!in_array($program, self::ALLOWED_PROGRAMS)) throw new InvalidArgumentException(
            "$program is not a valid program"
        );

        return self::MAX_STUDENTS_PROGRAM_ARRAY[$program];
    }


}
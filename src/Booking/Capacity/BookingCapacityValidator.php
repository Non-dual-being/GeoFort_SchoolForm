<?php
declare(strict_types=1);
namespace GeoFort\Booking\Capacity;

final class BookingCapacityValidator
{
    public function validate(DayCapacityTotals $current, ?int $targetStudents, EffectiveDayCapacity $limits): CapacityValidationResult
    {
        $schools = $current->confirmedSchools + 1;
        if ($targetStudents === null || $targetStudents <= 0) return new CapacityValidationResult(CapacityValidationCode::InvalidStudentCount, false, $schools, null);
        $students = $current->confirmedStudents + $targetStudents;
        if ($schools > $limits->effectiveMaxSchools) return new CapacityValidationResult(CapacityValidationCode::SchoolLimitExceeded, false, $schools, $students);
        if ($students > $limits->effectiveMaxStudents) return new CapacityValidationResult(CapacityValidationCode::StudentLimitExceeded, false, $schools, $students);
        return new CapacityValidationResult(CapacityValidationCode::Available, true, $schools, $students);
    }
}

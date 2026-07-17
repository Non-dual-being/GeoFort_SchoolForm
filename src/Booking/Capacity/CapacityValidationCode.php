<?php
declare(strict_types=1);
namespace GeoFort\Booking\Capacity;

enum CapacityValidationCode: string
{
    case Available = 'CAPACITY_AVAILABLE';
    case InvalidStudentCount = 'INVALID_STUDENT_COUNT';
    case SchoolLimitExceeded = 'SCHOOL_LIMIT_EXCEEDED';
    case StudentLimitExceeded = 'STUDENT_LIMIT_EXCEEDED';
}

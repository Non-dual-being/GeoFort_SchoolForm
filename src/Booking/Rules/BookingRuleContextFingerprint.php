<?php
declare(strict_types=1);
namespace GeoFort\Booking\Rules;

use GeoFort\Booking\Capacity\DayCapacityTotals;
use GeoFort\Booking\Capacity\EffectiveDayCapacity;
use GeoFort\Booking\Stored\StoredBooking;
use JsonException;

final class BookingRuleContextFingerprint
{
    /** @param array<string, bool|int|string|null> $metadata */
    public function create(string $ruleCode, StoredBooking $booking, string $targetStatus, array $metadata, ?DayCapacityTotals $totals, ?EffectiveDayCapacity $limits): string
    {
        $context = [
            'bookingId' => $booking->id,
            'program' => $booking->program,
            'ruleCode' => $ruleCode,
            'sector' => $booking->schoolSector,
            'studentCount' => $booking->studentCount,
            'supervisorCount' => $booking->supervisorCount,
            'targetStatus' => $targetStatus,
            'visitDate' => $booking->visitDate,
        ];
        if (in_array($ruleCode, ['SCHOOL_LIMIT_EXCEEDED', 'STUDENT_LIMIT_EXCEEDED'], true) && $totals !== null && $limits !== null) {
            $context['capacity'] = [
                'confirmedSchools' => $totals->confirmedSchools,
                'confirmedStudents' => $totals->confirmedStudents,
                'maximumSchools' => $limits->effectiveMaxSchools,
                'maximumStudents' => $limits->effectiveMaxStudents,
                'maxSchoolsOverride' => $limits->overrideMaxSchools,
                'maxStudentsOverride' => $limits->overrideMaxStudents,
            ];
        }
        $context['metadata'] = $metadata;
        $this->sort($context);
        try {
            return hash('sha256', json_encode($context, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        } catch (JsonException $exception) {
            throw new \RuntimeException('Overridecontext kon niet worden gecanonicaliseerd.', 0, $exception);
        }
    }

    /** @param array<string, bool|int|string|null> $metadata */
    public function createForAttendanceChange(string $ruleCode, StoredBooking $previous, StoredBooking $proposed, array $metadata, ?DayCapacityTotals $totals, ?EffectiveDayCapacity $limits): string
    {
        $context = [
            'bookingId'=>$previous->id, 'operation'=>'attendance_changed', 'program'=>$previous->program,
            'ruleCode'=>$ruleCode, 'sector'=>$previous->schoolSector, 'status'=>$previous->status,
            'visitDate'=>$previous->visitDate, 'previousStudentCount'=>$previous->studentCount,
            'proposedStudentCount'=>$proposed->studentCount, 'previousSupervisorCount'=>$previous->supervisorCount,
            'proposedSupervisorCount'=>$proposed->supervisorCount, 'metadata'=>$metadata,
        ];
        if (in_array($ruleCode, ['SCHOOL_LIMIT_EXCEEDED','STUDENT_LIMIT_EXCEEDED'], true) && $totals && $limits) {
            $context['capacity']=['confirmedSchoolsExcludingBooking'=>$totals->confirmedSchools,'confirmedStudentsExcludingBooking'=>$totals->confirmedStudents,'projectedSchools'=>$totals->confirmedSchools+1,'projectedStudents'=>$totals->confirmedStudents+$proposed->studentCount,'maximumSchools'=>$limits->effectiveMaxSchools,'maximumStudents'=>$limits->effectiveMaxStudents,'maxSchoolsOverride'=>$limits->overrideMaxSchools,'maxStudentsOverride'=>$limits->overrideMaxStudents];
        }
        $this->sort($context);
        try { return hash('sha256', json_encode($context, JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)); }
        catch (JsonException $exception) { throw new \RuntimeException('Overridecontext kon niet worden gecanonicaliseerd.', 0, $exception); }
    }

    /** @param array<mixed> $value */
    private function sort(array &$value): void
    {
        if (!array_is_list($value)) ksort($value, SORT_STRING);
        foreach ($value as &$item) if (is_array($item)) $this->sort($item);
    }
}

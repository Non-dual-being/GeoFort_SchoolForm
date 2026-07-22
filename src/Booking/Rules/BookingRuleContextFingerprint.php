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

    /** @param array<mixed> $value */
    private function sort(array &$value): void
    {
        if (!array_is_list($value)) ksort($value, SORT_STRING);
        foreach ($value as &$item) if (is_array($item)) $this->sort($item);
    }
}

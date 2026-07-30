<?php
declare(strict_types=1);

namespace GeoFort\Services\Dashboard\Booking\Export;

use DateTimeImmutable;
use GeoFort\Validation\FieldValidationException;

final class BookingExportCriteriaFactory
{
    /** @param array<string, mixed> $query */
    public function create(array $query, BookingExportDateBounds $bounds): BookingExportCriteria
    {
        $startDate = $this->date($query, 'startDate', 'begindatum');
        $endDate = $this->date($query, 'endDate', 'einddatum');

        if ($startDate > $endDate) {
            throw new FieldValidationException(
                'endDate',
                'De einddatum mag niet vóór de begindatum liggen.',
            );
        }

        if ($bounds->isEmpty()) {
            return new BookingExportCriteria($startDate, $endDate, null, null, $bounds);
        }

        $effectiveStart = max($startDate, (string) $bounds->minDate);
        $effectiveEnd = min($endDate, (string) $bounds->maxDate);
        if ($effectiveStart > $effectiveEnd) {
            $effectiveStart = null;
            $effectiveEnd = null;
        }

        return new BookingExportCriteria(
            $startDate,
            $endDate,
            $effectiveStart,
            $effectiveEnd,
            $bounds,
        );
    }

    /** @param array<string, mixed> $query */
    private function date(array $query, string $key, string $label): string
    {
        $value = $query[$key] ?? null;
        if (!is_string($value) || $value === '') {
            throw new FieldValidationException($key, "Kies een {$label}.");
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if (!$date || $date->format('Y-m-d') !== $value) {
            throw new FieldValidationException($key, "Kies een geldige {$label}.");
        }
        return $value;
    }
}

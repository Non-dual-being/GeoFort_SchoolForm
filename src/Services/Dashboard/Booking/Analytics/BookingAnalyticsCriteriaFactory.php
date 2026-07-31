<?php
declare(strict_types=1);

namespace GeoFort\Services\Dashboard\Booking\Analytics;

use DateTimeImmutable;
use GeoFort\Services\Dashboard\Booking\Export\BookingExportDateBounds;
use GeoFort\Booking\BookingProgramConfig;
use GeoFort\Validation\FieldValidationException;

final class BookingAnalyticsCriteriaFactory
{
    /** @param array<string, mixed> $query */
    public function create(array $query, BookingExportDateBounds $bounds): BookingAnalyticsCriteria
    {
        $start = $this->date($query, 'startDate', 'begindatum');
        $end = $this->date($query, 'endDate', 'einddatum');
        if ($start > $end) {
            throw new FieldValidationException('endDate', 'De einddatum mag niet vóór de begindatum liggen.');
        }
        if ($bounds->isEmpty()) {
            return new BookingAnalyticsCriteria($start, $end, null, null, $bounds, $this->sector($query), $this->population($query), $this->program($query));
        }
        $effectiveStart = max($start, (string) $bounds->minDate);
        $effectiveEnd = min($end, (string) $bounds->maxDate);
        if ($effectiveStart > $effectiveEnd) {
            $effectiveStart = $effectiveEnd = null;
        }
        return new BookingAnalyticsCriteria($start, $end, $effectiveStart, $effectiveEnd, $bounds, $this->sector($query), $this->population($query), $this->program($query));
    }

    /** @param array<string, mixed> $query */
    private function date(array $query, string $key, string $label): string
    {
        $value = $query[$key] ?? null;
        $date = is_string($value) ? DateTimeImmutable::createFromFormat('!Y-m-d', $value) : false;
        if (!$date || $date->format('Y-m-d') !== $value) {
            throw new FieldValidationException($key, "Kies een geldige {$label}.");
        }
        return $value;
    }

    /** @param array<string, mixed> $query */
    private function sector(array $query): string
    {
        $sector = $query['sector'] ?? 'all';
        if (!is_string($sector) || ($sector !== 'all' && !array_key_exists($sector, BookingProgramConfig::SCHOOL_TYPES_BY_KEY))) {
            throw new FieldValidationException('sector', 'Kies een geldige onderwijssector.');
        }
        return $sector;
    }

    /** @param array<string, mixed> $query */
    private function population(array $query): string
    {
        $population = $query['population'] ?? 'planning';
        if (!is_string($population) || !in_array($population, ['planning', 'confirmed', 'all'], true)) {
            throw new FieldValidationException('population', 'Kies een geldige statuspopulatie.');
        }
        return $population;
    }

    /** @param array<string, mixed> $query */
    private function program(array $query): string
    {
        $program = $query['program'] ?? 'all';
        if (!is_string($program) || ($program !== 'all' && !array_key_exists($program, \GeoFort\Booking\BookingPolicy::PROGRAM_LABELS))) {
            throw new FieldValidationException('program', 'Kies een geldig programma.');
        }
        return $program;
    }
}

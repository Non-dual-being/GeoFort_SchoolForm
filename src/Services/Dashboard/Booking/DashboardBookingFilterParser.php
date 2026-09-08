<?php
declare(strict_types=1);

namespace GeoFort\Services\Dashboard\Booking;

use DateTimeImmutable;
use GeoFort\Booking\BookingPolicy;
use GeoFort\Booking\BookingProgramConfig;
use GeoFort\Validation\FieldValidationException;

final class DashboardBookingFilterParser
{
    private const PER_PAGE = 20;
    private const MAX_SEARCH_LENGTH = 150;

    /** @param array<string, mixed> $query */
    public function parse(array $query): DashboardBookingFilters
    {
        $page = $this->positiveInteger($query['page'] ?? null);
        $search = $this->optionalString($query, 'search', self::MAX_SEARCH_LENGTH);
        $status = $this->optionalString($query, 'status');
        $sector = $this->optionalString($query, 'sector');
        $program = $this->optionalString($query, 'program');
        $module = $this->optionalString($query, 'module');
        $dateFrom = $this->optionalDate($query, 'dateFrom');
        $dateTo = $this->optionalDate($query, 'dateTo');

        if ($status !== null && !BookingPolicy::isAllowedStatus($status)) {
            throw new FieldValidationException('status', 'Kies een geldige status.');
        }
        if ($sector !== null && !BookingProgramConfig::isValidSchoolSectorValue($sector)) {
            throw new FieldValidationException('sector', 'Kies een geldige onderwijssector.');
        }
        if ($program !== null && !BookingPolicy::isAllowedProgram($program)) {
            throw new FieldValidationException('program', 'Kies een geldig programma.');
        }
        if ($module !== null && !array_key_exists($module, BookingProgramConfig::MODULE_LABELS)) {
            throw new FieldValidationException('module', 'Kies een geldige keuzemodule.');
        }
        if ($dateFrom !== null && $dateTo !== null && $dateFrom > $dateTo) {
            throw new FieldValidationException('dateTo', 'De einddatum mag niet vóór de begindatum liggen.');
        }

        return new DashboardBookingFilters(
            search: $search,
            status: $status,
            sector: $sector,
            program: $program,
            module: $module,
            dateFrom: $dateFrom,
            dateTo: $dateTo,
            page: $page,
            perPage: self::PER_PAGE,
            sort: ($query['sort'] ?? null) === 'asc' ? 'asc' : 'desc',
        );
    }

    private function positiveInteger(mixed $value): int
    {
        if ($value === null || $value === '') return 1;
        if (!is_string($value) || preg_match('/^[1-9]\d*$/', $value) !== 1) {
            throw new FieldValidationException('page', 'Kies een geldig paginanummer.');
        }
        $page = filter_var($value, FILTER_VALIDATE_INT);
        if ($page === false) {
            throw new FieldValidationException('page', 'Kies een geldig paginanummer.');
        }
        return $page;
    }

    /** @param array<string, mixed> $query */
    private function optionalString(array $query, string $key, ?int $maxLength = null): ?string
    {
        $value = $query[$key] ?? null;
        if ($value === null || $value === '') return null;
        if (!is_string($value)) {
            throw new FieldValidationException($key, 'Ongeldige filterwaarde.');
        }
        $value = trim($value);
        if ($value === '') return null;
        if ($maxLength !== null && mb_strlen($value) > $maxLength) {
            throw new FieldValidationException($key, "Gebruik maximaal {$maxLength} tekens.");
        }
        return $value;
    }

    /** @param array<string, mixed> $query */
    private function optionalDate(array $query, string $key): ?string
    {
        $value = $this->optionalString($query, $key);
        if ($value === null) return null;
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if (!$date || $date->format('Y-m-d') !== $value) {
            throw new FieldValidationException($key, 'Gebruik een geldige datum.');
        }
        return $value;
    }
}

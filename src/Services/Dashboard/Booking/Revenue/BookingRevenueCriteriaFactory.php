<?php
declare(strict_types=1);

namespace GeoFort\Services\Dashboard\Booking\Revenue;

use DateTimeImmutable;
use GeoFort\Services\Dashboard\Booking\Export\BookingExportDateBounds;
use GeoFort\Validation\FieldValidationException;

final class BookingRevenueCriteriaFactory
{
    /** @param array<string, mixed> $query */
    public function create(array $query, ?BookingExportDateBounds $bounds = null): BookingRevenueCriteria
    {
        if ($bounds?->isEmpty() === false && !isset($query['startDate'], $query['endDate'])) {
            return new BookingRevenueCriteria((string) $bounds->minDate, (string) $bounds->maxDate, $this->scope($query), $this->page($query));
        }
        $startDate = $this->date($query, 'startDate', 'begindatum');
        $endDate = $this->date($query, 'endDate', 'einddatum');
        if ($bounds?->isEmpty() === false) {
            if ($startDate > $bounds->maxDate || $endDate < $bounds->minDate) {
                return new BookingRevenueCriteria((string) $bounds->minDate, (string) $bounds->maxDate, $this->scope($query), $this->page($query));
            }
            $startDate = max($startDate, (string) $bounds->minDate);
            $endDate = min($endDate, (string) $bounds->maxDate);
            if ($startDate > $endDate) {
                return new BookingRevenueCriteria((string) $bounds->minDate, (string) $bounds->maxDate, $this->scope($query), $this->page($query));
            }
        }
        if ($startDate > $endDate) {
            throw new FieldValidationException('endDate', 'De einddatum mag niet voor de begindatum liggen.');
        }
        return new BookingRevenueCriteria($startDate, $endDate, $this->scope($query), $this->page($query));
    }

    /** @param array<string, mixed> $query */
    private function scope(array $query): string
    {
        $scope = $query['revenueScope'] ?? null;
        return is_string($scope) && in_array($scope, [BookingRevenueCriteria::SCOPE_DEFINITIVE, BookingRevenueCriteria::SCOPE_OPTION, BookingRevenueCriteria::SCOPE_COMBINED], true)
            ? $scope : BookingRevenueCriteria::SCOPE_DEFINITIVE;
    }

    /** @param array<string, mixed> $query */
    private function page(array $query): int
    {
        $page = filter_var($query['page'] ?? 1, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        return is_int($page) ? $page : 1;
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

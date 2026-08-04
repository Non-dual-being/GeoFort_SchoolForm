<?php
declare(strict_types=1);

namespace GeoFort\Services\Dashboard\Booking\Revenue;

use DateTimeImmutable;
use GeoFort\Validation\FieldValidationException;

final class BookingRevenueCriteriaFactory
{
    /** @param array<string, mixed> $query */
    public function create(array $query): BookingRevenueCriteria
    {
        $startDate = $this->date($query, 'startDate', 'begindatum');
        $endDate = $this->date($query, 'endDate', 'einddatum');
        if ($startDate > $endDate) {
            throw new FieldValidationException('endDate', 'De einddatum mag niet voor de begindatum liggen.');
        }
        return new BookingRevenueCriteria($startDate, $endDate);
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

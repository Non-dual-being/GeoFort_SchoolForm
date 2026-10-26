<?php
declare(strict_types=1);

namespace GeoFort\Booking;

use GeoFort\Validation\FieldValidationException;

/** Report identity follows the stored agreement, never the visit date or current price. */
final class BookingProgramVariant
{
    public const LABELS = [
        'day-four' => 'Dagprogramma · vier modules',
        'day-five' => 'Dagprogramma · vijf modules',
        'morning' => 'Ochtendprogramma',
        'custom' => 'Maatwerk',
        'unknown' => 'Onbekende programmavariant',
    ];

    public static function key(?string $program, ?string $version, bool $custom = false): string
    {
        if ($program === 'dag' && $version === FourModuleProgram::VERSION) return 'day-four';
        if ($custom) return 'custom';
        if ($version !== null && $version !== '') return 'unknown';
        return match ($program) { 'dag' => 'day-five', 'ochtend' => 'morning', default => 'unknown' };
    }

    public static function label(string $key): string { return self::LABELS[$key] ?? self::LABELS['unknown']; }

    public static function filter(mixed $value): string
    {
        if (!is_string($value) || ($value !== 'all' && !isset(self::LABELS[$value]))) {
            throw new FieldValidationException('programVariant', 'Kies een geldige programmavariant.');
        }
        return $value;
    }
}

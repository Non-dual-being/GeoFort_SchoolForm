<?php
declare(strict_types=1);
namespace GeoFort\Booking;

use DateTimeImmutable;
use InvalidArgumentException;

/** Availability controls new choices; it never changes an existing agreement. */
final readonly class ProgramAvailabilityPolicy
{
    public function __construct(public bool $fiveModuleOpen = true, public array $periods = []) {}

    public static function fromArray(array $config): self
    {
        return new self($config['fiveModuleOpen'], $config['periods']);
    }

    public function anyOn(string $date, string $program = 'all', string $variant = 'all'): bool
    {
        if ($variant === 'morning' || $program === 'ochtend') return $this->allows('ochtend', null, $date);
        if ($variant === 'day-four') return $this->allows('dag', FourModuleProgram::VERSION, $date);
        if ($variant === 'day-five') return $this->allows('dag', null, $date);
        return $this->allows('dag', null, $date) || $this->allows('dag', FourModuleProgram::VERSION, $date)
            || ($program === 'all' && $this->allows('ochtend', null, $date));
    }

    public function allows(string $program, ?string $version, string $date): bool
    {
        $day = self::date($date);
        if ($day === null || (int) $day->format('N') > 5) return false;
        $weekday = (int) $day->format('N');
        if ($program === 'dag' && $version === FourModuleProgram::VERSION) return FourModuleProgram::availableOn($date);
        if ($program === 'dag' && $version !== null) return false;
        if (!in_array($program, ['dag', 'ochtend'], true)) return false;
        $key = $program === 'ochtend' ? 'morning' : 'five';
        foreach ($this->periods as $period) {
            if ($period['program'] === $key && $date >= $period['from'] && $date <= $period['until']
                && in_array($weekday, $period['weekdays'], true)) return $period['open'];
        }
        return $program === 'ochtend' ? $weekday === 3 : $this->fiveModuleOpen;
    }

    /** Normalized, bounded and unambiguous input, independent of the database. */
    public static function validatePeriods(mixed $periods): array
    {
        if (!is_array($periods) || !array_is_list($periods) || count($periods) > 50) throw new InvalidArgumentException('Gebruik maximaal 50 periodes.');
        $result = [];
        foreach ($periods as $index => $period) {
            $number = $index + 1;
            if (!is_array($period) || array_diff(array_keys($period), ['program', 'from', 'until', 'weekdays', 'open', 'note']) !== []) throw new InvalidArgumentException("Periode {$number} is ongeldig.");
            if (!in_array($period['program'] ?? null, ['morning', 'five'], true) || !is_bool($period['open'] ?? null)) throw new InvalidArgumentException("Kies het programma en open/gesloten bij periode {$number}.");
            $from = self::date($period['from'] ?? null); $until = self::date($period['until'] ?? null);
            if (!$from || !$until || $from > $until) throw new InvalidArgumentException("Controleer de begin- en einddatum van periode {$number}.");
            $days = $period['weekdays'] ?? null;
            if (!is_array($days) || !array_is_list($days) || $days === [] || count($days) > 5 || count(array_unique($days, SORT_REGULAR)) !== count($days)) throw new InvalidArgumentException("Kies werkdagen bij periode {$number}.");
            foreach ($days as $day) if (!is_int($day) || $day < 1 || $day > 5) throw new InvalidArgumentException("Kies alleen maandag tot en met vrijdag bij periode {$number}.");
            $occurs = false;
            foreach ($days as $day) if ($from->modify('+' . (($day - (int) $from->format('N') + 7) % 7) . ' days') <= $until) $occurs = true;
            if (!$occurs) throw new InvalidArgumentException("De gekozen werkdagen vallen niet in periode {$number}.");
            $note = $period['note'] ?? '';
            if (!is_string($note) || mb_strlen($note) > 160 || preg_match('/[\x00-\x1F\x7F]/u', $note)) throw new InvalidArgumentException("Gebruik een toelichting van maximaal 160 tekens bij periode {$number}.");
            sort($days);
            $rule = ['program' => $period['program'], 'from' => $period['from'], 'until' => $period['until'], 'weekdays' => $days, 'open' => $period['open'], 'note' => trim($note)];
            foreach ($result as $previous) {
                if ($previous['program'] !== $rule['program']) continue;
                $start = max($previous['from'], $rule['from']); $end = min($previous['until'], $rule['until']);
                if ($start > $end) continue;
                $startDate = self::date($start);
                foreach (array_intersect($previous['weekdays'], $days) as $day) {
                    if ($startDate->modify('+' . (($day - (int) $startDate->format('N') + 7) % 7) . ' days')->format('Y-m-d') <= $end) {
                        throw new InvalidArgumentException("Periode {$number} overlapt een andere periode voor hetzelfde programma en dezelfde weekdag.");
                    }
                }
            }
            $result[] = $rule;
        }
        return $result;
    }

    private static function date(mixed $value): ?DateTimeImmutable
    {
        if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value)) return null;
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $date !== false && $date->format('Y-m-d') === $value ? $date : null;
    }
}

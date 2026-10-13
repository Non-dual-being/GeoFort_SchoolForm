<?php

declare(strict_types=1);

namespace GeoFort\Services\Dashboard\Roster;

use GeoFort\Booking\BookingProgramConfig;

final class RosterGenerationTemplateProvider
{
    /** @return list<array{label:string,startTime:string,endTime:string}> */
    public function defaultRounds(string $program, ?int $moduleCount = null): array
    {
        // Vijf modules blijft exact de bestaande GeoFort-standaard.
        if ($moduleCount === null || $moduleCount === 5) {
            if ($program === 'ochtend') {
                return [
                    ['label' => 'Ronde 1', 'startTime' => '10:15', 'endTime' => '10:35'],
                    ['label' => 'Ronde 2', 'startTime' => '10:35', 'endTime' => '10:55'],
                    ['label' => 'Ronde 3', 'startTime' => '11:10', 'endTime' => '11:30'],
                    ['label' => 'Ronde 4', 'startTime' => '11:30', 'endTime' => '11:50'],
                    ['label' => 'Ronde 5', 'startTime' => '11:50', 'endTime' => '12:10'],
                ];
            }

            return [
                ['label' => 'Ronde 1', 'startTime' => '10:15', 'endTime' => '11:00'],
                ['label' => 'Ronde 2', 'startTime' => '11:15', 'endTime' => '12:00'],
                ['label' => 'Ronde 3', 'startTime' => '12:00', 'endTime' => '12:45'],
                ['label' => 'Ronde 4', 'startTime' => '13:15', 'endTime' => '14:00'],
                ['label' => 'Ronde 5', 'startTime' => '14:00', 'endTime' => '14:45'],
            ];
        }

        if (!RosterManualProgramPolicy::supportsModuleCount($moduleCount)) {
            return [];
        }

        $configuration = BookingProgramConfig::PROGRAMS[$program] ?? null;
        if (!is_array($configuration)) {
            return [];
        }

        $durationMinutes = $this->durationMinutes($configuration['duurLesmodule'] ?? null);
        $programStart = (string) ($configuration['beginTijd'] ?? '');

        if ($durationMinutes <= 0 || !$this->isTime($programStart)) {
            return [];
        }

        // Ontvangst blijft 15 minuten. Daarna bepaalt het aantal modules de pauzes.
        $cursor = $this->addMinutes($programStart, 15);
        $breakAfterRound = match ($moduleCount) {
            4 => 2,
            3 => 1,
            default => null,
        };
        $breakMinutes = match ($moduleCount) {
            4 => 30,
            3 => 15,
            default => 0,
        };

        $rounds = [];

        for ($index = 1; $index <= $moduleCount; $index++) {
            if (
                $breakAfterRound !== null
                && $index === $breakAfterRound + 1
            ) {
                $cursor = $this->addMinutes($cursor, $breakMinutes);
            }

            $end = $this->addMinutes($cursor, $durationMinutes);
            $rounds[] = [
                'label' => "Ronde {$index}",
                'startTime' => $cursor,
                'endTime' => $end,
            ];
            $cursor = $end;
        }

        return $rounds;
    }

    /** @return list<array{type:string,label:string,startTime:string,endTime:string}> */
    public function defaultTimeline(string $program, ?int $moduleCount = null, ?string $programVersion = null): array
    {
        if ($programVersion === \GeoFort\Booking\FourModuleProgram::VERSION) {
            $round = 0;
            return array_map(static function (array $block) use (&$round): array {
                $type = match ($block['kind']) { 'arrival' => 'welcome', 'lesson' => 'round', default => $block['kind'] };
                return ['type' => $type, 'label' => match ($type) {
                    'welcome' => 'Aankomst & welkom', 'round' => 'Ronde ' . ++$round,
                    'lunch' => 'Lunch', default => 'Afscheid & vertrek',
                }, 'startTime' => $block['start'], 'endTime' => $block['end']];
            }, \GeoFort\Booking\FourModuleProgram::timetable());
        }
        $configuration = BookingProgramConfig::PROGRAMS[$program] ?? null;
        if (!is_array($configuration)) {
            return [];
        }

        $rounds = $this->defaultRounds($program, $moduleCount);
        if ($rounds === []) {
            return [];
        }

        $blocks = [];
        $previousEnd = (string) $configuration['beginTijd'];

        foreach ($rounds as $index => $round) {
            if ($previousEnd < $round['startTime']) {
                // Alleen de bestaande vijf-module-dag gebruikt de vaste lunchpositie.
                $type = $index === 0
                    ? 'welcome'
                    : (
                        ($moduleCount === 5 || $moduleCount === null)
                        && $program === 'dag'
                        && $index === 3
                            ? 'lunch'
                            : 'break'
                    );

                $blocks[] = [
                    'type' => $type,
                    'label' => match ($type) {
                        'welcome' => 'Aankomst & welkom',
                        'lunch' => 'Lunch',
                        default => 'Pauze',
                    },
                    'startTime' => $previousEnd,
                    'endTime' => $round['startTime'],
                ];
            }

            $blocks[] = ['type' => 'round', ...$round];
            $previousEnd = $round['endTime'];
        }

        $configuredEnd = (string) ($configuration['eindTijd'] ?? '');

        if ($moduleCount !== null && $moduleCount < 5) {
            // Kortere programma's eindigen 15 minuten na de laatste lesronde.
            $departureEnd = $this->addMinutes($previousEnd, 15);
            if ($this->isTime($configuredEnd) && $departureEnd > $configuredEnd) {
                $departureEnd = $configuredEnd;
            }
        } else {
            $departureEnd = $configuredEnd;
        }

        if ($this->isTime($departureEnd) && $previousEnd < $departureEnd) {
            $blocks[] = [
                'type' => 'departure',
                'label' => 'Afscheid & vertrek',
                'startTime' => $previousEnd,
                'endTime' => $departureEnd,
            ];
        }

        return $blocks;
    }

    public function note(string $program, ?int $moduleCount = null): string
    {
        if ($moduleCount !== null && $moduleCount >= 2 && $moduleCount <= 4) {
            return match ($moduleCount) {
                4 => 'Vier lesrondes met na ronde 2 een pauze van 30 minuten.',
                3 => 'Drie lesrondes met na ronde 1 een pauze van 15 minuten.',
                2 => 'Twee lesrondes zonder tussenpauze.',
            };
        }

        return $program === 'ochtend'
            ? 'Ochtendtemplate met expliciete aankomst, pauze en vertrek rondom de lesrondes.'
            : 'Standaard GeoFort-dag: 10:00 aankomst, vijf lesrondes, pauze 11:00-11:15, lunch 12:45-13:15 en vertrek 14:45-15:00.';
    }

    private function durationMinutes(mixed $value): int
    {
        if (is_int($value) && $value > 0) {
            return $value;
        }

        if (is_numeric($value) && (int) $value > 0) {
            return (int) $value;
        }

        if (!is_string($value)) {
            return 0;
        }

        $value = trim($value);

        if (
            preg_match(
                '/^(\d+)\s*(?:min|minuut|minuten)$/i',
                $value,
                $matches,
            ) === 1
        ) {
            return (int) $matches[1];
        }

        if (
            preg_match(
                '/^(\d{1,2}):(\d{2})(?::(\d{2}))?$/',
                $value,
                $matches,
            ) === 1
        ) {
            return ((int) $matches[1] * 60) + (int) $matches[2];
        }

        return 0;
    }

    private function addMinutes(string $time, int $minutes): string
    {
        if (!$this->isTime($time)) {
            return $time;
        }

        [$hours, $currentMinutes] = array_map('intval', explode(':', $time));
        $total = ($hours * 60) + $currentMinutes + $minutes;

        return sprintf(
            '%02d:%02d',
            intdiv($total, 60) % 24,
            $total % 60,
        );
    }

    private function isTime(string $time): bool
    {
        if (preg_match('/^(\d{2}):(\d{2})$/', $time, $matches) !== 1) {
            return false;
        }

        return (int) $matches[1] <= 23 && (int) $matches[2] <= 59;
    }
}

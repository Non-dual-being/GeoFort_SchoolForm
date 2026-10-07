<?php

declare(strict_types=1);

namespace GeoFort\Services\Dashboard\Roster;

use RuntimeException;

/** Explicit school-facing fields from the immutable snapshot; never consults live sources. */
final class RosterSchoolProjection
{
    public function fromSnapshot(array $snapshot): array
    {
        if (($snapshot['formatVersion'] ?? null) !== RosterFinalSnapshot::FORMAT_VERSION) {
            throw new RuntimeException('Onbekend snapshotformaat.');
        }
        $view = RosterPresentationInformation::fromSnapshot($snapshot);
        $groups = $view['groups'] ?? [];
        if (count($groups) < 1 || count($groups) > 10) throw new RuntimeException('Ongeldig aantal groepen.');
        usort($groups, static fn (array $a, array $b): int => ($a['position'] ?? $a['id']) <=> ($b['position'] ?? $b['id']));
        $ids = array_column($groups, 'id');
        if (count(array_unique($ids)) !== count($groups)) throw new RuntimeException('Ongeldige groepsrelaties.');
        $labels = array_map(fn (array $g): string => $this->text($g['label'] ?? null, 120), $groups);
        $planning = $view['planning'] ?? [];
        $events = [];
        $boundaries = [];
        foreach ($planning['rounds'] ?? [] as $round) {
            if (!in_array($round['type'] ?? '', ['round', 'welcome', 'break', 'lunch', 'departure'], true)) throw new RuntimeException('Onbekend dagblok.');
            [$start, $end] = $this->times($round);
            $neutral = ($round['type'] ?? '') !== 'round';
            $events[] = ['start' => $start, 'end' => $end, 'groups' => $round['groupIds'] ?? $ids,
                'neutral' => $neutral, 'label' => $neutral ? $this->text($round['label'] ?? null, 255) : '', 'color' => '#FFFFFF'];
            $boundaries[] = $start; $boundaries[] = $end;
        }
        foreach ($planning['sessions'] ?? [] as $session) {
            [$start, $end] = $this->times($session);
            $color = $session['color'] ?? '#E2E8F0';
            if (!is_string($color) || !preg_match('/^#[0-9A-Fa-f]{6}$/D', $color)) throw new RuntimeException('Ongeldige modulekleur.');
            $groupIds = $session['groupIds'] ?? [];
            if ($groupIds === [] || array_diff($groupIds, $ids) !== []) throw new RuntimeException('Ongeldige sessiegroepen.');
            $events[] = ['start' => $start, 'end' => $end, 'groups' => $groupIds, 'neutral' => false,
                'label' => $this->text($session['moduleLabel'] ?? null, 255), 'color' => strtoupper($color)];
            $boundaries[] = $start; $boundaries[] = $end;
        }
        $boundaries = array_values(array_unique($boundaries)); sort($boundaries);
        $rows = [];
        for ($i = 0; $i < count($boundaries) - 1; $i++) {
            $start = $boundaries[$i]; $end = $boundaries[$i + 1];
            $active = array_values(array_filter($events, static fn (array $e): bool => $e['start'] <= $start && $e['end'] >= $end));
            if ($active === []) continue;
            $cells = [];
            foreach ($ids as $id) {
                $matches = array_values(array_filter($active, static fn (array $e): bool => in_array($id, $e['groups'], true) && $e['label'] !== ''));
                if (count($matches) > 1) throw new RuntimeException('Overlappende groepsinhoud.');
                $cell = $matches[0] ?? ['label' => '—', 'color' => '#FFFFFF', 'neutral' => false];
                $cells[] = ['label' => $cell['label'], 'color' => $cell['color'], 'neutral' => $cell['neutral']];
            }
            $rows[] = ['start' => $start, 'end' => $end, 'cells' => $cells];
        }
        if ($rows === [] || count($rows) > 100) throw new RuntimeException('Ongeldige dagindeling.');
        $date = $view['visitDate'] ?? '';
        if (!is_string($date) || preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $date, $parts) !== 1
            || !checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) throw new RuntimeException('Ongeldige bezoekdatum.');
        $school = $view['school'] ?? [];
        $education = $view['education'] ?? [];
        $optional = fn (mixed $value, int $max = 255): string => $this->text($value ?? '', $max, true);
        $levels = array_map(fn (mixed $label): string => $this->text($label, 255), $education['selectionLabels'] ?? []);
        $students = $education['studentCount'] ?? null;
        if ($students !== null && (!is_int($students) || $students < 0)) throw new RuntimeException('Ongeldig leerlingenaantal.');
        return ['school' => $this->text($school['name'] ?? null, 255), 'visitDate' => $date,
            'program' => $this->text($view['education']['programLabel'] ?? '', 255, true),
            'groups' => $labels, 'rows' => $rows,
            'information' => [
                'school' => [
                    'School' => $this->text($school['name'] ?? null, 255),
                    'Contactpersoon' => $optional($school['contactName'] ?? null, 511),
                    'E-mailadres' => $optional($school['contactEmail'] ?? null),
                    'Telefoon contactpersoon' => $optional($school['contactPhone'] ?? null, 40),
                    'Telefoon school' => $optional($school['schoolPhone'] ?? null, 40),
                ],
                'program' => [
                    'Programma' => $optional($education['programLabel'] ?? null),
                    'Sector' => $optional($education['sectorLabel'] ?? null),
                    'Niveau(s)' => implode(' · ', $levels),
                    'Groepen' => (string) count($labels),
                    'Leerlingen' => $students === null ? '' : (string) $students,
                    'Keuzemodule' => $optional($education['choiceModuleLabel'] ?? null),
                ],
            ]];
    }

    private function times(array $event): array
    {
        $start = $event['startTime'] ?? ''; $end = $event['endTime'] ?? '';
        foreach ([$start, $end] as $time) if (!is_string($time) || !preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/D', $time)) throw new RuntimeException('Ongeldige roostertijd.');
        if ($start >= $end) throw new RuntimeException('Ongeldig tijdvak.');
        return [$start, $end];
    }

    private function text(mixed $text, int $max, bool $optional = false): string
    {
        if (!is_string($text) || !mb_check_encoding($text, 'UTF-8') || mb_strlen($text, 'UTF-8') > $max
            || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $text) || (!$optional && trim($text) === '')) {
            throw new RuntimeException('Ongeldige roostertekst.');
        }
        return trim($text);
    }
}

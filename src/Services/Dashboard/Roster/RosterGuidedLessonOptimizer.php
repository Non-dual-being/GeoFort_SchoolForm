<?php

declare(strict_types=1);

namespace GeoFort\Services\Dashboard\Roster;

/** Bounded, deterministic search around saved sessions; never edits a database. */
final class RosterGuidedLessonOptimizer
{
    private const MAX_CANDIDATES = 120;

    public function search(array $baseline, callable $evaluate, array $fixedOccupancy, array $moduleOrder = []): array
    {
        $original = $baseline['sessions'];
        $before = self::metrics($baseline, $fixedOccupancy);
        $best = $baseline;
        $bestMetrics = $before;
        $bestScore = self::score($best, $bestMetrics, 0, $moduleOrder);
        $seen = [self::key($original) => true];
        $count = 1;
        $limited = false;

        // Each pass may improve the preceding arrangement. Equal scores keep the saved order.
        for ($pass = 0; $pass < 3; $pass++) {
            $startKey = self::key($best['sessions']);
            foreach ($this->neighbours($best['sessions']) as $candidate) {
                $key = self::key($candidate);
                if (isset($seen[$key])) continue;
                if ($count >= self::MAX_CANDIDATES) { $limited = true; break 2; }
                $seen[$key] = true;
                $count++;
                // Never introduce an exceptional double Earth Watch / Minecraft lesson.
                if (!$this->normalCapacityPreserved([...$original, ...$fixedOccupancy], [...$candidate, ...$fixedOccupancy])) continue;
                $result = $evaluate($candidate);
                if (!$result['canFinalize']) continue;
                $metrics = self::metrics($result, $fixedOccupancy);
                // Filling mandatory roles takes precedence over reducing idle time.
                if ($result['staffing']['unfilledRequiredAssignments'] >= $best['staffing']['unfilledRequiredAssignments']
                    && ($metrics['paidGapMinutes'] > $bestMetrics['paidGapMinutes']
                    || $metrics['gapMinutes'] > $bestMetrics['gapMinutes'])) continue;
                $changes = self::changes($original, $result['sessions']);
                $score = self::score($result, $metrics, count($changes), $moduleOrder);
                if ($score < $bestScore) {
                    $best = $result;
                    $bestMetrics = $metrics;
                    $bestScore = $score;
                }
            }
            if (self::key($best['sessions']) === $startKey) break;
            if ($pass === 2) $limited = true;
        }

        $best['optimization'] = [
            'before' => $before, 'after' => $bestMetrics,
            'changes' => self::changes($original, $best['sessions']),
            'candidateCount' => $count, 'searchLimited' => $limited,
        ];
        $best['diagnostics']['candidateCount'] = $count;
        $best['templateNote'] = 'Het opgeslagen rooster is de leidraad. Voorstellen verbeteren de bezetting, tussenminuten of gekozen modulevolgorde. Je ziet alle wijzigingen voordat je ze toepast.';
        $best['staffing']['message'] = 'Vergelijk de verschoven lessen en tussenminuten voordat je het voorstel toepast.';
        return $best;
    }

    private static function score(array $result, array $metrics, int $changes, array $moduleOrder = []): array
    {
        $orderScore = array_fill_keys($moduleOrder, 0);
        foreach ($result['sessions'] as $session) if (isset($orderScore[$session['moduleKey']])) $orderScore[$session['moduleKey']] += self::minute($session['startTime']) * count($session['groupIds']);
        return [
            $result['staffing']['unfilledRequiredAssignments'],
            $metrics['paidGapMinutes'], $metrics['gapMinutes'], ...array_values($orderScore), $changes,
        ];
    }

    /** Swap connected groups between equal-duration lesson slots, preserving session identity. */
    private function neighbours(array $sessions): \Generator
    {
        $slots = [];
        foreach ($sessions as $index => $s) $slots[$s['startTime'].'-'.$s['endTime']][] = $index;
        ksort($slots);
        $slots = array_values($slots);
        for ($a = 0; $a < count($slots); $a++) for ($b = $a + 1; $b < count($slots); $b++) {
            $left = $sessions[$slots[$a][0]];
            $right = $sessions[$slots[$b][0]];
            if ($left['endTime'] > $right['startTime']
                || self::duration($left) !== self::duration($right)) continue;
            $indices = [...$slots[$a], ...$slots[$b]];
            $components = [];
            foreach ($indices as $seed) {
                $groups = $sessions[$seed]['groupIds'];
                $component = [];
                do {
                    $oldCount = count($component);
                    foreach ($indices as $index) {
                        if (array_intersect($groups, $sessions[$index]['groupIds']) === []) continue;
                        $component[$index] = true;
                        $groups = array_unique([...$groups, ...$sessions[$index]['groupIds']]);
                    }
                } while (count($component) !== $oldCount);
                $selected = array_keys($component);
                sort($selected);
                $components[implode(',', $selected)] = $selected;
            }
            // Also consider exchanging the whole round, where individual swaps hit capacity.
            $components['all'] = $indices;
            foreach ($components as $selected) {
                if (array_filter($selected, fn(int $i): bool => $sessions[$i]['moduleKey'] === 'Voedsel-Innovatie') !== []) continue;
                $candidate = $sessions;
                foreach ($selected as $index) {
                    $target = in_array($index, $slots[$a], true) ? $right : $left;
                    $candidate[$index]['startTime'] = $target['startTime'];
                    $candidate[$index]['endTime'] = $target['endTime'];
                }
                yield $candidate;
            }
        }
    }

    private function normalCapacityPreserved(array $before, array $after): bool
    {
        $config = new RosterPlanningConfig();
        $maxima = static function(array $sessions): array {
            $maximum = [];
            foreach ($sessions as $point) {
                $counts = [];
                foreach ($sessions as $s) if ($s['startTime'] <= $point['startTime'] && $s['endTime'] > $point['startTime']) {
                    $counts[$s['moduleKey']] = ($counts[$s['moduleKey']] ?? 0) + count($s['groupIds']);
                }
                foreach ($counts as $key => $count) $maximum[$key] = max($maximum[$key] ?? 0, $count);
            }
            return $maximum;
        };
        $original = $maxima($before);
        foreach ($maxima($after) as $key => $count) {
            if ($count > max($config->normalMaxParallel($key), $original[$key] ?? 0)) return false;
        }
        return true;
    }

    /** Union minutes avoids double-counting merged sessions, cooks and overlapping schools. */
    public static function metrics(array $result, array $fixed): array
    {
        $people = array_column($result['staffing']['staff'], null, 'id');
        $cook = $result['staffing']['cook'];
        if ($cook !== null) $people[$cook['id']] = $cook;
        $busy = []; $lessonMinutes = [];
        foreach ([...$result['sessions'], ...$fixed] as $s) {
            $ids = $s['assignedStaffIds'] ?? $s['staffIds'] ?? [];
            $cookId = array_key_exists('assignedStaffIds', $s)
                ? ($s['moduleKey'] === 'Voedsel-Innovatie' ? ($cook['id'] ?? null) : null)
                : ($s['cookStaffId'] ?? null);
            if ($cookId !== null) $ids[] = $cookId;
            for ($m = self::minute($s['startTime']); $m < self::minute($s['endTime']); $m++) {
                $lessonMinutes[$m] = true;
                foreach ($ids as $id) if (isset($people[$id])) $busy[$id][$m] = true;
            }
        }
        $staff = []; $total = 0; $paid = 0;
        foreach ($people as $id => $person) {
            $minutes = array_keys($busy[$id] ?? []);
            $gap = 0;
            if ($minutes !== []) for ($m = min($minutes); $m < max($minutes); $m++) {
                if (isset($lessonMinutes[$m]) && !isset($busy[$id][$m])) $gap++;
            }
            $total += $gap;
            if ($person['employmentType'] !== 'volunteer') $paid += $gap;
            $staff[] = ['id' => $id, 'name' => $person['name'], 'employmentType' => $person['employmentType'],
                'workMinutes' => count($minutes), 'gapMinutes' => $gap];
        }
        return ['gapMinutes' => $total, 'paidGapMinutes' => $paid,
            'unfilledAssignments' => $result['staffing']['unfilledRequiredAssignments'], 'staff' => $staff];
    }

    public static function changes(array $before, array $after): array
    {
        $source = array_column($before, null, 'existingSessionId');
        $changes = [];
        foreach ($after as $session) {
            $old = $source[$session['existingSessionId']];
            if ($old['startTime'] === $session['startTime'] && $old['endTime'] === $session['endTime']) continue;
            $changes[] = ['sessionId' => $session['existingSessionId'], 'moduleLabel' => $session['moduleLabel'],
                'groupLabels' => $session['groupLabels'],
                'fromStart' => $old['startTime'], 'fromEnd' => $old['endTime'],
                'toStart' => $session['startTime'], 'toEnd' => $session['endTime']];
        }
        return $changes;
    }

    private static function key(array $sessions): string
    {
        $times = [];
        foreach ($sessions as $s) $times[$s['existingSessionId']] = [$s['startTime'], $s['endTime']];
        ksort($times);
        return json_encode($times, JSON_THROW_ON_ERROR);
    }

    private static function minute(string $time): int
    {
        return (int) substr($time, 0, 2) * 60 + (int) substr($time, 3, 2);
    }

    private static function duration(array $session): int
    {
        return self::minute($session['endTime']) - self::minute($session['startTime']);
    }
}

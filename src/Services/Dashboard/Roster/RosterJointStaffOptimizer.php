<?php
declare(strict_types=1);
namespace GeoFort\Services\Dashboard\Roster;

/** Bounded, deterministic local search. Lessons and supervision coverage stay fixed. */
final class RosterJointStaffOptimizer
{
    public function optimize(array $tasks, array $members, array $fixed = []): array
    {
        $members = array_column($members, null, 'id');
        $original = array_column($tasks, 'staffId');
        $pool = array_values(array_unique($original)); sort($pool);
        $eligible = [];
        foreach ($tasks as $i => $task) {
            $eligible[$i] = array_values(array_filter($pool, function(int $id) use ($task, $members): bool {
                $m = $members[$id] ?? null;
                return $m !== null && $m['isActive'] && ($task['cook'] ? $m['canCook'] :
                    ($m['canGuide'] && in_array($task['moduleKey'], RosterPlanningConfig::qualifiedModuleKeys(array_column($m['preferences'], 'moduleKey')), true)));
            }));
        }
        $current = $original; $before = $this->metrics($tasks, $current, $members, $original);
        $score = $before['score']; $checks = 0; $limited = false;
        for ($pass = 0; $pass < 30; $pass++) {
            $best = null; $bestScore = $score;
            foreach ($tasks as $i => $_) foreach ($eligible[$i] as $id) {
                if ($id === $current[$i]) continue;
                $candidate = $current; $candidate[$i] = $id;
                if (++$checks > 30000) { $limited = true; break 2; }
                if (!$this->valid($tasks, $candidate, $fixed)) continue;
                $s = $this->metrics($tasks, $candidate, $members, $original)['score'];
                if ($s < $bestScore) { $bestScore = $s; $best = $candidate; }
            }
            // Move a whole compatible sequence together: moving just the first afternoon
            // lesson could create a paid gap that disappears when both lessons move.
            if (!$limited) foreach ($pool as $from) foreach ($pool as $to) {
                if ($from === $to) continue;
                foreach ([true, false] as $cook) {
                    $candidate = $current; $moved = 0;
                    foreach ($tasks as $i => $t) {
                        if ($current[$i] !== $from || $t['cook'] !== $cook || !in_array($to, $eligible[$i], true)) continue;
                        $trial = $candidate; $trial[$i] = $to;
                        if ($this->valid($tasks, $trial, $fixed)) { $candidate = $trial; $moved++; }
                    }
                    if ($moved < 2) continue;
                    if (++$checks > 30000) { $limited = true; break 3; }
                    $s = $this->metrics($tasks, $candidate, $members, $original)['score'];
                    if ($s < $bestScore) { $bestScore = $s; $best = $candidate; }
                }
            }
            if (!$limited) foreach ($tasks as $i => $_) for ($j = $i + 1; $j < count($tasks); $j++) {
                if ($current[$i] === $current[$j] || !in_array($current[$j], $eligible[$i], true) || !in_array($current[$i], $eligible[$j], true)) continue;
                $candidate = $current; [$candidate[$i], $candidate[$j]] = [$current[$j], $current[$i]];
                if (++$checks > 30000) { $limited = true; break 2; }
                if (!$this->valid($tasks, $candidate, $fixed)) continue;
                $s = $this->metrics($tasks, $candidate, $members, $original)['score'];
                if ($s < $bestScore) { $bestScore = $s; $best = $candidate; }
            }
            if ($best === null) break;
            $current = $best; $score = $bestScore;
            if ($limited) break;
        }
        return ['assignments' => $current, 'before' => $before,
            'after' => $this->metrics($tasks, $current, $members, $original),
            'improved' => $current !== $original, 'searchLimited' => $limited];
    }

    public function valid(array $tasks, array $assignment, array $fixed = []): bool
    {
        $byStaff = []; $modules = [];
        foreach ($fixed as $row) foreach ($row['staffIds'] as $id) {
            $byStaff[$id][] = $row;
            if ($id !== ($row['cookStaffId'] ?? null)) $modules[$id][RosterPlanningConfig::teachingFamily($row['moduleKey'])] = true;
        }
        foreach ($tasks as $i => $task) {
            $id = $assignment[$i];
            foreach ($byStaff[$id] ?? [] as $other) if ($task['startTime'] < $other['endTime'] && $task['endTime'] > $other['startTime']) return false;
            $byStaff[$id][] = $task;
            if (!$task['cook']) $modules[$id][RosterPlanningConfig::teachingFamily($task['moduleKey'])] = true;
            if (count($modules[$id] ?? []) > RosterPlanningConfig::MAX_MODULES_PER_STAFF) return false;
            // Availability is carried with the task for every candidate, not inferred from selection.
            $window = $task['availability'][$id] ?? null;
            if ($window !== null && (($window[0] !== null && $task['startTime'] < $window[0]) || ($window[1] !== null && $task['endTime'] > $window[1]))) return false;
        }
        return true;
    }

    public function metrics(array $tasks, array $assignment, array $members, array $original): array
    {
        $groupTeachers = [];
        $points = []; $byStaff = []; $guideCook = 0; $preference = 0; $changes = 0;
        foreach ($tasks as $i => $t) {
            $id = $assignment[$i]; $points[] = $t['startTime']; $points[] = $t['endTime']; $byStaff[$id][] = $t;
            foreach ($t['breaks'] ?? [] as $break) { $points[] = $break['startTime']; $points[] = $break['endTime']; }
            $minutes = $this->minutes($t['endTime']) - $this->minutes($t['startTime']);
            if ($t['cook'] && ($members[$id]['canGuide'] ?? false)) $guideCook += $minutes;
            if (!$t['cook']) foreach ($members[$id]['preferences'] ?? [] as $p) if (RosterPlanningConfig::teachingFamily($p['moduleKey']) === RosterPlanningConfig::teachingFamily($t['moduleKey'])) $preference += (int)$p['rank'];
            if ($id !== $original[$i]) $changes++;
            if (!$t['cook'] && ($t['morning'] ?? false)) foreach ($t['groupIds'] ?? [] as $gid) $groupTeachers[$t['planId'].':'.$gid][$id] = true;
        }
        $points = array_values(array_unique($points)); sort($points);
        $paid = 0; $volunteer = 0; $rows = []; $switches = 0;
        foreach ($members as $id => $m) {
            $work = $byStaff[$id] ?? [];
            if ($work === [] && !in_array($id, $original, true)) continue;
            usort($work, static fn(array $a, array $b): int => $a['startTime'] <=> $b['startTime']);
            $gap = 0; $workMinutes = 0; $cookMinutes = 0; $breaks = [];
            foreach ($work as $j => $t) {
                $duration = $this->minutes($t['endTime']) - $this->minutes($t['startTime']);
                $workMinutes += $duration; if ($t['cook']) $cookMinutes += $duration;
                foreach ($t['breaks'] ?? [] as $break) $breaks[] = $break;
                if ($j > 0 && ($t['cook'] !== $work[$j-1]['cook'] || $t['moduleKey'] !== $work[$j-1]['moduleKey'])) $switches++;
            }
            if ($work !== []) for ($p = 0; $p < count($points)-1; $p++) {
                $a = $points[$p]; $b = $points[$p+1];
                if ($a < $work[0]['startTime'] || $b > $work[count($work)-1]['endTime']) continue;
                // Only actual lesson time is a gap: shared breaks/lunch never count.
                $lesson = false; $busy = false;
                foreach ($tasks as $t) if ($t['startTime'] < $b && $t['endTime'] > $a) { $lesson = true; break; }
                foreach ($work as $t) if ($t['startTime'] < $b && $t['endTime'] > $a) { $busy = true; break; }
                $pause = false;
                foreach ($breaks as $break) if ($break['startTime'] <= $a && $break['endTime'] >= $b) { $pause = true; break; }
                if ($lesson && !$busy && !$pause) $gap += $this->minutes($b)-$this->minutes($a);
            }
            if ($m['employmentType'] === 'volunteer') $volunteer += $gap; else $paid += $gap;
            $rows[] = ['staffId'=>$id,'name'=>$m['name'],'gapMinutes'=>$gap,'workMinutes'=>$workMinutes,'cookMinutes'=>$cookMinutes];
        }
        // Balance actual working minutes without distinguishing paid staff from volunteers.
        // Keep the original guide pool fixed so dropping a short assignment cannot game fairness.
        $guideMinutes = array_column(array_values(array_filter($rows, static fn(array $row): bool =>
            (bool) ($members[$row['staffId']]['canGuide'] ?? false))), 'workMinutes');
        $imbalance = array_sum(array_map(static fn(int $minutes): int => $minutes * $minutes, $guideMinutes));
        $spread = $guideMinutes === [] ? 0 : max($guideMinutes) - min($guideMinutes);
        // Paid gaps and morning continuity remain stronger; specialist cooks stay preferred.
        $groupChanges = array_sum(array_map(static fn(array $teachers): int => max(0,count($teachers)-1),$groupTeachers));
        return ['workSpreadMinutes'=>$spread,'morningGroupChanges'=>$groupChanges,'paidGapMinutes'=>$paid,'volunteerGapMinutes'=>$volunteer,'people'=>count($byStaff),'staff'=>$rows,
            'score'=>[$paid, $groupChanges, $guideCook, $imbalance, count($byStaff), $volunteer, $changes, $switches, $preference]];
    }
    private function minutes(string $time): int { return (int)substr($time,0,2)*60+(int)substr($time,3,2); }
}

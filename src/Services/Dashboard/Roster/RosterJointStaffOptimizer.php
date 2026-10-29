<?php
declare(strict_types=1);
namespace GeoFort\Services\Dashboard\Roster;

/** Bounded, deterministic staffing search. Mandatory coverage and saved lessons take precedence. */
final class RosterJointStaffOptimizer
{
    public function optimize(array $tasks, array $members, array $fixed = [], array $selected = []): array
    {
        $members = array_column($members, null, 'id');
        $original = array_column($tasks, 'staffId');
        $pool = array_values(array_unique(array_filter([...$original, ...$selected]))); sort($pool);
        $members = array_intersect_key($members, array_fill_keys($pool, true));
        $eligible = [];
        foreach ($tasks as $i => $task) {
            $eligible[$i] = array_values(array_filter($pool, function(int $id) use ($task, $members): bool {
                $m = $members[$id] ?? null;
                return $m !== null && $m['isActive'] && ($task['cook'] ? $m['canCook'] :
                    ($m['canGuide'] && in_array($task['moduleKey'], RosterPlanningConfig::qualifiedModuleKeys(array_column($m['preferences'], 'moduleKey')), true)));
            }));
            if ($task['schoolAllowed'] ?? false) $eligible[$i][] = 0;
        }
        $current = $original; $before = $this->metrics($tasks, $current, $members, $original);
        $score = $before['score']; $checks = 0; $limited = false;
        // Reassign a complete day together. A single swap cannot free a specialist
        // when their replacement first needs a different teacher on the other school.
        $remaining = 60000;
        $attempts = [null, ...$pool];
        foreach ($attempts as $excluded) {
            $candidate = $this->cover($tasks, $eligible, $current, $fixed, $excluded, $remaining);
            if ($candidate !== null) {
                // A feasible reduced team may still have avoidable gaps. Improve it
                // before comparing it with a different reduced team.
                for ($pass = 0; $pass < count($tasks); $pass++) {
                    $oldCandidate = $candidate;
                    $localScore = $this->metrics($tasks, $candidate, $members, $original)['score'];
                    foreach ($eligible as $i => $ids) foreach ($ids as $id) {
                        if ($id === $excluded || $id === $candidate[$i]) continue;
                        $trial = $candidate; $trial[$i] = $id;
                        if (!$this->valid($tasks, $trial, $fixed)) continue;
                        $trialScore = $this->metrics($tasks, $trial, $members, $original)['score'];
                        if ($trialScore < $localScore) { $candidate = $trial; $localScore = $trialScore; }
                    }
                    if ($candidate === $oldCandidate) break;
                }
                $candidateScore = $this->metrics($tasks, $candidate, $members, $original)['score'];
                if ($candidateScore < $score) { $current = $candidate; $score = $candidateScore; }
            }
            if ($remaining <= 0) { $limited = true; break; }
        }
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

    /** Find full mandatory coverage within a bounded search, optionally retiring one employee. */
    private function cover(array $tasks, array $eligible, array $current, array $fixed, ?int $excluded, int &$remaining): ?array
    {
        foreach ($eligible as $i => &$ids) {
            $ids = array_values(array_filter($ids, static fn(int $id): bool => $id !== $excluded));
            usort($ids, static fn(int $a, int $b): int =>
                [$a === 0, $a !== $current[$i], $a] <=> [$b === 0, $b !== $current[$i], $b]);
            if ($ids === []) return null;
        }
        unset($ids);
        $visit = function(array $assigned) use (&$visit, &$remaining, $tasks, $eligible, $fixed): ?array {
            if (--$remaining < 0) return null;
            if (count($assigned) === count($tasks)) { ksort($assigned); return $assigned; }
            $best = null; $options = [];
            // Dynamic fewest-options-first handles staff shared between overlapping schools.
            foreach ($tasks as $i => $task) {
                if (isset($assigned[$i])) continue;
                $available = [];
                foreach ($eligible[$i] as $id) {
                    $trial = $assigned + [$i => $id];
                    if ($this->valid(array_intersect_key($tasks, $trial), $trial, $fixed)) $available[] = $id;
                }
                if ($available === []) return null;
                if ($best === null || count($available) < count($options)) { $best = $i; $options = $available; }
            }
            foreach ($options as $id) {
                $result = $visit($assigned + [$best => $id]);
                if ($result !== null) return $result;
                if ($remaining <= 0) break;
            }
            return null;
        };
        return $visit([]);
    }

    public function valid(array $tasks, array $assignment, array $fixed = []): bool
    {
        $byStaff = []; $modules = [];
        foreach ($fixed as $row) foreach ($row['staffIds'] as $id) {
            $byStaff[$id][] = $row;
            if ($id !== ($row['cookStaffId'] ?? null)) $modules[$id][RosterPlanningConfig::teachingFamily($row['moduleKey'])] = true;
        }
        $rows=[];
        foreach ($tasks as $i => $task) {
            $id = $assignment[$i];
            if ($id === 0) { if (!($task['schoolAllowed'] ?? false)) return false; continue; }
            $row=[...$task,'staffIds'=>[$id],'cookStaffId'=>$task['cook']?$id:null];
            foreach($rows as $other) if(RosterSharedFoodStaffing::compatible($row,$other)) {
                if(count(array_unique([...RosterSharedFoodStaffing::guides($row),...RosterSharedFoodStaffing::guides($other)]))>1) return false;
                if($row['cookStaffId']!==null && $other['cookStaffId']!==null && $row['cookStaffId']!==$other['cookStaffId'])return false;
            }
            $rows[]=$row;
            foreach ($byStaff[$id] ?? [] as $other) if ($task['startTime'] < $other['endTime'] && $task['endTime'] > $other['startTime'] && !RosterSharedFoodStaffing::allows($row,$other,$id) && !$this->sharedKeSession($row,$other)) return false;
            $byStaff[$id][] = $row;
            if (!$task['cook']) $modules[$id][RosterPlanningConfig::teachingFamily($task['moduleKey'])] = true;
            if (count($modules[$id] ?? []) > RosterPlanningConfig::MAX_MODULES_PER_STAFF) return false;
            // Availability is carried with the task for every candidate, not inferred from selection.
            $window = $task['availability'][$id] ?? null;
            if ($window !== null && (($window[0] !== null && $task['startTime'] < $window[0]) || ($window[1] !== null && $task['endTime'] > $window[1]))) return false;
        }
        return true;
    }

    /** Two groups in one KE session may share a qualified guide; never two schools/sessions. */
    private function sharedKeSession(array $a, array $b): bool
    {
        return ($a['moduleKey'] ?? '') === 'Klimaat-Experience' && ($b['moduleKey'] ?? '') === 'Klimaat-Experience'
            && !($a['cook'] ?? false) && !($b['cook'] ?? false)
            && ($a['sessionId'] ?? 0) > 0 && ($a['sessionId'] ?? null) === ($b['sessionId'] ?? null)
            && ($a['planId'] ?? 0) > 0 && ($a['planId'] ?? null) === ($b['planId'] ?? null)
            && $a['startTime'] === $b['startTime'] && $a['endTime'] === $b['endTime']
            && ($a['groupCount'] ?? 0) <= 2 && ($b['groupCount'] ?? 0) <= 2
            && ($a['groupIds'] ?? []) !== [] && ($b['groupIds'] ?? []) !== []
            && array_intersect($a['groupIds'], $b['groupIds']) === []
            && count(array_unique([...$a['groupIds'], ...$b['groupIds']])) <= 2;
    }

    public function metrics(array $tasks, array $assignment, array $members, array $original): array
    {
        $groupTeachers = [];
        $points = []; $byStaff = []; $guideCook = [];  $preference = 0; $changes = 0; $missing = 0; $school = 0; $keSchool = 0;
        foreach ($tasks as $i => $t) {
            $id = $assignment[$i]; $points[] = $t['startTime']; $points[] = $t['endTime'];
            if ($id !== $original[$i]) $changes++;
            if ($id === 0) { if ($t['schoolAllowed'] ?? false) { $school++; if ($t['requiresGeoFortForFinal'] ?? false) $keSchool++; } else $missing++; continue; }
            $byStaff[$id][] = $t;
            foreach ($t['breaks'] ?? [] as $break) { $points[] = $break['startTime']; $points[] = $break['endTime']; }
            $minutes = $this->minutes($t['endTime']) - $this->minutes($t['startTime']);
            if ($t['cook'] && ($members[$id]['canGuide'] ?? false)) for($minute=$this->minutes($t['startTime']);$minute<$this->minutes($t['endTime']);$minute++)$guideCook[$id.':'.$minute]=true;
            if (!$t['cook']) foreach ($members[$id]['preferences'] ?? [] as $p) if (RosterPlanningConfig::teachingFamily($p['moduleKey']) === RosterPlanningConfig::teachingFamily($t['moduleKey'])) $preference += (int)$p['rank'];
            if (!$t['cook'] && ($t['morning'] ?? false)) foreach ($t['groupIds'] ?? [] as $gid) $groupTeachers[$t['planId'].':'.$gid][$id] = true;
        }
        $points = array_values(array_unique($points)); sort($points);
        $paid = 0; $volunteer = 0; $rows = []; $switches = 0;
        foreach ($members as $id => $m) {
            $work = $byStaff[$id] ?? [];
            // Retain zero-work rows so the preview also shows employees freed from this day.
            usort($work, static fn(array $a, array $b): int => $a['startTime'] <=> $b['startTime']);
            $gap = 0; $workMinutes = []; $cookMinutes = []; $breaks = [];
            foreach ($work as $j => $t) {
                $duration = $this->minutes($t['endTime']) - $this->minutes($t['startTime']);
                for($minute=$this->minutes($t['startTime']);$minute<$this->minutes($t['endTime']);$minute++) { $workMinutes[$minute]=true; if($t['cook'])$cookMinutes[$minute]=true; }
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
            $rows[] = ['staffId'=>$id,'name'=>$m['name'],'gapMinutes'=>$gap,'workMinutes'=>count($workMinutes),'cookMinutes'=>count($cookMinutes)];
        }
        // After full coverage and compact staffing, balance the remaining guides' work.
        $guideMinutes = array_column(array_values(array_filter($rows, static fn(array $row): bool =>
            (bool) ($members[$row['staffId']]['canGuide'] ?? false) && $row['workMinutes'] > 0)), 'workMinutes');
        $imbalance = array_sum(array_map(static fn(int $minutes): int => $minutes * $minutes, $guideMinutes));
        $spread = $guideMinutes === [] ? 0 : max($guideMinutes) - min($guideMinutes);
        // Coverage and compact staffing precede gaps, continuity and balancing; specialist cooks stay preferred.
        $groupChanges = array_sum(array_map(static fn(array $teachers): int => max(0,count($teachers)-1),$groupTeachers));
        return ['unfilledAssignments'=>$missing,'keSchoolAssignments'=>$keSchool,'schoolAssignments'=>$school,'workSpreadMinutes'=>$spread,'morningGroupChanges'=>$groupChanges,'paidGapMinutes'=>$paid,'volunteerGapMinutes'=>$volunteer,'people'=>count($byStaff),'staff'=>$rows,
            'score'=>[$missing, $keSchool, count($byStaff), $paid, $groupChanges, count($guideCook), $school, $imbalance, $volunteer, $changes, $switches, $preference]];
    }
    private function minutes(string $time): int { return (int)substr($time,0,2)*60+(int)substr($time,3,2); }
}

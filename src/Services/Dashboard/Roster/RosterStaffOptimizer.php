<?php

declare(strict_types=1);

namespace GeoFort\Services\Dashboard\Roster;

use GeoFort\Services\Sql\RosterStaffSqlRepository;

final readonly class RosterStaffOptimizer
{
    public function __construct(private RosterStaffSqlRepository $staff) {}

    /**
     * @param list<array<string,mixed>> $sessions
     * @param list<int> $selectedStaffIds
     * @param list<array{staffId:int,moduleKeys:list<string>}> $plannerModulePreferences
     * @param bool $preferGeoFortKe Deprecated compatibility argument; ignored for new assignments.
     * @return array<string,mixed>
     */
    public function optimize(
        array $sessions,
        array $selectedStaffIds,
        bool $preferGeoFortKe = true,
        ?int $cookStaffId = null,
        array $plannerModulePreferences = [],
        array $fixedOccupancy = [],
        ?int $planId = null,
        ?int $pairId = null,
        ?array $catalog = null,
    ): array {
        $members = array_values(array_filter(
            $catalog === null ? $this->staff->membersByIds($selectedStaffIds) : array_values(array_intersect_key($catalog, array_fill_keys($selectedStaffIds, true))),
            static fn (array $member): bool => $member['isActive'] === true
                && $member['canGuide'] === true,
        ));

        $plannerRanksByStaff = [];
        foreach ($plannerModulePreferences as $preference) {
            $staffId = (int) ($preference['staffId'] ?? 0);
            $moduleKeys = is_array($preference['moduleKeys'] ?? null)
                ? array_values($preference['moduleKeys'])
                : [];

            foreach ($moduleKeys as $index => $moduleKey) {
                if (is_string($moduleKey) && $moduleKey !== '') {
                    $plannerRanksByStaff[$staffId][$moduleKey] = $index + 1;
                }
            }
        }

        $morning = array_intersect(array_column($sessions, 'moduleKey'), ['Zandtafel', 'Rising-Risk', 'Expedition-Earth', 'Dynamische-Globe-Bios']) !== [];
        $state = [];
        foreach ($members as $member) {
            $rankByModule = [];
            foreach (RosterPlanningConfig::rosterPreferences($member['preferences']) as $preference) {
                $rankByModule[(string) $preference['moduleKey']] = (int) $preference['rank'];
            }

            $state[$member['id']] = [
                'pairedSearch' => $fixedOccupancy !== [],
                'id' => $member['id'],
                'name' => $member['name'],
                'employmentType' => $member['employmentType'],
                'rankByModule' => $rankByModule,
                'plannerRankByModule' => $plannerRanksByStaff[(int) $member['id']] ?? [],
                'plannerPreferencePenalty' => 0,
                'load' => 0,
                'workMinutes' => 0,
                'modules' => [],
                'lastSlot' => null,
                'lastModule' => null,
                'lastGroups' => [],
                'slotIndices' => [],
                'preferencePoints' => 0,
            ];
        }

        foreach ($fixedOccupancy as $fixed) foreach ($fixed['staffIds'] ?? [] as $id) {
            if (!isset($state[$id])) continue;
            $state[$id]['alreadyOnDay'] = true;
            if ($id !== ($fixed['cookStaffId'] ?? null)) $state[$id]['modules'][RosterPlanningConfig::teachingFamily($fixed['moduleKey'])] = true;
        }
        $slots = $this->slots($sessions);
        $slotIndexByKey = [];
        foreach ($slots as $index => $slot) {
            $slotIndexByKey[$slot['key']] = $index;
        }

        $tasksBySlot = [];
        $desiredGuideMinutes = 0;
        $schoolPolicy = new RosterPlanningConfig();

        foreach ($sessions as $sessionIndex => $session) {
            $slotKey = (string) $session['startTime'] . '-' . (string) $session['endTime'];
            $duration = $this->durationMinutes(
                (string) $session['startTime'],
                (string) $session['endTime'],
            );
            $minimum = max(0, (int) ($session['minimumGeoFortStaff'] ?? 0));
            $foodRow=[...$session,'planId'=>$planId,'pairId'=>$pairId,'groupCount'=>count($session['groupIds']??[])];
            $sharedFoodGuide=null;
            foreach ($fixedOccupancy as $fixed) if (RosterSharedFoodStaffing::compatible($foodRow,$fixed)) {
                $guides=RosterSharedFoodStaffing::guides($fixed);
                if(count($guides)===1) $sharedFoodGuide=$guides[0];
            }

            for ($position = 0; $position < $minimum; $position++) {
                $tasksBySlot[$slotKey][] = [
                    'sharedFoodGuide' => $sharedFoodGuide,
                    'foodRow' => $foodRow,
                    'sessionIndex' => $sessionIndex,
                    'moduleKey' => (string) $session['moduleKey'],
                    'durationMinutes' => $duration,
                    'groupIds' => $morning ? array_map('intval', $session['groupIds'] ?? []) : [],
                    'required' => true,
                    'fallbackPenalty' => 100000,
                ];
                $desiredGuideMinutes += $duration;
            }

            if (
                $minimum === 0
                && $schoolPolicy->schoolSupervisionAllowed((string) $session['moduleKey'])
                && (bool) ($session['schoolSupervisionAllowed'] ?? false)
                && !($session['schoolFallback'] ?? false)
            ) {
                $tasksBySlot[$slotKey][] = [
                    'sharedFoodGuide' => $sharedFoodGuide,
                    'foodRow' => $foodRow,
                    'sessionIndex' => $sessionIndex,
                    'moduleKey' => (string) $session['moduleKey'],
                    'durationMinutes' => $duration,
                    'groupIds' => $morning ? array_map('intval', $session['groupIds'] ?? []) : [],
                    'required' => false,
                    'fallbackPenalty' => 5000,
                ];
                $desiredGuideMinutes += $duration;
            }
        }

        $cookMember = $cookStaffId === null ? null : ($catalog === null ? $this->staff->memberById($cookStaffId) : ($catalog[$cookStaffId] ?? null));
        $cookMinutes = 0;
        foreach ($slots as $slot) {
            if ($slot['hasVi']) {
                $cookMinutes += $slot['durationMinutes'];
            }
        }

        $guideCookMinutes = 0;
        if ($cookStaffId !== null && isset($state[$cookStaffId])) {
            $guideCookMinutes = $cookMinutes;
        }

        $targetMinutes = count($state) > 0
            ? (int) ceil(($desiredGuideMinutes + $guideCookMinutes) / count($state))
            : 0;

        $assignments = [];
        $assignedIntervals = [];
        $unfilledRequired = 0;
        $unfilledRequiredSessions = [];
        $schoolFallbackSessions = [];
        $searchLimited = false;
        $fourModule = in_array(\GeoFort\Booking\FourModuleProgram::VERSION, array_column($sessions, 'programVersion'), true);

        foreach ($slots as $slot) {
            $slotKey = $slot['key'];
            $tasks = $tasksBySlot[$slotKey] ?? [];
            $slotIndex = (int) $slotIndexByKey[$slotKey];
            $usedStaff = [];
            foreach ($fixedOccupancy as $fixed) {
                if ($fixed['startTime'] < $slot['endTime'] && $fixed['endTime'] > $slot['startTime']) {
                    foreach ($fixed['staffIds'] ?? [] as $id) $usedStaff[$id] = true;
                }
            }

            foreach ($tasks as &$task) {
                $sharedId=$task['sharedFoodGuide'];
                $task['mayShareFood']=false;
                if($sharedId===null)continue;
                $own=[...$task['foodRow'],'cookStaffId'=>$cookStaffId,'staffIds'=>[$sharedId]];
                $overlaps=array_values(array_filter($fixedOccupancy,static fn(array $r):bool=>$r['startTime']<$slot['endTime'] && $r['endTime']>$slot['startTime'] && in_array($sharedId,$r['staffIds']??[],true)));
                $task['mayShareFood']=$sharedId!==$cookStaffId && $overlaps!==[] && count(array_filter($overlaps,static fn(array $r):bool=>!RosterSharedFoodStaffing::allows($own,$r,$sharedId)))===0;
            }
            unset($task);

            // Stored manual lessons may partially overlap without identical slot boundaries.
            foreach ($assignedIntervals as $interval) {
                if ($interval['startTime'] < $slot['endTime'] && $interval['endTime'] > $slot['startTime']) {
                    $usedStaff[$interval['staffId']] = true;
                    foreach($tasks as &$task)if(($task['sharedFoodGuide']??null)===$interval['staffId'])$task['mayShareFood']=false;
                    unset($task);
                }
            }
            if ($cookStaffId !== null) foreach ($slots as $otherSlot) {
                if ($otherSlot['hasVi'] && $otherSlot['startTime'] < $slot['endTime'] && $otherSlot['endTime'] > $slot['startTime']) {
                    $usedStaff[$cookStaffId] = true;
                }
            }

            // Een gekozen kok is gedurende ieder VI-tijdvak niet tegelijk begeleider.
            if ($slot['hasVi'] && $cookStaffId !== null) {
                $usedStaff[$cookStaffId] = true;

                if (isset($state[$cookStaffId])) {
                    $state[$cookStaffId]['workMinutes'] += $slot['durationMinutes'];
                    $state[$cookStaffId]['slotIndices'][] = $slotIndex;
                    $state[$cookStaffId]['lastSlot'] = $slotIndex;
                }
            }

            if ($tasks === []) {
                continue;
            }

            usort($tasks, function (array $left, array $right) use ($state): int {
                if ($left['required'] !== $right['required']) {
                    return $left['required'] ? -1 : 1;
                }

                return $this->eligibleCount((string) $left['moduleKey'], $state)
                    <=> $this->eligibleCount((string) $right['moduleKey'], $state);
            });

            // Every slot prioritizes required coverage before soft costs.
            // Required roles stay first; school is chosen only after maximizing valid GeoFort coverage.
            $coverageFirst = true; // Hard coverage always precedes gaps, workload and preferences.
            $best = ['score' => PHP_INT_MAX, 'choices' => [], 'missing' => [PHP_INT_MAX, PHP_INT_MAX, PHP_INT_MAX]];
            $remainingNodes = $fourModule || isset($sessions[0]['existingSessionId']) ? 10000 : null;
            $memo = [];
            $this->solveSlot(
                tasks: $tasks,
                taskIndex: 0,
                slotIndex: $slotIndex,
                targetMinutes: $targetMinutes,
                state: $state,
                usedStaff: $usedStaff,
                choices: [],
                score: 0,
                best: $best,
                coverageFirst: $coverageFirst,
                remainingNodes: $remainingNodes,
                memo: $memo,
            );
            $searchLimited = $searchLimited || $remainingNodes === 0;

            $assignedThisSlot = [];

            foreach ($best['choices'] as $choice) {
                $sessionIndex = (int) $choice['sessionIndex'];
                $staffId = $choice['staffId'];

                if ($staffId === null) {
                    if ($choice['required']) {
                        $unfilledRequired++;
                        $unfilledRequiredSessions[$sessionIndex] = true;
                    } else {
                        $schoolFallbackSessions[$sessionIndex] = true;
                    }
                    continue;
                }

                $staffId = (int) $staffId;
                $assignments[$sessionIndex] ??= [];
                $assignments[$sessionIndex][] = $staffId;
                $assignedIntervals[] = ['staffId' => $staffId, 'startTime' => $slot['startTime'], 'endTime' => $slot['endTime']];
                $assignedThisSlot[$staffId] = [
                    'groupIds' => $choice['groupIds'],
                    'moduleKey' => (string) $choice['moduleKey'],
                    'durationMinutes' => (int) $choice['durationMinutes'],
                ];
            }

            foreach ($assignedThisSlot as $staffId => $assignment) {
                $member = &$state[$staffId];
                $moduleKey = $assignment['moduleKey'];
                $member['load']++;
                $member['workMinutes'] += $assignment['durationMinutes'];
                $member['modules'][RosterPlanningConfig::teachingFamily($moduleKey)] = true;
                $member['lastGroups'] = $assignment['groupIds'];
                $member['lastSlot'] = $slotIndex;
                $member['lastModule'] = $moduleKey;
                $member['slotIndices'][] = $slotIndex;
                $member['preferencePoints'] += (int) $member['rankByModule'][$moduleKey];
                $member['plannerPreferencePenalty'] += $this->plannerPreferencePenalty(
                    $member,
                    $moduleKey,
                );
                unset($member);
            }
        }

        $staffSummary = [];
        foreach ($state as $member) {
            $slotIndices = array_values(array_unique(array_map('intval', $member['slotIndices'])));
            sort($slotIndices);

            $gaps = 0;
            if (count($slotIndices) > 1) {
                $occupied = array_fill_keys($slotIndices, true);
                for ($slot = min($slotIndices); $slot <= max($slotIndices); $slot++) {
                    if (!isset($occupied[$slot])) {
                        $gaps++;
                    }
                }
            }

            $staffSummary[] = [
                'id' => $member['id'],
                'name' => $member['name'],
                'employmentType' => $member['employmentType'],
                'load' => $member['load'],
                'workMinutes' => $member['workMinutes'],
                'moduleCount' => count($member['modules']),
                'gaps' => $gaps,
                'averagePreferenceRank' => $member['load'] > 0
                    ? round($member['preferencePoints'] / $member['load'], 2)
                    : null,
            ];
        }

        usort(
            $staffSummary,
            static fn (array $left, array $right): int => strcmp($left['name'], $right['name']),
        );

        return [
            'assignments' => $assignments,
            'searchLimited' => $searchLimited,
            'unfilledRequiredAssignments' => $unfilledRequired,
            'unfilledRequiredSessionIndexes' => array_map(
                'intval',
                array_keys($unfilledRequiredSessions),
            ),
            'schoolFallbackSessionIndexes' => array_map('intval', array_keys($schoolFallbackSessions)),
            'newStaffUsedCount' => $fixedOccupancy === [] ? null : count(array_filter($state, static fn(array $member): bool => $member['load'] > 0 && !($member['alreadyOnDay'] ?? false))),
            'selectedStaffCount' => count($members),
            'targetWorkMinutes' => $targetMinutes,
            'plannerPreferencePenalty' => array_sum(array_map(
                static fn (array $member): int => (int) ($member['plannerPreferencePenalty'] ?? 0),
                $state,
            )),
            'staff' => $staffSummary,
            'cook' => $cookMember === null ? null : [
                'id' => $cookMember['id'],
                'name' => $cookMember['name'],
                'employmentType' => $cookMember['employmentType'],
                'workMinutes' => $cookMinutes,
            ],
        ];
    }

    /** @return list<array{key:string,startTime:string,endTime:string,durationMinutes:int,hasVi:bool}> */
    private function slots(array $sessions): array
    {
        $map = [];

        foreach ($sessions as $session) {
            $startTime = (string) $session['startTime'];
            $endTime = (string) $session['endTime'];
            $key = $startTime . '-' . $endTime;

            $map[$key] ??= [
                'key' => $key,
                'startTime' => $startTime,
                'endTime' => $endTime,
                'durationMinutes' => $this->durationMinutes($startTime, $endTime),
                'hasVi' => false,
            ];

            if ((string) $session['moduleKey'] === 'Voedsel-Innovatie') {
                $map[$key]['hasVi'] = true;
            }
        }

        $slots = array_values($map);
        usort(
            $slots,
            static fn (array $left, array $right): int => strcmp($left['startTime'], $right['startTime']),
        );

        return $slots;
    }

    /** @param array<int,array<string,mixed>> $state */
    private function eligibleCount(string $moduleKey, array $state): int
    {
        return count(array_filter(
            $state,
            static fn (array $member): bool => isset($member['rankByModule'][$moduleKey]),
        ));
    }

    private function solveSlot(
        array $tasks,
        int $taskIndex,
        int $slotIndex,
        int $targetMinutes,
        array $state,
        array $usedStaff,
        array $choices,
        int $score,
        array &$best,
        bool $coverageFirst = false,
        array $missing = [0, 0, 0],
        ?int &$remainingNodes = null,
        array &$memo = [],
    ): void {
        // Keep the best complete candidate; validation still rejects missing hard coverage.
        if ($remainingNodes !== null) {
            if ($remainingNodes === 0) return;
            $remainingNodes--;
        }
        // Within a slot the remaining options depend only on the task index and chosen people.
        // Discard repeated partial assignments with no better coverage/soft score.
        $chosen = array_values(array_filter(array_column($choices, 'staffId'), 'is_int'));
        sort($chosen);
        $memoKey = $taskIndex . ':' . implode(',', $chosen);
        $rank = [$missing, $score];
        if (isset($memo[$memoKey]) && $rank >= $memo[$memoKey]) return;
        $memo[$memoKey] = $rank;
        // Continuity can reduce a later candidate's soft score by at most 545 points.
        // Keep that lower bound when pruning so equal-coverage alternatives remain comparable.
        if ($coverageFirst && ($missing > $best['missing']
            || ($missing === $best['missing'] && $score - 545 * (count($tasks) - $taskIndex) >= $best['score']))) {
            return;
        }
        if (!$coverageFirst && $score >= $best['score']) {
            return;
        }

        if ($taskIndex >= count($tasks)) {
            $best = ['score' => $score, 'choices' => $choices, 'missing' => $missing];
            return;
        }

        $task = $tasks[$taskIndex];
        $moduleKey = (string) $task['moduleKey'];
        $candidates = [];

        foreach ($state as $staffId => $member) {
            if (($task['sharedFoodGuide'] ?? null) !== null && $staffId !== $task['sharedFoodGuide']) continue;
            $sharing=($task['mayShareFood']??false) && $staffId===($task['sharedFoodGuide']??null)
                && !in_array($staffId,array_column($choices,'staffId'),true);
            if (isset($usedStaff[$staffId]) && !$sharing) {
                continue;
            }
            if (!isset($member['rankByModule'][$moduleKey])) {
                continue;
            }
            if (
                !isset($member['modules'][RosterPlanningConfig::teachingFamily($moduleKey)])
                && count($member['modules']) >= RosterPlanningConfig::MAX_MODULES_PER_STAFF
            ) {
                continue;
            }

            $candidates[] = [
                'staffId' => (int) $staffId,
                'score' => $this->candidateScore(
                    $member,
                    $moduleKey,
                    $slotIndex,
                    (int) $task['durationMinutes'],
                    $targetMinutes,
                    $task['groupIds'],
                ),
            ];
        }

        usort(
            $candidates,
            static fn (array $left, array $right): int => $left['score'] <=> $right['score'],
        );

        foreach ($candidates as $candidate) {
            $staffId = $candidate['staffId'];
            $nextUsed = $usedStaff;
            $nextUsed[$staffId] = true;

            $nextMissing = $missing;
            if (($state[$staffId]['pairedSearch'] ?? false) && !($state[$staffId]['alreadyOnDay'] ?? false) && $state[$staffId]['load'] === 0) $nextMissing[2]++;
            $nextChoices = $choices;
            $nextChoices[] = [
                'sessionIndex' => (int) $task['sessionIndex'],
                'moduleKey' => $moduleKey,
                'staffId' => $staffId,
                'groupIds' => $task['groupIds'],
                'durationMinutes' => (int) $task['durationMinutes'],
                'required' => (bool) $task['required'],
            ];

            $this->solveSlot(
                $tasks,
                $taskIndex + 1,
                $slotIndex,
                $targetMinutes,
                $state,
                $nextUsed,
                $nextChoices,
                $score + $candidate['score'],
                $best,
                $coverageFirst,
                $nextMissing,
                $remainingNodes,
                $memo,
            );
        }

        $fallbackChoices = $choices;
        $fallbackChoices[] = [
            'sessionIndex' => (int) $task['sessionIndex'],
            'moduleKey' => $moduleKey,
            'staffId' => null,
            'durationMinutes' => (int) $task['durationMinutes'],
            'required' => (bool) $task['required'],
        ];

        $missing[$task['required'] ? 0 : 1]++;
        $this->solveSlot(
            $tasks,
            $taskIndex + 1,
            $slotIndex,
            $targetMinutes,
            $state,
            $usedStaff,
            $fallbackChoices,
            $score + (int) $task['fallbackPenalty'],
            $best,
            $coverageFirst,
            $missing,
            $remainingNodes,
            $memo,
        );
    }

    private function candidateScore(
        array $member,
        string $moduleKey,
        int $slotIndex,
        int $durationMinutes,
        int $targetMinutes,
        array $groupIds,
    ): int {
        $rank = (int) $member['rankByModule'][$moduleKey];

        // KE/VS-bezetting is hierboven al vóór alle soft kosten geoptimaliseerd.
        // Soft prioriteiten:
        // 1. tussenuren zwaarst;
        // 2. expliciete dagvoorkeuren van de planner;
        // 3. werkminuten gelijk verdelen;
        // 4. algemene modulevoorkeur en continuïteit.
        $score = $rank * 20;
        // Morning guides follow their group; paid internal gaps and coverage take priority.
        if ($groupIds !== [] && $member['lastGroups'] !== []
            && array_intersect($groupIds, $member['lastGroups']) === []) $score += 10_000;
        $score += $this->plannerPreferencePenalty($member, $moduleKey);

        // Betaalde krachten en vrijwilligers zijn voor werkverdeling gelijkwaardig.
        $score += (int) $member['workMinutes'] * 2;

        $projectedMinutes = (int) $member['workMinutes'] + $durationMinutes;
        if ($targetMinutes > 0 && $projectedMinutes > $targetMinutes) {
            $score += ($projectedMinutes - $targetMinutes) * 12;
        }

        if ((int) $member['load'] >= 5) {
            $score += 300 * ((int) $member['load'] - 4);
        }

        if ($member['lastModule'] === $moduleKey) {
            $score -= 45;
        } elseif ($member['lastModule'] !== null) {
            $score += 20;
        }

        if (!isset($member['modules'][RosterPlanningConfig::teachingFamily($moduleKey)])) {
            $score += 25 * (count($member['modules']) + 1);
        }

        if (is_int($member['lastSlot'])) {
            if ($slotIndex === $member['lastSlot'] + 1) {
                $score -= 500;
            } elseif ($slotIndex > $member['lastSlot'] + 1) {
                // Alleen een gat tussen twee daadwerkelijke inzetten krijgt
                // deze penalty. Vrij voor de eerste inzet en na de laatste
                // inzet is randvrij en blijft voor betaald/vrijwilliger
                // gelijkwaardig. Interne gaten zijn bij vrijwilligers wel
                // toegestaan wanneer dit operationeel helpt.
                $gapWeight = ($member['employmentType'] ?? 'paid') === 'volunteer'
                    ? 5_000
                    : 50_000;
                $score += $gapWeight * ($slotIndex - $member['lastSlot'] - 1);
            }
        }

        return $score;
    }

    private function plannerPreferencePenalty(array $member, string $moduleKey): int
    {
        $ranks = is_array($member['plannerRankByModule'] ?? null)
            ? $member['plannerRankByModule']
            : [];

        if ($ranks === []) {
            return 0;
        }

        return match ($ranks[$moduleKey] ?? null) {
            1 => 0,
            2 => 250,
            3 => 500,
            default => 2_000,
        };
    }

    private function durationMinutes(string $startTime, string $endTime): int
    {
        return max(0, $this->minutes($endTime) - $this->minutes($startTime));
    }

    private function minutes(string $time): int
    {
        [$hour, $minute] = array_map('intval', explode(':', $time));
        return ($hour * 60) + $minute;
    }
}

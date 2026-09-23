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
     * @return array<string,mixed>
     */
    public function optimize(
        array $sessions,
        array $selectedStaffIds,
        bool $preferGeoFortKe = true,
        ?int $cookStaffId = null,
    ): array {
        $members = array_values(array_filter(
            $this->staff->membersByIds($selectedStaffIds),
            static fn (array $member): bool => $member['isActive'] === true
                && $member['canGuide'] === true,
        ));

        $state = [];
        foreach ($members as $member) {
            $rankByModule = [];
            foreach ($member['preferences'] as $preference) {
                $rankByModule[(string) $preference['moduleKey']] = (int) $preference['rank'];
            }

            $state[$member['id']] = [
                'id' => $member['id'],
                'name' => $member['name'],
                'employmentType' => $member['employmentType'],
                'rankByModule' => $rankByModule,
                'load' => 0,
                'workMinutes' => 0,
                'modules' => [],
                'lastSlot' => null,
                'lastModule' => null,
                'slotIndices' => [],
                'preferencePoints' => 0,
            ];
        }

        $slots = $this->slots($sessions);
        $slotIndexByKey = [];
        foreach ($slots as $index => $slot) {
            $slotIndexByKey[$slot['key']] = $index;
        }

        $tasksBySlot = [];
        $desiredGuideMinutes = 0;

        foreach ($sessions as $sessionIndex => $session) {
            $slotKey = (string) $session['startTime'] . '-' . (string) $session['endTime'];
            $duration = $this->durationMinutes(
                (string) $session['startTime'],
                (string) $session['endTime'],
            );
            $minimum = max(0, (int) ($session['minimumGeoFortStaff'] ?? 0));

            for ($position = 0; $position < $minimum; $position++) {
                $tasksBySlot[$slotKey][] = [
                    'sessionIndex' => $sessionIndex,
                    'moduleKey' => (string) $session['moduleKey'],
                    'durationMinutes' => $duration,
                    'required' => true,
                    'fallbackPenalty' => 100000,
                ];
                $desiredGuideMinutes += $duration;
            }

            if (
                $preferGeoFortKe
                && $minimum === 0
                && (string) $session['moduleKey'] === 'Klimaat-Experience'
                && (bool) ($session['schoolSupervisionAllowed'] ?? false)
            ) {
                $tasksBySlot[$slotKey][] = [
                    'sessionIndex' => $sessionIndex,
                    'moduleKey' => (string) $session['moduleKey'],
                    'durationMinutes' => $duration,
                    'required' => false,
                    'fallbackPenalty' => 1200,
                ];
                $desiredGuideMinutes += $duration;
            }
        }

        $cookMember = $cookStaffId === null ? null : $this->staff->memberById($cookStaffId);
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
        $unfilledRequired = 0;
        $schoolFallbackSessions = [];

        foreach ($slots as $slot) {
            $slotKey = $slot['key'];
            $tasks = $tasksBySlot[$slotKey] ?? [];
            $slotIndex = (int) $slotIndexByKey[$slotKey];
            $usedStaff = [];

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

            $best = ['score' => PHP_INT_MAX, 'choices' => []];
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
            );

            $assignedThisSlot = [];

            foreach ($best['choices'] as $choice) {
                $sessionIndex = (int) $choice['sessionIndex'];
                $staffId = $choice['staffId'];

                if ($staffId === null) {
                    if ($choice['required']) {
                        $unfilledRequired++;
                    } else {
                        $schoolFallbackSessions[$sessionIndex] = true;
                    }
                    continue;
                }

                $staffId = (int) $staffId;
                $assignments[$sessionIndex] ??= [];
                $assignments[$sessionIndex][] = $staffId;
                $assignedThisSlot[$staffId] = [
                    'moduleKey' => (string) $choice['moduleKey'],
                    'durationMinutes' => (int) $choice['durationMinutes'],
                ];
            }

            foreach ($assignedThisSlot as $staffId => $assignment) {
                $member = &$state[$staffId];
                $moduleKey = $assignment['moduleKey'];
                $member['load']++;
                $member['workMinutes'] += $assignment['durationMinutes'];
                $member['modules'][$moduleKey] = true;
                $member['lastSlot'] = $slotIndex;
                $member['lastModule'] = $moduleKey;
                $member['slotIndices'][] = $slotIndex;
                $member['preferencePoints'] += (int) $member['rankByModule'][$moduleKey];
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
            'unfilledRequiredAssignments' => $unfilledRequired,
            'schoolFallbackSessionIndexes' => array_map('intval', array_keys($schoolFallbackSessions)),
            'selectedStaffCount' => count($members),
            'targetWorkMinutes' => $targetMinutes,
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
    ): void {
        if ($score >= $best['score']) {
            return;
        }

        if ($taskIndex >= count($tasks)) {
            $best = ['score' => $score, 'choices' => $choices];
            return;
        }

        $task = $tasks[$taskIndex];
        $moduleKey = (string) $task['moduleKey'];
        $candidates = [];

        foreach ($state as $staffId => $member) {
            if (isset($usedStaff[$staffId])) {
                continue;
            }
            if (!isset($member['rankByModule'][$moduleKey])) {
                continue;
            }
            if (
                !isset($member['modules'][$moduleKey])
                && count($member['modules']) >= 3
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

            $nextChoices = $choices;
            $nextChoices[] = [
                'sessionIndex' => (int) $task['sessionIndex'],
                'moduleKey' => $moduleKey,
                'staffId' => $staffId,
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
        );
    }

    private function candidateScore(
        array $member,
        string $moduleKey,
        int $slotIndex,
        int $durationMinutes,
        int $targetMinutes,
    ): int {
        $rank = (int) $member['rankByModule'][$moduleKey];
        $score = $rank * 100;

        // Betaalde krachten en vrijwilligers krijgen exact dezelfde
        // werkurenweging: employmentType komt bewust niet in de score voor.
        $score += (int) $member['workMinutes'] * 4;

        $projectedMinutes = (int) $member['workMinutes'] + $durationMinutes;
        if ($targetMinutes > 0 && $projectedMinutes > $targetMinutes) {
            $score += ($projectedMinutes - $targetMinutes) * 8;
        }

        if ((int) $member['load'] >= 5) {
            $score += 400 * ((int) $member['load'] - 4);
        }

        if ($member['lastModule'] === $moduleKey) {
            $score -= 60;
        } elseif ($member['lastModule'] !== null) {
            $score += 30;
        }

        if (!isset($member['modules'][$moduleKey])) {
            $score += 35 * (count($member['modules']) + 1);
        }

        if (is_int($member['lastSlot'])) {
            if ($slotIndex === $member['lastSlot'] + 1) {
                $score -= 300;
            } elseif ($slotIndex > $member['lastSlot'] + 1) {
                $score += 2500 * ($slotIndex - $member['lastSlot'] - 1);
            }
        }

        return $score;
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

<?php

declare(strict_types=1);

namespace GeoFort\Services\Dashboard\Roster;

use GeoFort\Services\Sql\RosterSessionSqlRepository;
use GeoFort\Services\Sql\RosterSnapshotSqlRepository;
use GeoFort\Services\Sql\RosterStaffSqlRepository;
use GeoFort\Services\Sql\RosterValidationSqlRepository;
use PDO;

/** Assign staff to saved activities, optionally searching nearby lesson arrangements. */
final readonly class RosterExistingLessonStaffing
{
    public function __construct(
        private PDO $pdo,
        private RosterPlanningConfig $config,
        private RosterGenerationTemplateProvider $templates,
        private RosterStaffSqlRepository $staff,
        private RosterStaffOptimizer $optimizer,
    ) {}

    public function preview(int $planId, array $staffIds, ?int $cookStaffId, array $preferences, ?array $companion, bool $optimizeExistingLessons = false): array
    {
        $repository = new RosterValidationSqlRepository($this->pdo);
        $snapshots = new RosterSnapshotSqlRepository($this->pdo);
        $validator = new RosterValidationService($repository, $snapshots, $this->config, $this->templates);
        $data = $repository->load($planId);
        if ($data === null) throw new RosterPlanException('PLAN_NOT_FOUND', 404, 'Het rooster bestaat niet.');
        if ($data['plan']['status'] !== 'concept') throw new RosterPlanException('PLAN_NOT_EDITABLE', 409, 'Heropen het rooster voordat je docenten indeelt.');

        $context = $data['plan']['planning_mode'] === 'custom' ? $data['context'] : $data['booking'];
        $version = $context['program_version'] ?? null;
        $groups = array_column($data['groups'], null, 'id');
        $links = [];
        foreach ($data['links'] as $link) $links[(int) $link['roster_session_id']][(int) $link['roster_group_id']] = $link['supervision_kind'];
        $proposal = [];
        foreach ($data['sessions'] as $session) {
            if ($session['item_type'] !== 'activity') continue;
            $key = (string) $session['module_key'];
            $module = $this->config->moduleOption($key, $version);
            $groupKinds = $links[(int) $session['id']] ?? [];
            $groupIds = array_keys($groupKinds);
            if ($groupIds === [] || array_diff($groupIds, array_keys($groups)) !== []) {
                throw new RosterPlanException('INVALID_SAVED_LESSONS', 422, 'Koppel eerst geldige groepen aan iedere opgeslagen les.');
            }
            $guideGroups = array_keys(array_filter($groupKinds, static fn(?string $kind): bool => $kind !== 'school'));
            $proposal[] = [
                'existingSessionId' => (int) $session['id'],
                'moduleKey' => $key, 'moduleLabel' => $module['label'], 'color' => $module['color'],
                'startTime' => substr($session['start_time'], 0, 5), 'endTime' => substr($session['end_time'], 0, 5),
                'location' => $session['location_label'], 'roundLabel' => 'Opgeslagen les',
                'programVersion' => $version,
                'groupIds' => $groupIds,
                'groupLabels' => array_map(static fn(int $id): string => $groups[$id]['label'], $groupIds),
                'guideGroupIds' => $guideGroups,
                'minimumGeoFortStaff' => $guideGroups === [] ? 0 : $this->config->requiredGeoFortGuidesForSession($key, count($guideGroups), false, $version),
                'schoolSupervisionAllowed' => $this->config->schoolSupervisionAllowed($key),
                'schoolFallback' => $guideGroups === [],
            ];
        }
        if ($proposal === []) throw new RosterPlanException('EXISTING_LESSONS_REQUIRED', 422, 'Sla eerst een lesrooster op voordat je hier docenten aan toevoegt.');
        $hasVi = in_array('Voedsel-Innovatie', array_column($proposal, 'moduleKey'), true);
        if (!$hasVi) $cookStaffId = null;

        $fixedSchools = [];
        if ($companion !== null) $fixedSchools[$companion['planId']] = [
            ...$companion, 'visitDate' => $data['plan']['visit_date'], 'schoolName' => 'Gekoppelde school',
        ];
        foreach ($repository->otherPlans($data['plan']['visit_date'], $planId) as $other) {
            if ($other['status'] !== 'published' || isset($fixedSchools[(int) $other['id']])) continue;
            $snapshot = $snapshots->state((int) $other['id'])['current'];
            if ($snapshot === null) throw new RosterPlanException('FINAL_SNAPSHOT_MISSING', 409, 'Een definitief rooster heeft geen geldige opgeslagen planning.');
            $fixedSchools[(int) $other['id']] = [
                'planId' => (int) $other['id'], 'revision' => (int) $other['revision'],
                'visitDate' => $data['plan']['visit_date'], 'schoolName' => 'Andere school',
                'cookStaffId' => $snapshot['content']['stored']['settings']['cook_staff_id'] ?? null,
                'occupancy' => $snapshot['content']['occupancy'],
            ];
        }
        $fixed = [];
        foreach ($fixedSchools as $school) foreach ($school['occupancy'] as $row) {
            $fixed[] = [...$row, 'cookStaffId' => $row['cookStaffId'] ?? ($row['moduleKey'] === 'Voedsel-Innovatie' ? $school['cookStaffId'] : null)];
        }
        $evaluate = function(array $proposal) use ($data, $planId, $staffIds, $cookStaffId, $preferences, $companion, $fixedSchools, $fixed, $validator, $hasVi): array {
            usort($proposal, static fn(array $a, array $b): int => [$a['startTime'], $a['endTime'], $a['existingSessionId']] <=> [$b['startTime'], $b['endTime'], $b['existingSessionId']]);
            $optimized = $this->optimizer->optimize($proposal, $staffIds, true, $cookStaffId, $preferences, $fixed);
            foreach ($proposal as $index => &$session) {
                $session['assignedStaffIds'] = $optimized['assignments'][$index] ?? [];
                $session['groupStaffAssignments'] = [];
                foreach ($session['groupIds'] as $groupId) {
                    $position = array_search($groupId, $session['guideGroupIds'], true);
                    $guide = $position === false ? null : ($session['assignedStaffIds'][$session['minimumGeoFortStaff'] === 1 ? 0 : $position] ?? null);
                    $session['groupStaffAssignments'][] = [
                        'groupId' => $groupId, 'kind' => $position === false ? 'school' : ($guide === null ? 'open' : 'staff'),
                        'assignedStaffIds' => $guide === null ? [] : [$guide],
                    ];
                }
            }
            unset($session);

            // Validate the exact proposed relations in memory, including incomplete saved layouts.
            $proposedData = self::validationData($data, $proposal, $staffIds, $cookStaffId);
            $validation = $validator->validateData($proposedData, (int) $data['plan']['revision']);
            $day = (new RosterDayRules($this->config))->validate([
                ['planId' => $planId, 'revision' => (int) $data['plan']['revision'],
                    'visitDate' => $data['plan']['visit_date'], 'schoolName' => 'Deze school',
                    'cookStaffId' => $cookStaffId, 'occupancy' => $validator->occupancy($proposedData)],
                ...array_values($fixedSchools),
            ], focusPlanIds: [$planId]);
            $errors = [...$validation->errors, ...$day['errors']];
            $proposalById = array_column($proposal, null, 'existingSessionId');
            $hardIssues = array_map(static fn(array $error): array => [
                'moduleKey' => null, 'moduleLabel' => null, 'startTime' => null, 'endTime' => null,
                'groupLabels' => [], 'requiredStaff' => null, 'assignedStaff' => null, 'eligibleStaffNames' => [],
                ...array_intersect_key($proposalById[$error['sessionId'] ?? 0] ?? [], array_flip(['moduleKey', 'moduleLabel', 'startTime', 'endTime', 'groupLabels'])),
                ...$error,
            ], $errors);
            $conflicts = array_filter($errors, static fn(array $e): bool => str_starts_with($e['code'], 'STAFF_'));
            $rounds = self::rounds($proposal);
            $minimum = max(array_map(static function(array $r) use ($proposal): int {
                return array_sum(array_map(static fn(array $s): int => $s['startTime'] < $r['endTime'] && $s['endTime'] > $r['startTime'] ? $s['minimumGeoFortStaff'] : 0, $proposal));
            }, $rounds));
            return [
                'planId' => $planId, 'revision' => (int) $data['plan']['revision'], 'companion' => $companion,
                'staffingSignature' => hash('sha256', json_encode([$data['plan']['revision'], $proposal, $cookStaffId, $fixedSchools], JSON_THROW_ON_ERROR)),
                'useExistingLessons' => true, 'lessonPreferences' => null, 'lessonConflictSignature' => null,
                'existingSessionCount' => count($data['sessions']), 'rounds' => $rounds, 'sessions' => $proposal,
                'warnings' => [...$validation->warnings, ...$day['warnings'],
                    ...(($optimized['searchLimited'] ?? false) ? [['code' => 'STAFF_SEARCH_LIMIT', 'message' => 'Niet alle personeelscombinaties zijn onderzocht. Controleer de open plekken.']] : [])],
                'hardIssues' => $hardIssues, 'canApply' => $conflicts === [], 'canFinalize' => $errors === [],
                'diagnostics' => ['compressionLevel' => 1, 'candidateCount' => 1],
                'templateNote' => 'Opgeslagen lessen, groepen, tijden, locaties en schoolbegeleiding blijven behouden.',
                'staffing' => [
                    'mode' => 'with_staff', 'preferGeoFortKe' => true, 'plannerModulePreferences' => $preferences,
                    'hasVi' => $hasVi, 'cookRequired' => $hasVi, 'cookSelected' => $cookStaffId !== null,
                    'selectedStaffCount' => $optimized['selectedStaffCount'],
                    'selectedPeopleCount' => count(array_unique([...$staffIds, ...($cookStaffId === null ? [] : [$cookStaffId])])),
                    'unfilledRequiredAssignments' => $optimized['unfilledRequiredAssignments'],
                    'targetWorkMinutes' => $optimized['targetWorkMinutes'], 'staff' => $optimized['staff'], 'cook' => $optimized['cook'],
                    'minimumSimultaneousGeoFortStaff' => $minimum,
                    'preferredSimultaneousGeoFortStaff' => $minimum,
                    'schoolSupervisionEligibleSessions' => count(array_filter($proposal, static fn(array $s): bool => $s['schoolSupervisionAllowed'])),
                    'assignmentStatus' => $errors === [] ? 'complete' : 'incomplete',
                    'message' => 'Docentenvoorstel op de opgeslagen lesindeling. Open plekken blijven zichtbaar; lessen worden niet verschoven.',
                ],
            ];
        };
        $result = $evaluate($proposal);
        if ($optimizeExistingLessons) {
            $result = (new RosterGuidedLessonOptimizer())->search($result, $evaluate, $fixed);
        }
        $result['optimizeExistingLessons'] = $optimizeExistingLessons;
        $result['staffingSignature'] = hash('sha256', json_encode([
            $result['staffingSignature'], $optimizeExistingLessons, $result['optimization'] ?? null,
        ], JSON_THROW_ON_ERROR));
        return $result;
    }

    private static function rounds(array $sessions): array
    {
        $boundaries = array_values(array_unique([...array_column($sessions, 'startTime'), ...array_column($sessions, 'endTime')]));
        sort($boundaries);
        $rounds = [];
        for ($i = 1; $i < count($boundaries); $i++) {
            $start = $boundaries[$i - 1]; $end = $boundaries[$i];
            if (array_filter($sessions, static fn(array $s): bool => $s['startTime'] < $end && $s['endTime'] > $start) !== []) {
                $rounds[] = ['label' => 'Opgeslagen les', 'startTime' => $start, 'endTime' => $end];
            }
        }
        return $rounds;
    }

    private static function validationData(array $data, array $proposal, array $staffIds, ?int $cook): array
    {
        $data['settings'] = [...($data['settings'] ?? []), 'staffing_mode' => 'with_staff', 'cook_staff_id' => $cook];
        $data['selectedStaff'] = array_map(static fn(int $id): array => ['staff_id' => $id], array_unique([...$staffIds, ...($cook === null ? [] : [$cook])]));
        $byId = array_column($proposal, null, 'existingSessionId');
        $data['sessionStaff'] = []; $data['groupStaff'] = [];
        foreach ($data['sessions'] as &$session) {
            unset($session['cook_staff_id']);
            if (isset($byId[(int) $session['id']])) {
                $candidate = $byId[(int) $session['id']];
                $session['start_time'] = $candidate['startTime'];
                $session['end_time'] = $candidate['endTime'];
            }
        }
        unset($session);
        foreach ($proposal as $session) {
            foreach ($session['assignedStaffIds'] as $id) $data['sessionStaff'][] = ['roster_session_id' => $session['existingSessionId'], 'staff_id' => $id];
            foreach ($session['groupStaffAssignments'] as $group) foreach ($group['assignedStaffIds'] as $id) {
                $data['groupStaff'][] = ['roster_session_id' => $session['existingSessionId'], 'roster_group_id' => $group['groupId'], 'staff_id' => $id];
            }
        }
        foreach ($data['links'] as &$link) {
            foreach ($byId[(int) $link['roster_session_id']]['groupStaffAssignments'] ?? [] as $group) {
                if ((int) $link['roster_group_id'] === $group['groupId']) $link['supervision_kind'] = $group['kind'];
            }
        }
        unset($link);
        return $data;
    }

    public static function saveAssignments(array $session, RosterSessionSqlRepository $sessions, RosterStaffSqlRepository $staff): void
    {
        $id = $session['existingSessionId'];
        $staff->replaceSessionAssignments($id, $session['assignedStaffIds'], 'auto');
        $byGroup = [];
        foreach ($session['groupStaffAssignments'] as $group) {
            $sessions->setGroupSupervision($id, $group['groupId'], $group['kind']);
            $byGroup[$group['groupId']] = $group['assignedStaffIds'];
        }
        $staff->replaceSessionGroupAssignments($id, $byGroup, 'auto');
    }
}

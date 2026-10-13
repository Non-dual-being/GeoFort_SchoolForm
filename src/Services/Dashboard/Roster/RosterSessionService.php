<?php

declare(strict_types=1);

namespace GeoFort\Services\Dashboard\Roster;

use GeoFort\Booking\BookingProgramConfig;
use GeoFort\Services\Sql\RosterPlanSqlRepository;
use GeoFort\Services\Sql\RosterSessionSqlRepository;
use GeoFort\Services\Sql\RosterStaffSqlRepository;
use GeoFort\Services\Sql\StoredBookingSqlRepository;
use PDO;
use RuntimeException;
use Throwable;

final readonly class RosterSessionService
{
    public function __construct(
        private PDO $pdo,
        private StoredBookingSqlRepository $bookings,
        private RosterPlanSqlRepository $plans,
        private RosterSessionSqlRepository $sessions,
        private RosterPlanningConfig $config,
        private ?RosterStaffSqlRepository $staff = null,
    ) {}

    /**
     * @param list<int> $groupIds
     * @param list<int> $staffIds
     * @return array{sessionId:int,revision:int,warnings:list<array{code:string,message:string}>}
     */
    public function saveActivity(
        int $planId,
        ?int $sessionId,
        int $expectedRevision,
        string $moduleKey,
        string $startTime,
        string $endTime,
        ?string $location,
        array $groupIds,
        int $actingAdminId,
        array $staffIds = [],
        ?int $cookStaffId = null,
    ): array {
        $staffIds = array_values(array_unique(array_map('intval', $staffIds)));
        $groupIds = array_values(array_unique($groupIds));

        try {
            if (!$this->pdo->beginTransaction()) {
                throw new RuntimeException('Roostertransactie kon niet worden gestart.');
            }

            if ($this->staff !== null) RosterStaffCatalogGuard::lock($this->pdo);

            $plan = $this->requireEditablePlan($planId, $expectedRevision);
            $context = $this->planningContext($plan);

            $this->assertModuleAllowed($context['moduleKeys'], $moduleKey);
            [$normalizedStart, $normalizedEnd] = $this->validateTimeRange(
                $context['program'],
                $startTime,
                $endTime,
            );
            $this->assertGroupsBelongToPlan($planId, $groupIds);
            $this->assertCookSelection(
                $planId,
                $sessionId,
                $moduleKey,
                $normalizedStart,
                $normalizedEnd,
                $staffIds,
                $cookStaffId,
            );
            $this->assertStaffAssignments(
                $planId,
                $sessionId,
                $moduleKey,
                $normalizedStart,
                $normalizedEnd,
                $staffIds,
                $cookStaffId,
            );


            if ($sessionId !== null && $this->sessions->findByIdForPlan($sessionId, $planId) === null) {
                throw new RosterPlanException(
                    'SESSION_NOT_FOUND',
                    404,
                    'De roostersessie bestaat niet.',
                );
            }

            $preserveGroupStaffAssignments = false;
            $existingSupervision = [];
            if ($sessionId !== null && $this->staff !== null) {
                $existingSupervision = $this->sessions->groupSupervisionForPlan($planId)[$sessionId] ?? [];
                $existingGroupAssignments = $this->staff->groupAssignmentIdsForSession($sessionId);
                if ($existingGroupAssignments !== [] || array_filter($existingSupervision) !== []) {
                    $existingAssignments = $this->staff->assignmentsForPlan($planId)[$sessionId] ?? [];
                    $existingStaffIds = array_values(array_unique(array_map(
                        'intval',
                        array_column($existingAssignments, 'id'),
                    )));
                    $requestedStaffIds = $staffIds;
                    sort($existingStaffIds);
                    sort($requestedStaffIds);
                    $existingSession = $this->sessions->findByIdForPlan($sessionId, $planId);
                    $preserveGroupStaffAssignments = $existingStaffIds === $requestedStaffIds && $existingSession['module_key'] === $moduleKey;
                }
            }

            $duplicateGroups = $this->sessions->duplicateModuleGroupIds(
                $planId,
                $moduleKey,
                $groupIds,
                $sessionId,
            );
            if ($duplicateGroups !== []) {
                throw new RosterPlanException(
                    'DUPLICATE_MODULE_FOR_GROUP',
                    422,
                    'Een of meer groepen hebben deze module al in hun rooster.',
                );
            }

            $overlappingGroups = $this->sessions->overlappingGroupIds(
                $planId,
                $groupIds,
                $normalizedStart,
                $normalizedEnd,
                $sessionId,
            );
            if ($overlappingGroups !== []) {
                throw new RosterPlanException(
                    'GROUP_TIME_CONFLICT',
                    422,
                    'Een of meer groepen zijn in dit tijdvak al bij een andere sessie ingedeeld.',
                );
            }

            $existingParallelGroups = $this->sessions->overlappingModuleGroupCount(
                $planId,
                $moduleKey,
                $normalizedStart,
                $normalizedEnd,
                $sessionId,
            );
            $maxParallel = $this->config->maxParallel($moduleKey);
            $prospectiveParallelGroups = $existingParallelGroups + count($groupIds);

            $location = $this->normalizeLocation($location, $moduleKey);

            if ($sessionId === null) {
                $sessionId = $this->sessions->insertActivity(
                    $planId,
                    $moduleKey,
                    $normalizedStart,
                    $normalizedEnd,
                    $location,
                    $actingAdminId,
                );
            } else {
                $this->sessions->updateActivity(
                    $sessionId,
                    $planId,
                    $moduleKey,
                    $normalizedStart,
                    $normalizedEnd,
                    $location,
                    $actingAdminId,
                );
            }

            $cookOverrides=(new \GeoFort\Services\Sql\RosterSessionCookSqlRepository($this->pdo))->forPlan($planId);
            if($cookOverrides!==[] && $moduleKey==='Voedsel-Innovatie' && $cookStaffId===null) throw new RosterPlanException('COOK_REQUIRED',422,'Kies een kok voor dit lesblok.');
            if($cookOverrides!==[] && $moduleKey==='Voedsel-Innovatie' && $cookStaffId!==null) (new \GeoFort\Services\Sql\RosterSessionCookSqlRepository($this->pdo))->save($sessionId,$cookStaffId);
            $this->sessions->replaceGroups($sessionId, $groupIds);
            if ($preserveGroupStaffAssignments) {
                // Removed groups must not leave their guides in the aggregate assignment.
                $remainingStaffIds = [];
                foreach ($groupIds as $groupId) {
                    $remainingStaffIds = [...$remainingStaffIds, ...RosterGroupSupervision::staffIds(
                        $existingSupervision[$groupId] ?? null,
                        $existingGroupAssignments[$groupId] ?? [],
                        $staffIds,
                    )];
                }
                $staffIds = array_values(array_unique($remainingStaffIds));
            }
            $this->staff?->replaceSessionAssignments($sessionId, $staffIds, 'manual');
            if (!$preserveGroupStaffAssignments) {
                $this->staff?->clearSessionGroupAssignments($sessionId);
                foreach ($existingSupervision as $gid => $kind) if ($kind !== null) $this->sessions->setGroupSupervision($sessionId, $gid, null);
            }
            $this->staff?->addPlanSelection($planId, $staffIds);

            if ($this->staff !== null) {
                $settings = $this->staff->settingsForPlan($planId);
                $mode = ($staffIds !== [] || $cookStaffId !== null)
                    ? 'with_staff'
                    : $settings['staffingMode'];

                $this->staff->savePlanSettings(
                    $planId,
                    $mode,
                    $settings['preferGeoFortKe'],
                    $cookOverrides!==[] ? $settings['cookStaffId'] : $cookStaffId,
                );

                if ($cookStaffId !== null) {
                    $this->staff->addPlanSelection($planId, [$cookStaffId]);
                }
            }

            $revision = $this->plans->advanceRevision($planId, $actingAdminId);
            $warnings = $this->softWarnings($planId, $staffIds);
            if ($prospectiveParallelGroups > $maxParallel) {
                $warnings[] = [
                    'code' => 'PARALLEL_GROUP_LIMIT',
                    'message' => "Conceptwaarschuwing: {$moduleKey} heeft {$prospectiveParallelGroups} groepen tegelijk; maximaal {$maxParallel} is toegestaan voordat het rooster definitief kan worden.",
                ];
            }

            $maximumGroupsPerSession = $this->config->maxGroupsPerSession($moduleKey);
            if (count($groupIds) > $maximumGroupsPerSession) {
                $warnings[] = [
                    'code' => 'SESSION_GROUP_LIMIT',
                    'message' => "Conceptwaarschuwing: deze sessie bevat meer dan {$maximumGroupsPerSession} groepen. Splits de sessie voordat het rooster definitief wordt.",
                ];
            }

            if ($this->staff !== null) {
                $settings = $this->staff->settingsForPlan($planId);
                if ($settings['staffingMode'] === 'with_staff') {
                    $moduleCount = $context['moduleCount'];
                    $schoolAllowed = $this->config->schoolSupervisionAllowedForModuleCount(
                        $moduleKey,
                        $moduleCount,
                        $context['programVersion'] ?? null,
                    );
                    $minimum = $this->config->requiredGeoFortGuidesForSession(
                        $moduleKey,
                        count($groupIds),
                        $schoolAllowed,
                        $context['programVersion'] ?? null,
                    );

                    if (count($staffIds) < $minimum) {
                        $warnings[] = [
                            'code' => 'OPEN_REQUIRED_STAFF',
                            'message' => "Conceptwaarschuwing: deze sessie vraagt minimaal {$minimum} geschikte GeoFort-begeleider(s).",
                        ];
                    }

                    if (
                        $moduleKey === 'Voedsel-Innovatie'
                        && ($context['programVersion'] ?? null) !== \GeoFort\Booking\FourModuleProgram::VERSION
                        && count($staffIds) !== 1
                    ) {
                        $warnings[] = [
                            'code' => 'VI_GUIDE_COUNT',
                            'message' => 'Conceptwaarschuwing: Voedsel Innovatie hoort precies één begeleider naast de kok te hebben.',
                        ];
                    }

                    if ($moduleKey === 'Voedsel-Innovatie' && $cookStaffId === null) {
                        $warnings[] = [
                            'code' => 'OPEN_COOK',
                            'message' => 'Conceptwaarschuwing: Voedsel Innovatie heeft nog geen kok ingevuld.',
                        ];
                    }
                }
            }
RosterPairGuard::staff($this->pdo, $planId);
            if (!$this->pdo->commit()) {
                throw new RuntimeException('Roostertransactie kon niet worden vastgelegd.');
            }

            return [
                'sessionId' => $sessionId,
                'revision' => $revision,
                'warnings' => $warnings,
            ];
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                try {
                    $this->pdo->rollBack();
                } catch (Throwable) {
                }
            }

            if ($exception instanceof RosterPlanException) {
                throw $exception;
            }

            throw new RuntimeException('Roostersessie kon niet worden opgeslagen.', 0, $exception);
        }
    }

    /**
     * Wijzig alleen de begeleider(s) van één groep binnen een gedeelde sessie.
     *
     * roster_session_staff blijft de unie van alle effectieve groepsbegeleiders,
     * zodat de bestaande overlap- en modulelimietcontroles bruikbaar blijven.
     *
     * @param list<int> $staffIds
     * @return array{sessionId:int,revision:int,warnings:list<array{code:string,message:string}>}
     */
    public function saveGroupStaffAssignment(
        int $planId,
        int $sessionId,
        int $expectedRevision,
        int $groupId,
        array $staffIds,
        int $actingAdminId,
        ?string $supervisionKind = null,
    ): array {
        $kind = $supervisionKind ?? 'staff';
        if (!in_array($kind, ['staff', 'school', 'open'], true) || ($kind !== 'staff' && $staffIds !== [])) {
            throw new RosterPlanException('INVALID_SUPERVISION', 422, 'Kies personeel, schoolbegeleiding of OPEN.');
        }
        $staffIds = array_values(array_unique(array_map('intval', $staffIds)));

        try {
            if (!$this->pdo->beginTransaction()) {
                throw new RuntimeException('Roostertransactie kon niet worden gestart.');
            }

            if ($this->staff !== null) RosterStaffCatalogGuard::lock($this->pdo);

            $plan = $this->requireEditablePlan($planId, $expectedRevision);
            $context = $this->planningContext($plan);

            $session = null;
            foreach ($this->sessions->findByPlanId($planId) as $candidate) {
                if ((int) ($candidate['id'] ?? 0) === $sessionId) {
                    $session = $candidate;
                    break;
                }
            }

            if (!is_array($session)) {
                throw new RosterPlanException(
                    'SESSION_NOT_FOUND',
                    404,
                    'De roostersessie bestaat niet.',
                );
            }

            $sessionGroupIds = array_values(array_unique(array_map(
                'intval',
                is_array($session['group_ids'] ?? null) ? $session['group_ids'] : [],
            )));
            if (!in_array($groupId, $sessionGroupIds, true)) {
                throw new RosterPlanException(
                    'GROUP_NOT_IN_SESSION',
                    422,
                    'Deze groep hoort niet bij de gekozen roostersessie.',
                );
            }

            if ($this->staff === null) {
                throw new RuntimeException('Personeelsrepository ontbreekt voor groepsspecifieke toewijzingen.');
            }

            $moduleKey = (string) ($session['module_key'] ?? '');

            $this->assertModuleAllowed($context['moduleKeys'], $moduleKey);
            if ($kind === 'school' && !$this->config->schoolSupervisionAllowedForModuleCount($moduleKey, $context['moduleCount'], $context['programVersion'] ?? null)) {
                throw new RosterPlanException('SCHOOL_NOT_ALLOWED', 422, 'Schoolbegeleiding is voor deze module in dit programma niet toegestaan.');
            }
            $startTime = substr((string) ($session['start_time'] ?? ''), 0, 5);
            $endTime = substr((string) ($session['end_time'] ?? ''), 0, 5);
            $settings = $this->staff->settingsForPlan($planId);
            $cookStaffId = (new \GeoFort\Services\Sql\RosterSessionCookSqlRepository($this->pdo))->forPlan($planId)[$sessionId] ?? $settings['cookStaffId'];

            $this->assertStaffAssignments(
                $planId,
                $sessionId,
                $moduleKey,
                $startTime,
                $endTime,
                $staffIds,
                $cookStaffId,
            );

            $sessionAssignments = $this->staff->assignmentsForPlan($planId)[$sessionId] ?? [];
            $baselineStaffIds = array_values(array_unique(array_map(
                'intval',
                array_column($sessionAssignments, 'id'),
            )));

            if ($kind === 'staff' && $staffIds === []) {
                throw new RosterPlanException(
                    'GROUP_STAFF_REQUIRED',
                    422,
                    'Kies minimaal één begeleider voor deze groep.',
                );
            }

            $assignmentsByGroup = $this->staff->groupAssignmentIdsForSession($sessionId);
            if (
                $assignmentsByGroup === []
                && $baselineStaffIds === []
                && count($sessionGroupIds) > 1
                && $supervisionKind === null
            ) {
                throw new RosterPlanException(
                    'GROUP_STAFF_BASELINE_REQUIRED',
                    422,
                    'Vul eerst minimaal één begeleider voor de gedeelde sessie in voordat je de groepen afzonderlijk verdeelt.',
                );
            }
            $kinds = $session['group_supervision'] ?? [];
            foreach ($sessionGroupIds as $sessionGroupId) {
                $assignmentsByGroup[$sessionGroupId] = RosterGroupSupervision::staffIds($kinds[$sessionGroupId] ?? null, $assignmentsByGroup[$sessionGroupId] ?? [], $baselineStaffIds);
                $kinds[$sessionGroupId] = RosterGroupSupervision::kind($kinds[$sessionGroupId] ?? null, $assignmentsByGroup[$sessionGroupId]);
            }
            $assignmentsByGroup[$groupId] = $staffIds;
            $kinds[$groupId] = $kind;
            if ($supervisionKind !== null || array_filter($session['group_supervision'] ?? []) !== []) {
                foreach ($kinds as $gid => $storedKind) $this->sessions->setGroupSupervision($sessionId, $gid, $storedKind);
            }

            $aggregateStaffSet = [];
            foreach ($assignmentsByGroup as $groupStaffIds) {
                foreach ($groupStaffIds as $staffId) {
                    $aggregateStaffSet[(int) $staffId] = true;
                }
            }
            $aggregateStaffIds = array_map('intval', array_keys($aggregateStaffSet));
            sort($aggregateStaffIds);

            if ($moduleKey === 'Voedsel-Innovatie' && ($context['programVersion'] ?? null) !== \GeoFort\Booking\FourModuleProgram::VERSION && count($aggregateStaffIds) > 1) {
                throw new RosterPlanException(
                    'VI_GUIDE_COUNT',
                    422,
                    'Voedsel Innovatie mag precies één begeleider naast de kok hebben; verdeel VI niet over meerdere begeleiders.',
                );
            }

            $this->staff->replaceSessionGroupAssignments(
                $sessionId,
                $assignmentsByGroup,
                'manual',
            );
            $this->staff->replaceSessionAssignments($sessionId, $aggregateStaffIds, 'manual');
            $this->staff->addPlanSelection($planId, $aggregateStaffIds);

            $mode = ($aggregateStaffIds !== [] || $cookStaffId !== null)
                ? 'with_staff'
                : $settings['staffingMode'];

            $this->staff->savePlanSettings(
                $planId,
                $mode,
                $settings['preferGeoFortKe'],
                $settings['cookStaffId'],
            );

            $revision = $this->plans->advanceRevision($planId, $actingAdminId);
            $warnings = $this->softWarnings($planId, $aggregateStaffIds);
            if ($kind === 'school' && ($context['programVersion'] ?? null) === \GeoFort\Booking\FourModuleProgram::VERSION) {
                $warnings[] = ['code' => 'SCHOOL_SUPERVISION_OVERRIDE', 'message' => 'Deze groep wordt begeleid door de school in plaats van een GeoFort-docent. Controleer en accepteer deze afwijking bij het definitief maken.'];
            }

            $moduleCount = $context['moduleCount'];
            $schoolAllowed = $this->config->schoolSupervisionAllowedForModuleCount(
                $moduleKey,
                $moduleCount,
                $context['programVersion'] ?? null,
            );
            $minimum = $this->config->requiredGeoFortGuidesForSession(
                $moduleKey,
                count($sessionGroupIds),
                $schoolAllowed,
                $context['programVersion'] ?? null,
            );

            if (($context['programVersion'] ?? null) === \GeoFort\Booking\FourModuleProgram::VERSION) {
                $minimum = count(array_filter($kinds, static fn (?string $storedKind): bool => $storedKind !== 'school'));
            }

            if (count($aggregateStaffIds) < $minimum) {
                $warnings[] = [
                    'code' => 'OPEN_REQUIRED_STAFF',
                    'message' => "Conceptwaarschuwing: deze gedeelde sessie vraagt minimaal {$minimum} geschikte GeoFort-begeleider(s).",
                ];
            }

            if ($staffIds === [] && !$schoolAllowed) {
                $warnings[] = [
                    'code' => 'OPEN_GROUP_STAFF',
                    'message' => 'Conceptwaarschuwing: deze groep heeft nog geen begeleider.',
                ];
            }

            RosterPairGuard::staff($this->pdo, $planId);
            if (!$this->pdo->commit()) {
                throw new RuntimeException('Roostertransactie kon niet worden vastgelegd.');
            }

            return [
                'sessionId' => $sessionId,
                'revision' => $revision,
                'warnings' => $warnings,
            ];
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                try {
                    $this->pdo->rollBack();
                } catch (Throwable) {
                }
            }

            if ($exception instanceof RosterPlanException) {
                throw $exception;
            }

            throw new RuntimeException(
                'Groepsspecifieke docenttoewijzing kon niet worden opgeslagen.',
                0,
                $exception,
            );
        }
    }

    /** @return array{revision:int} */
    public function deleteActivity(
        int $planId,
        int $sessionId,
        int $expectedRevision,
        int $actingAdminId,
    ): array {
        try {
            if (!$this->pdo->beginTransaction()) {
                throw new RuntimeException('Roostertransactie kon niet worden gestart.');
            }

            if ($this->staff !== null) RosterStaffCatalogGuard::lock($this->pdo);

            $this->requireEditablePlan($planId, $expectedRevision);

            if (!$this->sessions->delete($sessionId, $planId)) {
                throw new RosterPlanException(
                    'SESSION_NOT_FOUND',
                    404,
                    'De roostersessie bestaat niet.',
                );
            }

            $revision = $this->plans->advanceRevision($planId, $actingAdminId);

            RosterPairGuard::staff($this->pdo, $planId);
            if (!$this->pdo->commit()) {
                throw new RuntimeException('Roostertransactie kon niet worden vastgelegd.');
            }

            return ['revision' => $revision];
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                try {
                    $this->pdo->rollBack();
                } catch (Throwable) {
                }
            }

            if ($exception instanceof RosterPlanException) {
                throw $exception;
            }

            throw new RuntimeException('Roostersessie kon niet worden verwijderd.', 0, $exception);
        }
    }
    /** @param list<int> $staffIds */
    private function assertCookSelection(
        int $planId,
        ?int $sessionId,
        string $moduleKey,
        string $startTime,
        string $endTime,
        array $staffIds,
        ?int $cookStaffId,
    ): void {
        if ($this->staff === null || $cookStaffId === null) {
            return;
        }

        $cook = $this->staff->memberById($cookStaffId);
        if ($cook === null || !$cook['isActive'] || !$cook['canCook']) {
            throw new RosterPlanException(
                'INVALID_COOK_SELECTION',
                422,
                'De geselecteerde kok is niet beschikbaar voor de kokrol.',
            );
        }

        if (
            $moduleKey === 'Voedsel-Innovatie'
            && in_array($cookStaffId, $staffIds, true)
        ) {
            throw new RosterPlanException(
                'STAFF_COOK_CONFLICT',
                422,
                "{$cook['name']} kan in hetzelfde VI-tijdvak niet tegelijk kok en begeleider zijn.",
            );
        }

        $overrides=(new \GeoFort\Services\Sql\RosterSessionCookSqlRepository($this->pdo))->forPlan($planId);
        $viIntervals = $overrides===[] ? $this->staff->viIntervalsForPlan($planId) : [];
        if ($moduleKey === 'Voedsel-Innovatie') {
            $viIntervals[] = ['startTime' => $startTime, 'endTime' => $endTime];
        }

        foreach ($viIntervals as $interval) {
            if (
                $this->staff->overlappingSessionIdsForStaff(
                    $planId,
                    $cookStaffId,
                    $interval['startTime'],
                    $interval['endTime'],
                    $sessionId,
                ) !== []
            ) {
                throw new RosterPlanException(
                    'COOK_TIME_CONFLICT',
                    422,
                    "{$cook['name']} staat tijdens een Voedsel Innovatie-tijdvak al als begeleider ingepland.",
                );
            }
        }
    }

    /** @param list<int> $staffIds */
    private function assertStaffAssignments(
        int $planId,
        ?int $sessionId,
        string $moduleKey,
        string $startTime,
        string $endTime,
        array $staffIds,
        ?int $cookStaffId,
    ): void {
        if ($this->staff === null || $staffIds === []) {
            return;
        }

        foreach ($staffIds as $staffId) {
            if ($staffId <= 0) {
                throw new RosterPlanException('INVALID_STAFF', 422, 'Ongeldige medewerker.');
            }

            $member = $this->staff->memberById($staffId);
            if ($member === null || !$member['isActive'] || !$member['canGuide']) {
                throw new RosterPlanException(
                    'STAFF_NOT_GUIDE',
                    422,
                    'Deze medewerker kan niet als begeleider worden ingezet.',
                );
            }

            $moduleKeys = RosterPlanningConfig::qualifiedModuleKeys(array_column($member['preferences'], 'moduleKey'));
            if (!in_array($moduleKey, $moduleKeys, true)) {
                throw new RosterPlanException(
                    'STAFF_MODULE_NOT_ALLOWED',
                    422,
                    "{$member['name']} kan deze module niet geven.",
                );
            }

            if (
                $cookStaffId !== null
                && $staffId === $cookStaffId
                && (new \GeoFort\Services\Sql\RosterSessionCookSqlRepository($this->pdo))->forPlan($planId)===[]
                && $this->staff->planHasViOverlap($planId, $startTime, $endTime)
            ) {
                throw new RosterPlanException(
                    'STAFF_COOK_CONFLICT',
                    422,
                    "{$member['name']} is in dit tijdvak als kok nodig en kan niet tegelijk begeleiden.",
                );
            }

            if (
                $this->staff->overlappingSessionIdsForStaff(
                    $planId,
                    $staffId,
                    $startTime,
                    $endTime,
                    $sessionId,
                ) !== []
            ) {
                throw new RosterPlanException(
                    'STAFF_TIME_CONFLICT',
                    422,
                    "{$member['name']} staat in dit tijdvak al op een andere sessie.",
                );
            }

            $assignedModules = $this->staff->assignedModuleKeysForStaff(
                $planId,
                $staffId,
                $sessionId,
            );
            if (!in_array($moduleKey, $assignedModules, true)) {
                $assignedModules[] = $moduleKey;
            }

            if (count(array_unique(array_map(RosterPlanningConfig::teachingFamily(...), $assignedModules))) > 3) {
                throw new RosterPlanException(
                    'STAFF_MODULE_LIMIT',
                    422,
                    "{$member['name']} zou op meer dan drie verschillende modules staan.",
                );
            }
        }
    }

    /**
     * Zachte regels blokkeren opslaan niet.
     * @param list<int> $staffIds
     * @return list<array{code:string,message:string}>
     */
    private function softWarnings(int $planId, array $staffIds): array
    {
        if ($this->staff === null || $staffIds === []) {
            return [];
        }

        $warnings = [];

        foreach ($staffIds as $staffId) {
            $member = $this->staff->memberById($staffId);
            if ($member === null) {
                continue;
            }

            $intervals = $this->staff->assignmentIntervalsForStaff($planId, $staffId);
            for ($index = 0; $index < count($intervals) - 1; $index++) {
                $current = $intervals[$index];
                $next = $intervals[$index + 1];
                $gap = $this->minutes($next['startTime']) - $this->minutes($current['endTime']);

                if ($gap > 45) {
                    $warnings[] = [
                        'code' => 'STAFF_GAP',
                        'message' => "{$member['name']} heeft waarschijnlijk een tussenuur.",
                    ];
                    break;
                }
            }
        }

        return $warnings;
    }

    /** @return array{id:int,booking_id:int|null,status:string,revision:int} */
    private function requireEditablePlan(int $planId, int $expectedRevision): array
    {
        $plan = $this->plans->lockPlan($planId);
        if ($plan === null) {
            throw new RosterPlanException(
                'PLAN_NOT_FOUND',
                404,
                'Het roosterplan bestaat niet.',
            );
        }

        if ($plan['status'] !== 'concept') {
            throw new RosterPlanException(
                'PLAN_NOT_EDITABLE',
                409,
                'Alleen een conceptrooster kan worden gewijzigd.',
            );
        }

        if ($plan['revision'] !== $expectedRevision) {
            throw new RosterPlanException(
                'ROSTER_REVISION_CONFLICT',
                409,
                'Het rooster is ondertussen gewijzigd. Herlaad de pagina en probeer opnieuw.',
            );
        }

        return $plan;
    }

    /**
     * Resolve the operational source for session editing.
     *
     * booking_locked: the linked booking remains authoritative.
     * custom: roster_manual_plan_context is authoritative; booking_id is provenance only.
     *
     * @param array{id:int,booking_id:int|null,planning_mode:string,status:string,revision:int} $plan
     * @return array{program:string,moduleKeys:list<string>,moduleCount:int}
     */
    private function planningContext(array $plan): array
    {
        $bookingId = $plan['booking_id'] ?? null;
        $planningMode = is_string($plan['planning_mode'] ?? null)
            ? (string) $plan['planning_mode']
            : ($bookingId === null ? 'custom' : 'booking_locked');

        if ($planningMode === 'custom') {
            $manualContext = $this->plans->manualContextForPlan((int) $plan['id']);
            if ($manualContext === null) {
                throw new RosterPlanException(
                    'MANUAL_CONTEXT_NOT_FOUND',
                    409,
                    'De maatwerkbasis van dit rooster ontbreekt.',
                );
            }

            $program = $this->contextString($manualContext['program_key'] ?? null);
            if ($program === null) {
                throw new RosterPlanException(
                    'MANUAL_CONTEXT_INVALID',
                    409,
                    'Het programma ontbreekt in de maatwerkbasis.',
                );
            }

            $moduleKeys = $this->decodeContextModuleKeys(
                $manualContext['module_keys_json'] ?? null,
            );
            if ($moduleKeys === []) {
                throw new RosterPlanException(
                    'MANUAL_PROGRAM_REQUIRED',
                    409,
                    'Sla eerst het maatwerkprogramma op voordat je sessies wijzigt.',
                );
            }

            return [
                'program' => $program,
                'programVersion' => $manualContext['program_version'] ?? null,
                'moduleKeys' => $moduleKeys,
                'moduleCount' => count($moduleKeys),
            ];
        }

        if ($planningMode !== 'booking_locked') {
            throw new RosterPlanException(
                'INVALID_PLANNING_MODE',
                409,
                'Het rooster heeft geen geldige planningsautoriteit.',
            );
        }

        $booking = $this->requireBooking($plan);
        $moduleKeys = $this->config->requiredModuleKeys(
            $booking->schoolSector,
            $booking->program,
            $booking->choiceModuleKey,
            $booking->programVersion,
        );

        if ($moduleKeys === []) {
            throw new RosterPlanException(
                'PROGRAM_MODULES_REQUIRED',
                409,
                'Het aanvraagprogramma bevat geen roosterbare modules.',
            );
        }

        return [
            'program' => $booking->program,
            'programVersion' => $booking->programVersion,
            'moduleKeys' => $moduleKeys,
            'moduleCount' => count($moduleKeys),
        ];
    }

    /** @return list<string> */
    private function decodeContextModuleKeys(mixed $value): array
    {
        if ($value === null || $value === '') {
            return [];
        }

        if (is_string($value)) {
            try {
                $value = json_decode($value, true, 32, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                return [];
            }
        }

        if (!is_array($value)) {
            return [];
        }

        $keys = [];
        foreach ($value as $moduleKey) {
            if (!is_string($moduleKey) || trim($moduleKey) === '') {
                continue;
            }

            $moduleKey = trim($moduleKey);
            if (!in_array($moduleKey, $keys, true)) {
                $keys[] = $moduleKey;
            }
        }

        return $keys;
    }

    private function contextString(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);
        return $value === '' ? null : $value;
    }
    private function requireBooking(array $plan): \GeoFort\Booking\Stored\StoredBooking
    {
        $bookingId = $plan['booking_id'];
        if ($bookingId === null) {
            throw new RosterPlanException(
                'BOOKING_REQUIRED',
                422,
                'Deze sessie-editor ondersteunt in deze run alleen roosters met een gekoppelde aanvraag.',
            );
        }

        $booking = $this->bookings->findById($bookingId);
        if ($booking === null) {
            throw new RosterPlanException(
                'BOOKING_NOT_FOUND',
                404,
                'De gekoppelde aanvraag bestaat niet.',
            );
        }

        return $booking;
    }

    /** @param list<string> $allowedModuleKeys */
    private function assertModuleAllowed(array $allowedModuleKeys, string $moduleKey): void
    {
        if (!in_array($moduleKey, $allowedModuleKeys, true)) {
            throw new RosterPlanException(
                'MODULE_NOT_ALLOWED',
                422,
                'Deze module hoort niet bij het operationele roosterprogramma.',
            );
        }
    }

    /** @param list<int> $groupIds */
    private function assertGroupsBelongToPlan(int $planId, array $groupIds): void
    {
        if ($groupIds === []) {
            throw new RosterPlanException(
                'GROUP_REQUIRED',
                422,
                'Kies minimaal een roostergroep voor de sessie.',
            );
        }

        foreach ($groupIds as $groupId) {
            if ($groupId <= 0) {
                throw new RosterPlanException('INVALID_GROUP', 422, 'Ongeldige roostergroep.');
            }
        }

        $allowed = $this->plans->findGroupIds($planId);
        foreach ($groupIds as $groupId) {
            if (!in_array($groupId, $allowed, true)) {
                throw new RosterPlanException(
                    'GROUP_NOT_IN_PLAN',
                    422,
                    'Een geselecteerde groep hoort niet bij dit rooster.',
                );
            }
        }
    }

    /** @return array{0:string,1:string} */
    private function validateTimeRange(string $program, string $startTime, string $endTime): array
    {
        $start = $this->normalizeTime($startTime);
        $end = $this->normalizeTime($endTime);

        if ($this->minutes($end) <= $this->minutes($start)) {
            throw new RosterPlanException(
                'INVALID_TIME_RANGE',
                422,
                'De eindtijd moet na de starttijd liggen.',
            );
        }

        $programConfig = BookingProgramConfig::PROGRAMS[$program] ?? null;
        if (!is_array($programConfig)) {
            throw new RosterPlanException('PROGRAM_NOT_SUPPORTED', 422, 'Onbekend programma.');
        }

        $programStart = (string) $programConfig['beginTijd'];
        $programEnd = (string) $programConfig['eindTijd'];

        if (
            $this->minutes($start) < $this->minutes($programStart)
            || $this->minutes($end) > $this->minutes($programEnd)
        ) {
            throw new RosterPlanException(
                'TIME_OUTSIDE_PROGRAM',
                422,
                "De sessie moet tussen {$programStart} en {$programEnd} vallen.",
            );
        }

        return [$start, $end];
    }

    private function normalizeTime(string $value): string
    {
        $value = trim($value);
        if (preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $value) !== 1) {
            throw new RosterPlanException('INVALID_TIME', 422, 'Gebruik een geldige tijd in HH:MM.');
        }

        return $value;
    }

    private function minutes(string $time): int
    {
        [$hours, $minutes] = array_map('intval', explode(':', $time));
        return ($hours * 60) + $minutes;
    }

    private function normalizeLocation(?string $location, string $moduleKey): ?string
    {
        $location = trim((string) $location);
        if ($location === '') {
            $location = (string) ($this->config->moduleOption($moduleKey)['defaultLocation'] ?? '');
        }

        if (mb_strlen($location) > 160) {
            throw new RosterPlanException(
                'LOCATION_TOO_LONG',
                422,
                'De locatie mag maximaal 160 tekens bevatten.',
            );
        }

        return $location === '' ? null : $location;
    }
}

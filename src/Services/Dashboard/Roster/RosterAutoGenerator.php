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

final readonly class RosterAutoGenerator
{
    public function __construct(
        private PDO $pdo,
        private StoredBookingSqlRepository $bookings,
        private RosterPlanSqlRepository $plans,
        private RosterSessionSqlRepository $sessions,
        private RosterPlanningConfig $config,
        private RosterGenerationTemplateProvider $templates,
        private ?RosterStaffSqlRepository $staff = null,
        private ?RosterStaffOptimizer $staffOptimizer = null,
    ) {}

    /**
     * @param list<array{label?:mixed,startTime?:mixed,endTime?:mixed}> $rounds
     * @param list<int> $staffIds
     * @param list<array{staffId:int,moduleKeys:list<string>}> $plannerModulePreferences
     * @param bool $preferGeoFortKe Deprecated compatibility metadata; ignored by generation policy.
     * @return array<string,mixed>
     */
    public function preview(
        int $planId,
        array $rounds,
        array $staffIds = [],
        string $staffingMode = 'with_staff',
        bool $preferGeoFortKe = true,
        ?int $cookStaffId = null,
        array $plannerModulePreferences = [],
    ): array {
        $this->pdo->beginTransaction();
        try {
            $revision = $this->staff === null ? 0 : RosterStaffCatalogGuard::lock($this->pdo);
            $proposal = $this->calculatePreview($planId, $rounds, $staffIds, $staffingMode, $preferGeoFortKe, $cookStaffId, $plannerModulePreferences);
            $this->pdo->rollBack();
            return [...$proposal, 'catalogRevision' => $revision];
        } catch (Throwable $exception) { if ($this->pdo->inTransaction()) $this->pdo->rollBack(); throw $exception; }
    }

    private function calculatePreview(
        int $planId,
        array $rounds,
        array $staffIds = [],
        string $staffingMode = 'with_staff',
        bool $preferGeoFortKe = true,
        ?int $cookStaffId = null,
        array $plannerModulePreferences = [],
    ): array {
        $context = $this->context($planId);
        $groups = $context['groups'];
        $modules = $context['modules'];

        $rounds = $this->validateRounds(
            $context['program'],
            $rounds,
            count($modules),
        );

        if ($modules === [] || count($rounds) !== count($modules)) {
            throw new RosterPlanException(
                'GENERATION_MODULE_ROUND_MISMATCH',
                422,
                'Automatische generatie vereist voor iedere programmamodule precies een ronde.',
            );
        }

        $staffingMode = $this->normalizeStaffingMode($staffingMode);
        if ($staffingMode === 'lesson_only') {
            $staffIds = [];
            $cookStaffId = null;
        }

        $hasViInputModule = in_array(
            'Voedsel-Innovatie',
            array_column($modules, 'key'),
            true,
        );
        if (!$hasViInputModule) {
            $cookStaffId = null;
        }

        $hasKeInputModule = in_array(
            'Klimaat-Experience',
            array_column($modules, 'key'),
            true,
        );
        if (!$hasKeInputModule) {
            $preferGeoFortKe = false;
        }

        $this->validateStaffSelection($staffIds, $cookStaffId, $staffingMode);
        $plannerModulePreferences = $this->normalizePlannerModulePreferences(
            $plannerModulePreferences,
            $staffIds,
            $modules,
            $staffingMode,
        );

        $orderedModules = $this->generationModuleOrder($modules);
        $programModuleCount = count($orderedModules);
        $orderedModules = array_map(
            static fn (array $module): array => [
                ...$module,
                'programModuleCount' => $programModuleCount,
            ],
            $orderedModules,
        );

        $hasViModule = in_array(
            'Voedsel-Innovatie',
            array_column($orderedModules, 'key'),
            true,
        );
        $cookMissing = $staffingMode === 'with_staff'
            && $hasViModule
            && $cookStaffId === null;

        $optimized = [
            'assignments' => [],
            'unfilledRequiredAssignments' => 0,
            'unfilledRequiredSessionIndexes' => [],
            'schoolFallbackSessionIndexes' => [],
            'selectedStaffCount' => 0,
            'targetWorkMinutes' => 0,
            'staff' => [],
            'cook' => null,
        ];

        $proposal = [];
        $selectedCompressionLevel = 1;
        $candidateCount = 0;
        $lessonStructureIssue = null;

        $bestValidProposal = null;
        $bestValidOptimized = null;
        $bestValidScore = [PHP_INT_MAX, PHP_INT_MAX];
        $bestValidCompressionLevel = 1;

        $bestInvalidProposal = null;
        $bestInvalidOptimized = null;
        $bestInvalidHardCount = PHP_INT_MAX;
        $bestInvalidScore = [PHP_INT_MAX, PHP_INT_MAX];
        $bestInvalidCompressionLevel = 1;

        $compressionLevels = $staffingMode === 'with_staff'
            ? [1, 2, 3, 4]
            : [1];

        foreach ($compressionLevels as $compressionLevel) {
            $levelHasValid = false;

            $candidates = $this->buildLessonProposalCandidates(
                array_values($groups),
                array_values($rounds),
                $orderedModules,
                $staffingMode === 'with_staff' ? 600 : 1,
                $compressionLevel,
            );
            $candidateCount += count($candidates);

            if ($candidates === []) {
                continue;
            }

            if ($staffingMode === 'lesson_only') {
                $proposal = $candidates[0];
                $selectedCompressionLevel = $compressionLevel;
                break;
            }

            if ($this->staffOptimizer === null) {
                continue;
            }

            foreach ($candidates as $candidate) {
                $candidateOptimized = $this->staffOptimizer->optimize(
                    $candidate,
                    $staffIds,
                    $preferGeoFortKe,
                    $cookStaffId,
                    $plannerModulePreferences,
                );

                $hardCount = (int) $candidateOptimized['unfilledRequiredAssignments']
                    + ($cookMissing ? 1 : 0);

                $score = $this->staffedCandidateScore(
                    $candidateOptimized,
                    $candidate,
                );

                if ($hardCount === 0) {
                    $levelHasValid = true;

                    if ($bestValidProposal === null || $score < $bestValidScore) {
                        $bestValidProposal = $candidate;
                        $bestValidOptimized = $candidateOptimized;
                        $bestValidScore = $score;
                        $bestValidCompressionLevel = $compressionLevel;
                    }
                    continue;
                }

                if (
                    $bestInvalidProposal === null
                    || $hardCount < $bestInvalidHardCount
                    || (
                        $hardCount === $bestInvalidHardCount
                        && $score < $bestInvalidScore
                    )
                ) {
                    $bestInvalidProposal = $candidate;
                    $bestInvalidOptimized = $candidateOptimized;
                    $bestInvalidHardCount = $hardCount;
                    $bestInvalidScore = $score;
                    $bestInvalidCompressionLevel = $compressionLevel;
                }
            }

            if ($levelHasValid) {
                break;
            }
        }

        if ($staffingMode === 'with_staff') {
            if ($bestValidProposal !== null && $bestValidOptimized !== null) {
                $proposal = $bestValidProposal;
                $optimized = $bestValidOptimized;
                $selectedCompressionLevel = $bestValidCompressionLevel;
            } elseif ($bestInvalidProposal !== null && $bestInvalidOptimized !== null) {
                // Preview blijft altijd beschikbaar: laat de beste onvolledige
                // kandidaat zien en verklaar daarna exact wat nog fout is.
                $proposal = $bestInvalidProposal;
                $optimized = $bestInvalidOptimized;
                $selectedCompressionLevel = $bestInvalidCompressionLevel;
            }
        }

        if ($proposal === []) {
            $proposal = $this->buildEmergencyLessonProposal(
                array_values($groups),
                array_values($rounds),
                $orderedModules,
            );
            $lessonStructureIssue = [
                'code' => 'GENERATION_NO_VALID_LESSON_SCHEDULE',
                'message' => 'Binnen de normale parallel- en sessieregels is nog geen volledig lesrooster gevonden. Dit is het dichtstbijzijnde voorbeeld.',
                'moduleKey' => null,
                'moduleLabel' => null,
                'startTime' => null,
                'endTime' => null,
                'groupLabels' => [],
                'requiredStaff' => null,
                'assignedStaff' => null,
                'eligibleStaffNames' => [],
            ];

            // Ook het noodvoorbeeld krijgt een personeelsanalyse.
            if ($staffingMode === 'with_staff' && $this->staffOptimizer !== null) {
                $optimized = $this->staffOptimizer->optimize(
                    $proposal,
                    $staffIds,
                    $preferGeoFortKe,
                    $cookStaffId,
                    $plannerModulePreferences,
                );
            }
        }
        $parallel = $this->parallelCounts($proposal);
        $warnings = [];
        foreach ($parallel as $key => $count) {
            [$startTime, $moduleKey] = explode('|', $key, 2);
            $maximum = $this->config->maxParallel($moduleKey);

            if ($count > $maximum) {
                $warnings[] = [
                    'code' => 'GENERATION_PARALLEL_LIMIT',
                    'message' => "HARD: {$moduleKey} staat {$count} keer parallel om {$startTime}; maximaal {$maximum} groepen zijn toegestaan.",
                ];
            }

            if ($count > 1) {
                $module = $this->config->moduleOption($moduleKey);
                $warnings[] = [
                    'code' => 'PARALLEL_LOCATION_REVIEW',
                    'message' => "{$module['label']} staat {$count} keer parallel om {$startTime}; controleer ruimte/capaciteit.",
                ];
            }
        }


        $fallbackSet = array_fill_keys(
            array_map('intval', $optimized['schoolFallbackSessionIndexes'] ?? []),
            true,
        );

        foreach ($proposal as $index => &$session) {
            $session['assignedStaffIds'] = array_values(array_unique(array_map(
                'intval',
                $optimized['assignments'][$index] ?? [],
            )));
            $session['schoolFallback'] = isset($fallbackSet[$index]);
            if ($this->config->schoolSupervisionAllowed((string) $session['moduleKey'])) {
                // Lesson-only deliberately selects no staff. With an optimizer, an empty
                // assignment is a valid School decision for these two modules, never implicit OPEN.
                $session['schoolFallback'] = $session['assignedStaffIds'] === []
                    && ($staffingMode === 'lesson_only' || $this->staffOptimizer !== null);
            }
        }
        unset($session);

        $hardIssues = $this->buildGenerationHardIssues(
            $proposal,
            $optimized,
            $staffIds,
            $cookMissing,
            $lessonStructureIssue,
            $warnings,
        );
        $canFinalize = $hardIssues === [];
        $canApply = true;

        // Parallel-limieten zijn hierboven al naar hardIssues vertaald.
        $warnings = array_values(array_filter(
            $warnings,
            static fn (array $warning): bool =>
                ($warning['code'] ?? null) !== 'GENERATION_PARALLEL_LIMIT',
        ));

        $hasVi = count(array_filter(
            $proposal,
            static fn (array $session): bool => $session['moduleKey'] === 'Voedsel-Innovatie',
        )) > 0;

        $cookOpen = $cookMissing;

        $staffing = array_merge(
            $this->staffingSummary($proposal, $rounds),
            [
                'mode' => $staffingMode,
                'preferGeoFortKe' => $preferGeoFortKe,
                'plannerModulePreferences' => $plannerModulePreferences,
                'selectedStaffCount' => (int) $optimized['selectedStaffCount'],
                'selectedPeopleCount' => count(array_unique(array_filter([
                    ...$staffIds,
                    $cookStaffId,
                ], static fn (mixed $value): bool => is_int($value) && $value > 0))),
                'unfilledRequiredAssignments' => (int) $optimized['unfilledRequiredAssignments'],
                'cookRequired' => $hasVi,
                'cookSelected' => $cookStaffId !== null,
                'cook' => $optimized['cook'],
                'targetWorkMinutes' => (int) $optimized['targetWorkMinutes'],
                'staff' => $optimized['staff'],
                'assignmentStatus' => $staffingMode === 'lesson_only'
                    ? 'lesson_only'
                    : ($canFinalize ? 'complete' : 'incomplete'),
                'message' => $staffingMode === 'lesson_only'
                    ? 'Alleen het lesrooster wordt gegenereerd. Personeel kan daarna via Handmatige correcties worden toegevoegd.'
                    : ($canFinalize
                        ? 'Het personeelsvoorstel is berekend. Controleer na opslaan het actuele rooster op het tabblad Controle.'
                        : 'Dit concept kan worden opgeslagen. Los de aandachtspunten op voordat het rooster definitief wordt gemaakt.'),
            ],
        );

        return [
            'planId' => $planId,
            'revision' => $context['revision'],
            'existingSessionCount' => count($this->sessions->findByPlanId($planId)),
            'rounds' => $rounds,
            'sessions' => $proposal,
            'warnings' => $warnings,
            'hardIssues' => $hardIssues,
            'canApply' => $canApply,
            'canFinalize' => $canFinalize,
            'diagnostics' => [
                'compressionLevel' => $selectedCompressionLevel,
                'candidateCount' => $candidateCount,
            ],
            'staffing' => $staffing,
            'templateNote' => $this->templates->note($context['program']),
        ];
    }

    /**
     * Backwards-compatible argument order: actingAdminId blijft argument 5.
     * @param list<array{label?:mixed,startTime?:mixed,endTime?:mixed}> $rounds
     * @param list<int> $staffIds
     * @param list<array{staffId:int,moduleKeys:list<string>}> $plannerModulePreferences
     * @return array{revision:int,sessionCount:int,unfilledRequiredAssignments:int}
     */
    public function apply(
        int $planId,
        int $expectedRevision,
        array $rounds,
        bool $replaceExisting,
        int $actingAdminId,
        array $staffIds = [],
        string $staffingMode = 'with_staff',
        bool $preferGeoFortKe = true,
        ?int $cookStaffId = null,
        array $plannerModulePreferences = [],
        ?int $expectedCatalogRevision = null,
    ): array {
        $proposal = $this->preview(
            $planId,
            $rounds,
            $staffIds,
            $staffingMode,
            $preferGeoFortKe,
            $cookStaffId,
            $plannerModulePreferences,
        );

        if (($proposal['staffing']['cookRequired'] ?? false) !== true) {
            $cookStaffId = null;
        }
        $preferGeoFortKe = (bool) ($proposal['staffing']['preferGeoFortKe'] ?? false);
        // Ook een onvolledig rooster mag als concept worden opgeslagen.
        // Finaliseren gebruikt uitsluitend RosterValidationService op opnieuw gelezen opgeslagen data.

        try {
            if (!$this->pdo->beginTransaction()) {
                throw new RuntimeException('Generatietransactie kon niet worden gestart.');
            }

            if ($this->staff !== null) {
                $catalogRevision = RosterStaffCatalogGuard::lock($this->pdo);
                RosterStaffCatalogGuard::expect($catalogRevision, $expectedCatalogRevision ?? $proposal['catalogRevision']);
                RosterStaffCatalogGuard::expect($catalogRevision, $proposal['catalogRevision']);
            }

            $plan = $this->plans->lockPlan($planId);
            if ($plan === null) {
                throw new RosterPlanException('PLAN_NOT_FOUND', 404, 'Het roosterplan bestaat niet.');
            }
            if ($plan['status'] !== 'concept') {
                throw new RosterPlanException('PLAN_NOT_EDITABLE', 409, 'Alleen een conceptrooster kan automatisch worden ingevuld.');
            }
            if ($plan['revision'] !== $expectedRevision) {
                throw new RosterPlanException(
                    'ROSTER_REVISION_CONFLICT',
                    409,
                    'Het rooster is ondertussen gewijzigd. Maak eerst een nieuw voorstel.',
                );
            }

            $existingCount = count($this->sessions->findByPlanId($planId));
            if ($existingCount > 0 && !$replaceExisting) {
                throw new RosterPlanException(
                    'EXISTING_SESSIONS_REQUIRE_REPLACE',
                    409,
                    'Dit rooster bevat al sessies. Bevestig expliciet dat het voorstel deze sessies mag vervangen.',
                );
            }

            $mode = (string) $proposal['staffing']['mode'];
            $selection = $mode === 'with_staff' ? $staffIds : [];
            if ($mode === 'with_staff' && $cookStaffId !== null) {
                $selection[] = $cookStaffId;
            }

            $this->staff?->replacePlanSelection($planId, $selection);
            $this->staff?->savePlanSettings(
                $planId,
                $mode,
                $preferGeoFortKe,
                $mode === 'with_staff' ? $cookStaffId : null,
            );
            $this->staff?->replacePlanModulePreferences(
                $planId,
                $mode === 'with_staff'
                    ? ($proposal['staffing']['plannerModulePreferences'] ?? [])
                    : [],
            );

            $this->sessions->deleteByPlanId($planId);

            foreach ($proposal['sessions'] as $session) {
                $sessionId = $this->sessions->insertActivity(
                    $planId,
                    (string) $session['moduleKey'],
                    (string) $session['startTime'],
                    (string) $session['endTime'],
                    is_string($session['location'] ?? null) ? $session['location'] : null,
                    $actingAdminId,
                );
                $this->sessions->replaceGroups($sessionId, array_map('intval', $session['groupIds']));
                $this->staff?->replaceSessionAssignments(
                    $sessionId,
                    $mode === 'with_staff'
                        ? array_map('intval', $session['assignedStaffIds'] ?? [])
                        : [],
                    'auto',
                );
                if ($this->config->schoolSupervisionAllowed((string) $session['moduleKey'])) {
                    $staffIdsForSession = $mode === 'with_staff' ? array_map('intval', $session['assignedStaffIds'] ?? []) : [];
                    $kind = $staffIdsForSession !== [] ? 'staff' : (($session['schoolFallback'] ?? false) ? 'school' : 'open');
                    $byGroup = [];
                    foreach (array_map('intval', $session['groupIds']) as $groupId) {
                        $this->sessions->setGroupSupervision($sessionId, $groupId, $kind);
                        $byGroup[$groupId] = $staffIdsForSession;
                    }
                    $this->staff?->replaceSessionGroupAssignments($sessionId, $byGroup, 'auto');
                }
            }

            $revision = $this->plans->advanceRevision($planId, $actingAdminId);

            if (!$this->pdo->commit()) {
                throw new RuntimeException('Generatietransactie kon niet worden vastgelegd.');
            }

            return [
                'revision' => $revision,
                'sessionCount' => count($proposal['sessions']),
                'unfilledRequiredAssignments' => (int) $proposal['staffing']['unfilledRequiredAssignments'],
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

            throw new RuntimeException('Automatisch rooster kon niet worden toegepast.', 0, $exception);
        }
    }

    /** @return array{booking:\GeoFort\Booking\Stored\StoredBooking,groups:list<array<string,mixed>>,revision:int} */
    private function context(int $planId): array
    {
        $plan = $this->plans->findById($planId);
        if ($plan === null) {
            throw new RosterPlanException('PLAN_NOT_FOUND', 404, 'Het roosterplan bestaat niet.');
        }

        $bookingId = is_numeric($plan['booking_id'] ?? null)
            ? (int) $plan['booking_id']
            : null;
        $planningMode = is_string($plan['planning_mode'] ?? null)
            ? (string) $plan['planning_mode']
            : ($bookingId === null ? 'custom' : 'booking_locked');

        if ($planningMode === 'custom') {
            $manualContext = $this->plans->manualContextForPlan($planId);
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
                    'Sla eerst het maatwerkprogramma op voordat je een voorstel genereert.',
                );
            }

            return [
                'planningMode' => 'custom',
                'program' => $program,
                'modules' => array_map(
                    fn (string $moduleKey): array => $this->config->moduleOption($moduleKey),
                    $moduleKeys,
                ),
                'groups' => is_array($plan['groups'] ?? null)
                    ? array_values($plan['groups'])
                    : [],
                'revision' => (int) $plan['revision'],
            ];
        }

        if ($planningMode !== 'booking_locked' || $bookingId === null) {
            throw new RosterPlanException(
                'INVALID_PLANNING_MODE',
                409,
                'Het rooster heeft geen geldige planningsautoriteit.',
            );
        }

        $booking = $this->bookings->findById($bookingId);
        if ($booking === null) {
            throw new RosterPlanException('BOOKING_NOT_FOUND', 404, 'De gekoppelde aanvraag bestaat niet.');
        }

        return [
            'planningMode' => 'booking_locked',
            'program' => $booking->program,
            'modules' => $this->config->moduleOptions(
                $booking->schoolSector,
                $booking->program,
                $booking->choiceModuleKey,
            ),
            'groups' => is_array($plan['groups'] ?? null)
                ? array_values($plan['groups'])
                : [],
            'revision' => (int) $plan['revision'],
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

    private function normalizeStaffingMode(string $mode): string
    {
        if (!in_array($mode, ['lesson_only', 'with_staff'], true)) {
            throw new RosterPlanException('INVALID_STAFFING_MODE', 422, 'Onbekende roosterpersoneelsmodus.');
        }

        return $mode;
    }

    /** @param list<int> $staffIds */
    private function validateStaffSelection(array $staffIds, ?int $cookStaffId, string $mode): void
    {
        if ($mode === 'lesson_only' || $this->staff === null) {
            return;
        }

        foreach (array_values(array_unique(array_map('intval', $staffIds))) as $staffId) {
            $member = $this->staff->memberById($staffId);
            if ($member === null || !$member['isActive'] || !$member['canGuide']) {
                throw new RosterPlanException(
                    'INVALID_GUIDE_SELECTION',
                    422,
                    'Een geselecteerde medewerker is niet actief of kan niet als begeleider worden ingezet.',
                );
            }
        }

        if ($cookStaffId !== null) {
            $cook = $this->staff->memberById($cookStaffId);
            if ($cook === null || !$cook['isActive'] || !$cook['canCook']) {
                throw new RosterPlanException(
                    'INVALID_COOK_SELECTION',
                    422,
                    'De geselecteerde kok is niet beschikbaar voor de kokrol.',
                );
            }
        }
    }

    /**
     * @param list<array{staffId:mixed,moduleKeys:mixed}> $preferences
     * @param list<int> $staffIds
     * @param list<array<string,mixed>> $modules
     * @return list<array{staffId:int,moduleKeys:list<string>}>
     */
    private function normalizePlannerModulePreferences(
        array $preferences,
        array $staffIds,
        array $modules,
        string $staffingMode,
    ): array {
        if ($staffingMode === 'lesson_only' || $preferences === []) {
            return [];
        }

        if ($this->staff === null) {
            throw new RosterPlanException(
                'PLANNER_PREFERENCES_UNAVAILABLE',
                422,
                'Dagvoorkeuren voor begeleiders zijn momenteel niet beschikbaar.',
            );
        }

        $selected = array_fill_keys(array_map('intval', $staffIds), true);
        $programModules = array_fill_keys(
            array_map('strval', array_column($modules, 'key')),
            true,
        );
        $members = [];
        foreach ($this->staff->membersByIds($staffIds) as $member) {
            $members[(int) $member['id']] = $member;
        }

        $normalized = [];
        $seenStaff = [];

        foreach ($preferences as $entry) {
            if (!is_array($entry)) {
                throw new RosterPlanException(
                    'INVALID_PLANNER_MODULE_PREFERENCE',
                    422,
                    'Ongeldige dagvoorkeur voor een begeleider.',
                );
            }

            $staffId = $entry['staffId'] ?? null;
            $moduleKeys = $entry['moduleKeys'] ?? null;

            if (
                !is_int($staffId)
                || $staffId <= 0
                || !isset($selected[$staffId])
                || isset($seenStaff[$staffId])
                || !is_array($moduleKeys)
                || count($moduleKeys) > 3
            ) {
                throw new RosterPlanException(
                    'INVALID_PLANNER_MODULE_PREFERENCE',
                    422,
                    'Dagvoorkeuren moeten bij een geselecteerde begeleider horen en maximaal drie modules bevatten.',
                );
            }

            $member = $members[$staffId] ?? null;
            if (!is_array($member)) {
                throw new RosterPlanException(
                    'INVALID_PLANNER_MODULE_PREFERENCE',
                    422,
                    'De gekozen begeleider bestaat niet in het personeelsbestand.',
                );
            }

            $eligible = array_fill_keys(
                array_map('strval', array_column($member['preferences'], 'moduleKey')),
                true,
            );
            $keys = [];

            foreach ($moduleKeys as $moduleKey) {
                if (
                    !is_string($moduleKey)
                    || trim($moduleKey) === ''
                    || !isset($programModules[$moduleKey])
                    || !isset($eligible[$moduleKey])
                ) {
                    throw new RosterPlanException(
                        'INVALID_PLANNER_MODULE_PREFERENCE',
                        422,
                        'Een dagvoorkeur bevat een module die deze begeleider vandaag niet kan geven.',
                    );
                }

                if (!in_array($moduleKey, $keys, true)) {
                    $keys[] = $moduleKey;
                }
            }

            if (count($keys) > 3) {
                throw new RosterPlanException(
                    'INVALID_PLANNER_MODULE_PREFERENCE',
                    422,
                    'Per begeleider zijn maximaal drie dagvoorkeuren toegestaan.',
                );
            }

            $seenStaff[$staffId] = true;
            if ($keys !== []) {
                $normalized[] = [
                    'staffId' => $staffId,
                    'moduleKeys' => $keys,
                ];
            }
        }

        return $normalized;
    }

    /**
     * @param list<array{label?:mixed,startTime?:mixed,endTime?:mixed}> $rounds
     * @return list<array{label:string,startTime:string,endTime:string}>
     */
    private function validateRounds(string $program, array $rounds, int $moduleCount): array
    {
        if (count($rounds) !== $moduleCount || $moduleCount < 1) {
            throw new RosterPlanException(
                'INVALID_ROUND_COUNT',
                422,
                "Verwacht {$moduleCount} rondes voor dit programma.",
            );
        }

        $programConfig = BookingProgramConfig::PROGRAMS[$program] ?? null;
        if (!is_array($programConfig)) {
            throw new RosterPlanException('PROGRAM_NOT_SUPPORTED', 422, 'Onbekend programma.');
        }

        $programStart = (string) $programConfig['beginTijd'];
        $programEnd = (string) $programConfig['eindTijd'];
        $normalized = [];
        $previousEnd = null;

        foreach (array_values($rounds) as $index => $round) {
            if (!is_array($round)) {
                throw new RosterPlanException('INVALID_ROUND', 422, 'Ongeldige ronde-invoer.');
            }

            $start = $this->time($round['startTime'] ?? null);
            $end = $this->time($round['endTime'] ?? null);
            $label = is_string($round['label'] ?? null) && trim($round['label']) !== ''
                ? trim($round['label'])
                : 'Ronde ' . ($index + 1);

            if ($this->minutes($end) <= $this->minutes($start)) {
                throw new RosterPlanException('INVALID_ROUND_TIME', 422, "{$label}: eindtijd moet na starttijd liggen.");
            }
            if (
                $this->minutes($start) < $this->minutes($programStart)
                || $this->minutes($end) > $this->minutes($programEnd)
            ) {
                throw new RosterPlanException(
                    'ROUND_OUTSIDE_PROGRAM',
                    422,
                    "{$label} moet tussen {$programStart} en {$programEnd} vallen.",
                );
            }
            if ($previousEnd !== null && $this->minutes($start) < $this->minutes($previousEnd)) {
                throw new RosterPlanException('ROUND_OVERLAP', 422, 'Rondes mogen elkaar niet overlappen.');
            }

            $normalized[] = [
                'label' => $label,
                'startTime' => $start,
                'endTime' => $end,
            ];
            $previousEnd = $end;
        }

        return $normalized;
    }

    /**
     * Bouw meerdere geldige lesrooster-kandidaten.
     *
     * Huidige 5-modulepolicy:
     * - Voedsel Innovatie gaat zo vroeg mogelijk;
     * - per VI-sessie maximaal twee opeenvolgende groepen;
     * - groep 1+2, 3+4, 5+6, ... delen dezelfde VI-sessie;
     * - overige modules blijven afzonderlijke sessies;
     * - parallelgrenzen tellen groepen per module/tijdvak, niet sessierecords.
     *
     * @param list<array<string,mixed>> $groups
     * @param list<array{label:string,startTime:string,endTime:string}> $rounds
     * @param list<array<string,mixed>> $modules
     * @return list<list<array<string,mixed>>>
     */
    private function buildLessonProposalCandidates(
        array $groups,
        array $rounds,
        array $modules,
        int $limit,
        int $compressionLevel = 1,
    ): array {
        if ($groups === [] || $rounds === [] || $modules === []) {
            return [];
        }

        $moduleByKey = [];
        foreach ($modules as $module) {
            $moduleByKey[(string) $module['key']] = $module;
        }

        $viKey = 'Voedsel-Innovatie';
        if (!isset($moduleByKey[$viKey])) {
            // Programmas zonder VI gebruiken dezelfde compressieregels als de
            // volledige generator. De lesvolgorde blijft cyclisch; alleen
            // groepen op dezelfde module en hetzelfde tijdvak worden waar
            // toegestaan samengevoegd.
            $proposal = [];

            foreach ($rounds as $roundIndex => $round) {
                $moduleGroups = [];

                foreach ($groups as $groupIndex => $group) {
                    $module = $modules[($groupIndex + $roundIndex) % count($modules)];
                    $moduleKey = (string) $module['key'];

                    $moduleGroups[$moduleKey] ??= [
                        'module' => $module,
                        'groups' => [],
                    ];
                    $moduleGroups[$moduleKey]['groups'][] = [
                        'id' => (int) $group['id'],
                        'label' => (string) $group['label'],
                    ];
                }

                foreach ($moduleGroups as $moduleKey => $bucket) {
                    $maximumGroups = $this->config->effectiveMaxGroupsForCompressionLevel(
                        (string) $moduleKey,
                        $compressionLevel,
                    );

                    foreach (array_chunk($bucket['groups'], $maximumGroups) as $chunk) {
                        $proposal[] = $this->proposalSession(
                            $bucket['module'],
                            $round,
                            array_map(
                                static fn (array $group): int => (int) $group['id'],
                                $chunk,
                            ),
                            array_map(
                                static fn (array $group): string => (string) $group['label'],
                                $chunk,
                            ),
                        );
                    }
                }
            }

            return [$proposal];
        }

        // In de huidige vijfmodulevariant zetten we maximaal Ã©Ã©n VI-paar per
        // tijdvak. Met vijf rondes ondersteunt dit maximaal tien groepen.
        if (count($groups) > count($rounds) * 2) {
            throw new RosterPlanException(
                'GENERATION_VI_CAPACITY',
                422,
                'Voor dit aantal groepen zijn te weinig tijdvakken beschikbaar om Voedsel Innovatie in tweetallen te plannen.',
            );
        }

        $otherModules = array_values(array_filter(
            $modules,
            static fn (array $module): bool => (string) $module['key'] !== $viKey,
        ));
        $permutations = $this->modulePermutations($otherModules);

        $assignment = array_fill(
            0,
            count($groups),
            array_fill(0, count($rounds), null),
        );

        // VI: 1+2 in ronde 1, 3+4 in ronde 2, enzovoort.
        foreach ($groups as $groupIndex => $_group) {
            $viRound = intdiv($groupIndex, 2);
            $assignment[$groupIndex][$viRound] = $viKey;
        }

        $parallelCounts = [];
        $matrices = [];
        $this->collectLessonAssignmentMatrices(
            groupIndex: 0,
            groups: $groups,
            rounds: $rounds,
            otherModules: $otherModules,
            permutations: $permutations,
            assignment: $assignment,
            parallelCounts: $parallelCounts,
            matrices: $matrices,
            limit: max(1, $limit),
            compressionLevel: $compressionLevel,
        );

        $proposals = [];
        foreach ($matrices as $matrix) {
            $proposal = [];

            foreach ($rounds as $roundIndex => $round) {
                $viGroupIds = [];
                $viGroupLabels = [];

                foreach ($groups as $groupIndex => $group) {
                    if (($matrix[$groupIndex][$roundIndex] ?? null) === $viKey) {
                        $viGroupIds[] = (int) $group['id'];
                        $viGroupLabels[] = (string) $group['label'];
                    }
                }

                if ($viGroupIds !== []) {
                    $proposal[] = $this->proposalSession(
                        $moduleByKey[$viKey],
                        $round,
                        $viGroupIds,
                        $viGroupLabels,
                    );
                }

                $moduleGroups = [];

                foreach ($groups as $groupIndex => $group) {
                    $moduleKey = $matrix[$groupIndex][$roundIndex] ?? null;
                    if (!is_string($moduleKey) || $moduleKey === $viKey) {
                        continue;
                    }

                    $moduleGroups[$moduleKey] ??= [];
                    $moduleGroups[$moduleKey][] = [
                        'id' => (int) $group['id'],
                        'label' => (string) $group['label'],
                    ];
                }

                foreach ($moduleGroups as $moduleKey => $assignedGroups) {
                    $maximumGroups = $this->config->effectiveMaxGroupsForCompressionLevel(
                        (string) $moduleKey,
                        $compressionLevel,
                    );

                    foreach (array_chunk($assignedGroups, $maximumGroups) as $chunk) {
                        $proposal[] = $this->proposalSession(
                            $moduleByKey[(string) $moduleKey],
                            $round,
                            array_map(
                                static fn (array $group): int => (int) $group['id'],
                                $chunk,
                            ),
                            array_map(
                                static fn (array $group): string => (string) $group['label'],
                                $chunk,
                            ),
                        );
                    }
                }
            }

            $proposals[] = $proposal;
        }

        return $proposals;
    }

    /**
     * @param list<array<string,mixed>> $groups
     * @param list<array<string,string>> $rounds
     * @param list<array<string,mixed>> $otherModules
     * @param list<list<array<string,mixed>>> $permutations
     * @param array<int,array<int,string|null>> $assignment
     * @param array<int,array<string,int>> $parallelCounts
     * @param list<array<int,array<int,string|null>>> $matrices
     */
    private function collectLessonAssignmentMatrices(
        int $groupIndex,
        array $groups,
        array $rounds,
        array $otherModules,
        array $permutations,
        array &$assignment,
        array &$parallelCounts,
        array &$matrices,
        int $limit,
        int $compressionLevel,
    ): void {
        if (count($matrices) >= $limit) {
            return;
        }

        if ($groupIndex >= count($groups)) {
            $matrices[] = $assignment;
            return;
        }

        $viRound = intdiv($groupIndex, 2);
        $remainingRounds = array_values(array_filter(
            array_keys($rounds),
            static fn (int $roundIndex): bool => $roundIndex !== $viRound,
        ));

        foreach ($permutations as $permutation) {
            $valid = true;

            foreach ($remainingRounds as $position => $roundIndex) {
                $module = $permutation[$position] ?? null;
                if (!is_array($module)) {
                    $valid = false;
                    break;
                }

                $moduleKey = (string) $module['key'];
                $current = (int) ($parallelCounts[$roundIndex][$moduleKey] ?? 0);

                // HARD: maxParallel is modulecapaciteit in AANTAL GROEPEN,
                // niet in sessierecords.
                $groupCapacity = $this->config->maxParallel($moduleKey);

                if ($current >= $groupCapacity) {
                    $valid = false;
                    break;
                }
            }

            if (!$valid) {
                continue;
            }

            foreach ($remainingRounds as $position => $roundIndex) {
                $moduleKey = (string) $permutation[$position]['key'];
                $assignment[$groupIndex][$roundIndex] = $moduleKey;
                $parallelCounts[$roundIndex][$moduleKey]
                    = (int) ($parallelCounts[$roundIndex][$moduleKey] ?? 0) + 1;
            }

            $this->collectLessonAssignmentMatrices(
                $groupIndex + 1,
                $groups,
                $rounds,
                $otherModules,
                $permutations,
                $assignment,
                $parallelCounts,
                $matrices,
                $limit,
                $compressionLevel,
            );

            foreach ($remainingRounds as $position => $roundIndex) {
                $moduleKey = (string) $permutation[$position]['key'];
                $parallelCounts[$roundIndex][$moduleKey]--;
                $assignment[$groupIndex][$roundIndex] = null;
            }

            if (count($matrices) >= $limit) {
                return;
            }
        }
    }

    /**
     * Altijd een zichtbaar voorbeeld leveren wanneer de normale zoekruimte
     * geen structureel geldige kandidaat oplevert.
     *
     * @param list<array<string,mixed>> $groups
     * @param list<array<string,string>> $rounds
     * @param list<array<string,mixed>> $modules
     * @return list<array<string,mixed>>
     */
    private function buildEmergencyLessonProposal(
        array $groups,
        array $rounds,
        array $modules,
    ): array {
        $proposal = [];

        foreach ($groups as $groupIndex => $group) {
            foreach ($rounds as $roundIndex => $round) {
                $module = $modules[($groupIndex + $roundIndex) % count($modules)];

                $proposal[] = $this->proposalSession(
                    $module,
                    $round,
                    [(int) $group['id']],
                    [(string) $group['label']],
                );
            }
        }

        return $proposal;
    }

    /**
     * @param list<array<string,mixed>> $items
     * @return list<list<array<string,mixed>>>
     */
    private function modulePermutations(array $items): array
    {
        if (count($items) <= 1) {
            return [$items];
        }

        $result = [];

        foreach ($items as $index => $item) {
            $remaining = $items;
            array_splice($remaining, $index, 1);

            foreach ($this->modulePermutations(array_values($remaining)) as $tail) {
                $result[] = [$item, ...$tail];
            }
        }

        return $result;
    }

    /**
     * @param array<string,mixed> $module
     * @param array{label:string,startTime:string,endTime:string} $round
     * @param list<int> $groupIds
     * @param list<string> $groupLabels
     * @return array<string,mixed>
     */
    private function proposalSession(
        array $module,
        array $round,
        array $groupIds,
        array $groupLabels,
    ): array {
        $moduleKey = (string) $module['key'];
        $programModuleCount = max(1, (int) ($module['programModuleCount'] ?? 5));
        $schoolSupervisionAllowed = $this->config->schoolSupervisionAllowedForModuleCount(
            $moduleKey,
            $programModuleCount,
        );

        return [
            'moduleKey' => $moduleKey,
            'moduleLabel' => (string) $module['label'],
            'color' => (string) $module['color'],
            'startTime' => $round['startTime'],
            'endTime' => $round['endTime'],
            'roundLabel' => $round['label'],
            'location' => $module['defaultLocation'],
            'groupIds' => $groupIds,
            'groupLabels' => $groupLabels,
            'minimumGeoFortStaff' => $this->config->requiredGeoFortGuidesForSession(
                $moduleKey,
                count($groupIds),
                $schoolSupervisionAllowed,
            ),
            'schoolSupervisionAllowed' => $schoolSupervisionAllowed,
        ];
    }

    /** @param list<array<string,mixed>> $proposal @return array<string,int> */
    private function parallelCounts(array $proposal): array
    {
        $parallel = [];

        foreach ($proposal as $session) {
            $key = (string) $session['startTime'] . '|' . (string) $session['moduleKey'];
            $groupCount = max(1, count($session['groupIds'] ?? []));
            $parallel[$key] = ($parallel[$key] ?? 0) + $groupCount;
        }

        return $parallel;
    }

    /**
     * HARD preflight: iedere module die GeoFort-begeleiding vereist moet
     * door minstens Ã©Ã©n geselecteerde begeleider gegeven kunnen worden.
     *
     * @param list<array<string,mixed>> $modules
     * @param list<int> $staffIds
     */
    private function assertRequiredModuleSkillCoverage(array $modules, array $staffIds): void
    {
        if ($this->staff === null) {
            return;
        }

        $members = array_values(array_filter(
            $this->staff->membersByIds($staffIds),
            static fn (array $member): bool => $member['isActive'] && $member['canGuide'],
        ));

        foreach ($modules as $module) {
            $moduleKey = (string) $module['key'];
            if ($this->config->minimumGeoFortStaff($moduleKey) === 0) {
                continue;
            }

            $covered = count(array_filter(
                $members,
                static fn (array $member): bool => in_array(
                    $moduleKey,
                    array_column($member['preferences'], 'moduleKey'),
                    true,
                ),
            )) > 0;

            if (!$covered) {
                $label = (string) ($module['label'] ?? $moduleKey);
                throw new RosterPlanException(
                    'STAFF_MODULE_COVERAGE_MISSING',
                    422,
                    "De geselecteerde medewerkers kunnen {$label} niet bemannen.",
                );
            }
        }
    }

    /**
     * Na harde bezetting: eerst KE/VS zoveel mogelijk door GeoFort,
     * daarna de bestaande soft kosten voor tussenuren, dagvoorkeuren,
     * werkverdeling en modulecontinuïteit.
     *
     * @param array<string,mixed> $optimized
     */
    private function staffedCandidateScore(
        array $optimized,
        array $proposal,
    ): array {
        $staff = is_array($optimized['staff'] ?? null) ? $optimized['staff'] : [];

        $gapPenalty = 0;
        foreach ($staff as $member) {
            $gaps = (int) ($member['gaps'] ?? 0);
            if ($gaps <= 0) {
                continue;
            }

            // Tussenuren blijven ongewenst. Bij vrijwilligers zijn ze echter
            // nadrukkelijk toegestaan wanneer dit nodig is om het rooster rond
            // te krijgen; daarom is hun penalty duidelijk lager.
            $weight = ($member['employmentType'] ?? 'paid') === 'volunteer'
                ? 100_000
                : 1_000_000;
            $gapPenalty += $gaps * $weight;
        }

        $schoolFallbacks = count($optimized['schoolFallbackSessionIndexes'] ?? []);

        $minutes = array_map(
            static fn (array $member): int => (int) ($member['workMinutes'] ?? 0),
            $staff,
        );
        $spread = $minutes === [] ? 0 : max($minutes) - min($minutes);

        $preferencePenalty = 0;
        foreach ($staff as $member) {
            $average = $member['averagePreferenceRank'] ?? null;
            if (is_numeric($average)) {
                $preferencePenalty += (int) round(((float) $average) * 10);
            }
        }

        $plannerPreferencePenalty =
            (int) ($optimized['plannerPreferencePenalty'] ?? 0) * 15;

        $compressionPenalty = 0;

        foreach ($proposal as $session) {
            if (count($session['groupIds'] ?? []) < 2) {
                continue;
            }

            $priority = $this->config->sharedGuidePriority(
                (string) ($session['moduleKey'] ?? ''),
            );

            $compressionPenalty += match ($priority) {
                2 => 20_000,
                3 => 60_000,
                4 => 90_000,
                default => 0,
            };
        }

        return [$schoolFallbacks, $gapPenalty
            + $plannerPreferencePenalty
            + $compressionPenalty
            + ($spread * 1_000)
            + $preferencePenalty];
    }

    /**
     * @param list<array<string,mixed>> $proposal
     * @param array<string,mixed> $optimized
     * @param list<int> $staffIds
     * @param array<string,mixed>|null $lessonStructureIssue
     * @param list<array{code:string,message:string}> $warnings
     * @return list<array<string,mixed>>
     */
    private function buildGenerationHardIssues(
        array $proposal,
        array $optimized,
        array $staffIds,
        bool $cookMissing,
        ?array $lessonStructureIssue,
        array $warnings,
    ): array {
        $issues = [];

        if ($lessonStructureIssue !== null) {
            $issues[] = $lessonStructureIssue;
        }

        foreach ($warnings as $warning) {
            if (($warning['code'] ?? null) !== 'GENERATION_PARALLEL_LIMIT') {
                continue;
            }

            $issues[] = [
                'code' => 'GENERATION_PARALLEL_LIMIT',
                'message' => (string) $warning['message'],
                'moduleKey' => null,
                'moduleLabel' => null,
                'startTime' => null,
                'endTime' => null,
                'groupLabels' => [],
                'requiredStaff' => null,
                'assignedStaff' => null,
                'eligibleStaffNames' => [],
            ];
        }

        if ($cookMissing) {
            $issues[] = [
                'code' => 'COOK_REQUIRED',
                'message' => 'Voedsel Innovatie vereist een kok. Kies een actieve medewerker met de bevoegdheid koken.',
                'moduleKey' => 'Voedsel-Innovatie',
                'moduleLabel' => 'Voedsel Innovatie',
                'startTime' => null,
                'endTime' => null,
                'groupLabels' => [],
                'requiredStaff' => 1,
                'assignedStaff' => 0,
                'eligibleStaffNames' => [],
            ];
        }

        $unfilledIndexes = array_values(array_unique(array_map(
            'intval',
            $optimized['unfilledRequiredSessionIndexes'] ?? [],
        )));

        foreach ($unfilledIndexes as $sessionIndex) {
            $session = $proposal[$sessionIndex] ?? null;
            if (!is_array($session)) {
                continue;
            }

            $moduleKey = (string) $session['moduleKey'];
            $eligibleNames = $this->eligibleStaffNamesForModule(
                $moduleKey,
                $staffIds,
            );
            $required = (int) ($session['minimumGeoFortStaff'] ?? 0);
            $assigned = count($optimized['assignments'][$sessionIndex] ?? []);
            $groupLabels = array_values(array_map(
                'strval',
                $session['groupLabels'] ?? [],
            ));

            $reason = $eligibleNames === []
                ? 'Geen geselecteerde medewerker kan deze module geven.'
                : 'De geschikte geselecteerde medewerkers zijn in dit tijdvak al nodig voor andere harde roosterrollen.';

            $issues[] = [
                'code' => 'REQUIRED_STAFF_UNFILLED',
                'message' => sprintf(
                    '%s %s-%s %s: nodig %d, ingevuld %d. %s',
                    (string) $session['moduleLabel'],
                    (string) $session['startTime'],
                    (string) $session['endTime'],
                    $groupLabels === [] ? '' : implode(' ', array_map(
                        static fn (string $label): string => '(' . $label . ')',
                        $groupLabels,
                    )),
                    $required,
                    $assigned,
                    $reason,
                ),
                'moduleKey' => $moduleKey,
                'moduleLabel' => (string) $session['moduleLabel'],
                'startTime' => (string) $session['startTime'],
                'endTime' => (string) $session['endTime'],
                'groupLabels' => $groupLabels,
                'requiredStaff' => $required,
                'assignedStaff' => $assigned,
                'eligibleStaffNames' => $eligibleNames,
            ];
        }

        return $issues;
    }

    /** @param list<int> $staffIds @return list<string> */
    private function eligibleStaffNamesForModule(
        string $moduleKey,
        array $staffIds,
    ): array {
        if ($this->staff === null || $staffIds === []) {
            return [];
        }

        $names = [];

        foreach ($this->staff->membersByIds($staffIds) as $member) {
            if (!$member['isActive'] || !$member['canGuide']) {
                continue;
            }

            if (!in_array(
                $moduleKey,
                array_column($member['preferences'], 'moduleKey'),
                true,
            )) {
                continue;
            }

            $names[] = (string) $member['name'];
        }

        sort($names, SORT_NATURAL | SORT_FLAG_CASE);

        return $names;
    }

    /** @param list<array<string,mixed>> $modules @return list<array<string,mixed>> */
    private function generationModuleOrder(array $modules): array
    {
        usort($modules, static function (array $left, array $right): int {
            if (($left['key'] ?? null) === 'Voedsel-Innovatie') return -1;
            if (($right['key'] ?? null) === 'Voedsel-Innovatie') return 1;
            return 0;
        });

        return array_values($modules);
    }

    /** @param list<array<string,mixed>> $proposal @param list<array<string,string>> $rounds */
    private function staffingSummary(array $proposal, array $rounds): array
    {
        $minimumSimultaneous = 0;
        $preferredSimultaneous = 0;

        foreach ($rounds as $round) {
            $required = 0;
            $preferred = 0;

            foreach ($proposal as $session) {
                if ($session['startTime'] !== $round['startTime']) {
                    continue;
                }

                $minimum = (int) $session['minimumGeoFortStaff'];
                $required += $minimum;
                $preferred += $minimum;

                if (
                    $minimum === 0
                    && $this->config->schoolSupervisionAllowed((string) $session['moduleKey'])
                    && $session['schoolSupervisionAllowed']
                ) {
                    $preferred++;
                }
            }

            $minimumSimultaneous = max($minimumSimultaneous, $required);
            $preferredSimultaneous = max($preferredSimultaneous, $preferred);
        }

        return [
            'minimumSimultaneousGeoFortStaff' => $minimumSimultaneous,
            'preferredSimultaneousGeoFortStaff' => $preferredSimultaneous,
            'schoolSupervisionEligibleSessions' => count(array_filter(
                $proposal,
                static fn (array $session): bool => (bool) $session['schoolSupervisionAllowed'],
            )),
        ];
    }

    private function time(mixed $value): string
    {
        if (!is_string($value) || preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', trim($value)) !== 1) {
            throw new RosterPlanException('INVALID_TIME', 422, 'Gebruik tijden in HH:MM.');
        }
        return trim($value);
    }

    private function minutes(string $time): int
    {
        [$hour, $minute] = array_map('intval', explode(':', $time));
        return ($hour * 60) + $minute;
    }
}

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
     * @return array<string,mixed>
     */
    public function preview(
        int $planId,
        array $rounds,
        array $staffIds = [],
        string $staffingMode = 'with_staff',
        bool $preferGeoFortKe = true,
        ?int $cookStaffId = null,
    ): array {
        $context = $this->context($planId);
        $booking = $context['booking'];
        $groups = $context['groups'];

        $modules = $this->config->moduleOptions(
            $booking->schoolSector,
            $booking->program,
            $booking->choiceModuleKey,
        );
        $rounds = $this->validateRounds($booking->program, $rounds, count($modules));

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

        $this->validateStaffSelection($staffIds, $cookStaffId, $staffingMode);

        $orderedModules = $this->generationModuleOrder($modules);
        $proposal = [];
        $parallel = [];

        foreach (array_values($groups) as $groupIndex => $group) {
            foreach (array_values($rounds) as $roundIndex => $round) {
                $module = $orderedModules[($groupIndex + $roundIndex) % count($orderedModules)];
                $parallelKey = $round['startTime'] . '|' . $module['key'];
                $parallel[$parallelKey] = ($parallel[$parallelKey] ?? 0) + 1;

                $proposal[] = [
                    'moduleKey' => $module['key'],
                    'moduleLabel' => $module['label'],
                    'color' => $module['color'],
                    'startTime' => $round['startTime'],
                    'endTime' => $round['endTime'],
                    'roundLabel' => $round['label'],
                    'location' => $module['defaultLocation'],
                    'groupIds' => [(int) $group['id']],
                    'groupLabels' => [(string) $group['label']],
                    'minimumGeoFortStaff' => $this->config->minimumGeoFortStaff($module['key']),
                    'schoolSupervisionAllowed' => $this->config->schoolSupervisionAllowed($module['key']),
                ];
            }
        }

        $warnings = [];
        foreach ($parallel as $key => $count) {
            [$startTime, $moduleKey] = explode('|', $key, 2);
            $maximum = $this->config->maxParallel($moduleKey);

            if ($count > $maximum) {
                throw new RosterPlanException(
                    'GENERATION_PARALLEL_LIMIT',
                    422,
                    "De generator komt voor {$moduleKey} om {$startTime} boven de parallelgrens van {$maximum}.",
                );
            }

            if ($count > 1) {
                $module = $this->config->moduleOption($moduleKey);
                $warnings[] = [
                    'code' => 'PARALLEL_LOCATION_REVIEW',
                    'message' => "{$module['label']} staat {$count} keer parallel om {$startTime}; controleer ruimte/capaciteit.",
                ];
            }
        }

        $optimized = [
            'assignments' => [],
            'unfilledRequiredAssignments' => 0,
            'schoolFallbackSessionIndexes' => [],
            'selectedStaffCount' => 0,
            'targetWorkMinutes' => 0,
            'staff' => [],
            'cook' => null,
        ];

        if ($staffingMode === 'with_staff' && $this->staffOptimizer !== null) {
            $optimized = $this->staffOptimizer->optimize(
                $proposal,
                $staffIds,
                $preferGeoFortKe,
                $cookStaffId,
            );
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
        }
        unset($session);

        $hasVi = count(array_filter(
            $proposal,
            static fn (array $session): bool => $session['moduleKey'] === 'Voedsel-Innovatie',
        )) > 0;

        $cookOpen = $staffingMode === 'with_staff' && $hasVi && $cookStaffId === null;
        if ($cookOpen) {
            $warnings[] = [
                'code' => 'OPEN_COOK',
                'message' => 'Voedsel Innovatie heeft nog geen kok. Het concept mag worden opgeslagen, maar controle/bevestiging vereist een kok.',
            ];
        }

        if ($staffingMode === 'with_staff' && (int) $optimized['unfilledRequiredAssignments'] > 0) {
            $warnings[] = [
                'code' => 'OPEN_REQUIRED_STAFF',
                'message' => 'Niet alle verplichte GeoFort-begeleiding kon worden ingevuld.',
            ];
        }

        $staffing = array_merge(
            $this->staffingSummary($proposal, $rounds, $preferGeoFortKe),
            [
                'mode' => $staffingMode,
                'preferGeoFortKe' => $preferGeoFortKe,
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
                    : (((int) $optimized['unfilledRequiredAssignments'] === 0 && !$cookOpen)
                        ? 'complete'
                        : 'incomplete'),
                'message' => $staffingMode === 'lesson_only'
                    ? 'Alleen het lesrooster wordt gegenereerd. Personeel kan daarna via Handmatige correcties worden toegevoegd.'
                    : (((int) $optimized['unfilledRequiredAssignments'] === 0 && !$cookOpen)
                        ? 'Het personeelsvoorstel is operationeel gevuld; zachte waarschuwingen blijven controleerbaar.'
                        : 'Het concept bevat nog open personeelsrollen. Dit mag als concept, maar moet voor bevestiging worden opgelost.'),
            ],
        );

        return [
            'planId' => $planId,
            'revision' => $context['revision'],
            'existingSessionCount' => count($this->sessions->findByPlanId($planId)),
            'rounds' => $rounds,
            'sessions' => $proposal,
            'warnings' => $warnings,
            'staffing' => $staffing,
            'templateNote' => $this->templates->note($booking->program),
        ];
    }

    /**
     * Backwards-compatible argument order: actingAdminId blijft argument 5.
     * @param list<array{label?:mixed,startTime?:mixed,endTime?:mixed}> $rounds
     * @param list<int> $staffIds
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
    ): array {
        $proposal = $this->preview(
            $planId,
            $rounds,
            $staffIds,
            $staffingMode,
            $preferGeoFortKe,
            $cookStaffId,
        );

        try {
            if (!$this->pdo->beginTransaction()) {
                throw new RuntimeException('Generatietransactie kon niet worden gestart.');
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

        $bookingId = $plan['booking_id'] ?? null;
        if (!is_numeric($bookingId)) {
            throw new RosterPlanException(
                'BOOKING_REQUIRED',
                422,
                'Automatische generatie vanuit een los rooster volgt via de wizard.',
            );
        }

        $booking = $this->bookings->findById((int) $bookingId);
        if ($booking === null) {
            throw new RosterPlanException('BOOKING_NOT_FOUND', 404, 'De gekoppelde aanvraag bestaat niet.');
        }

        return [
            'booking' => $booking,
            'groups' => is_array($plan['groups'] ?? null) ? array_values($plan['groups']) : [],
            'revision' => (int) $plan['revision'],
        ];
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
    private function staffingSummary(array $proposal, array $rounds, bool $preferGeoFortKe): array
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
                    $preferGeoFortKe
                    && $minimum === 0
                    && $session['moduleKey'] === 'Klimaat-Experience'
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

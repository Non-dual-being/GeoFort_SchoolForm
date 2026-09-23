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

            $plan = $this->requireEditablePlan($planId, $expectedRevision);
            $booking = $this->requireBooking($plan);

            $this->assertModuleAllowed(
                $booking->schoolSector,
                $booking->program,
                $booking->choiceModuleKey,
                $moduleKey,
            );
            [$normalizedStart, $normalizedEnd] = $this->validateTimeRange(
                $booking->program,
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

            $parallel = $this->sessions->overlappingModuleSessionCount(
                $planId,
                $moduleKey,
                $normalizedStart,
                $normalizedEnd,
                $sessionId,
            );
            $maxParallel = $this->config->maxParallel($moduleKey);
            if ($parallel >= $maxParallel) {
                throw new RosterPlanException(
                    'PARALLEL_SESSION_LIMIT',
                    422,
                    "Voor deze module zijn maximaal {$maxParallel} parallelle sessies toegestaan.",
                );
            }

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

            $this->sessions->replaceGroups($sessionId, $groupIds);
            $this->staff?->replaceSessionAssignments($sessionId, $staffIds, 'manual');
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
                    $cookStaffId,
                );

                if ($cookStaffId !== null) {
                    $this->staff->addPlanSelection($planId, [$cookStaffId]);
                }
            }

            $revision = $this->plans->advanceRevision($planId, $actingAdminId);
            $warnings = $this->softWarnings($planId, $staffIds);

            if ($this->staff !== null) {
                $settings = $this->staff->settingsForPlan($planId);
                if ($settings['staffingMode'] === 'with_staff') {
                    $minimum = $this->config->minimumGeoFortStaff($moduleKey);
                    if (count($staffIds) < $minimum) {
                        $warnings[] = [
                            'code' => 'OPEN_REQUIRED_STAFF',
                            'message' => 'Deze sessie heeft nog een verplichte GeoFort-personeelsplek open.',
                        ];
                    }

                    if ($moduleKey === 'Voedsel-Innovatie' && $cookStaffId === null) {
                        $warnings[] = [
                            'code' => 'OPEN_COOK',
                            'message' => 'Voedsel Innovatie heeft nog geen kok ingevuld.',
                        ];
                    }
                }
            }

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

            $this->requireEditablePlan($planId, $expectedRevision);

            if (!$this->sessions->delete($sessionId, $planId)) {
                throw new RosterPlanException(
                    'SESSION_NOT_FOUND',
                    404,
                    'De roostersessie bestaat niet.',
                );
            }

            $revision = $this->plans->advanceRevision($planId, $actingAdminId);

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

        $viIntervals = $this->staff->viIntervalsForPlan($planId);
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

            $moduleKeys = array_column($member['preferences'], 'moduleKey');
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

            if (count(array_unique($assignedModules)) > 3) {
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

    private function assertModuleAllowed(
        string $sector,
        string $program,
        ?string $choiceModule,
        string $moduleKey,
    ): void {
        $allowed = $this->config->requiredModuleKeys($sector, $program, $choiceModule);
        if (!in_array($moduleKey, $allowed, true)) {
            throw new RosterPlanException(
                'MODULE_NOT_ALLOWED',
                422,
                'Deze module hoort niet bij het programma van deze aanvraag.',
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
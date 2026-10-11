<?php

declare(strict_types=1);

namespace GeoFort\Services\Dashboard\Roster;

use GeoFort\Booking\BookingProgramConfig;
use GeoFort\Booking\Stored\StoredBooking;
use GeoFort\Services\Booking\Availability\BookingAvailabilityService;
use GeoFort\Services\Booking\Roster\RosterGroupCountResolver;
use GeoFort\Services\Sql\RosterPlanSqlRepository;
use GeoFort\Services\Sql\RosterSessionSqlRepository;
use GeoFort\Services\Sql\RosterStaffSqlRepository;
use GeoFort\Services\Sql\RosterSnapshotSqlRepository;
use GeoFort\Services\Sql\StoredBookingSqlRepository;
use DateTimeImmutable;
use PDO;
use RuntimeException;
use Throwable;
use GeoFort\Validation\FieldValidationException;

final readonly class RosterPlanService
{
    public function __construct(
        private PDO $pdo,
        private StoredBookingSqlRepository $bookings,
        private RosterPlanSqlRepository $plans,
        private RosterGroupCountResolver $groupCountResolver,
        private ?RosterSessionSqlRepository $sessions = null,
        private ?RosterPlanningConfig $planningConfig = null,
        private ?RosterGenerationTemplateProvider $generationTemplates = null,
        private ?RosterStaffSqlRepository $staff = null,
        private ?BookingAvailabilityService $availability = null,
        private ?RosterSnapshotSqlRepository $snapshots = null,
    ) {}

    /** @return array{created:bool,plan:array<string,mixed>} */
    public function createFromBooking(int $bookingId, int $actingAdminId): array
    {
        $booking = $this->bookings->findById($bookingId);
        if ($booking === null) {
            throw new RosterPlanException('BOOKING_NOT_FOUND', 404, 'De aanvraag bestaat niet.');
        }

        if ($booking->studentCount === null || $booking->studentCount <= 0) {
            throw new RosterPlanException(
                'STUDENT_COUNT_REQUIRED',
                422,
                'Het leerlingaantal ontbreekt; er kan nog geen rooster worden gestart.',
            );
        }

        $groupCount = $this->groupCountResolver->resolve(
            $booking->schoolSector,
            $booking->program,
            $booking->studentCount,
        );

        $moduleCount = count(($this->planningConfig ?? new RosterPlanningConfig())->requiredModuleKeys($booking->schoolSector, $booking->program, $booking->choiceModuleKey));
        $maximumGroups = RosterManualProgramPolicy::maxGroupsForModuleCount($moduleCount);
        // Outside the public commercial range the count is advice only. Group limits remain independent.
        $groupCount = max(1, min($maximumGroups, $groupCount ?? (int) ceil($booking->studentCount / 16)));
        if ($groupCount < 1 || $groupCount > min(10, $maximumGroups)) {
            throw new RosterPlanException(
                'GROUP_COUNT_UNAVAILABLE',
                422,
                'Kies een groepsindeling binnen de capaciteit van het programma.',
            );
        }

        try {
            if (!$this->pdo->beginTransaction()) {
                throw new RuntimeException('Roostertransactie kon niet worden gestart.');
            }

            if ($this->staff !== null) RosterStaffCatalogGuard::lock($this->pdo);

            $creation = $this->plans->createForBooking(
                bookingId: $booking->id,
                visitDate: $booking->visitDate,
                sourceBookingFingerprint: $this->fingerprint($booking),
                adminId: $actingAdminId,
            );

            if ($creation['created']) {
                $this->plans->insertGroups($creation['id'], $groupCount);
            }

            if (!$this->pdo->commit()) {
                throw new RuntimeException('Roostertransactie kon niet worden vastgelegd.');
            }
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

            throw new RuntimeException('Roosterplan kon niet worden aangemaakt.', 0, $exception);
        }

        $plan = $this->getPlan($creation['id']);
        if ($plan === null) {
            throw new RuntimeException('Aangemaakt roosterplan kon niet worden teruggelezen.');
        }

        return [
            'created' => $creation['created'],
            'plan' => $plan,
        ];
    }

    /** @return array{created:bool,plan:array<string,mixed>} */
    public function createManual(
        string $schoolName,
        string $city,
        string $visitDate,
        string $sector,
        string $program,
        int $studentCount,
        int $groupCount,
        int $actingAdminId,
            ?int $bookingId = null,
        bool $allowWeekdayOverride = false,
    ): array {
        $schoolName = trim($schoolName);

        $linkedBooking = null;

        if ($bookingId !== null) {

            if ($bookingId <= 0) {

                throw new RosterPlanException(

                    'INVALID_BOOKING_ID',

                    422,

                    'Kies een geldige aanvraag om aan dit rooster te koppelen.',

                );

            }



            $linkedBooking = $this->bookings->findById($bookingId);

            if ($linkedBooking === null) {

                throw new RosterPlanException(

                    'BOOKING_NOT_FOUND',

                    404,

                    'De gekoppelde aanvraag bestaat niet.',

                );

            }



            if ($this->plans->findIdByBookingId($bookingId) !== null) {

                throw new RosterPlanException(

                    'BOOKING_ALREADY_HAS_ROSTER',

                    409,

                    'Deze aanvraag is al aan een rooster gekoppeld.',

                );

            }

        }
        $city = trim($city);
        $visitDate = trim($visitDate);
        $sector = trim($sector);
        $program = trim($program);

        if ($schoolName === '' || mb_strlen($schoolName) > 160) {
            throw new RosterPlanException(
                'INVALID_MANUAL_SCHOOL_NAME',
                422,
                'Vul een schoolnaam in van maximaal 160 tekens.',
            );
        }

        if (mb_strlen($city) > 120) {
            throw new RosterPlanException(
                'INVALID_MANUAL_CITY',
                422,
                'De plaatsnaam mag maximaal 120 tekens bevatten.',
            );
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $visitDate);
        if ($date === false || $date->format('Y-m-d') !== $visitDate) {
            throw new RosterPlanException(
                'INVALID_MANUAL_VISIT_DATE',
                422,
                'Gebruik een geldige bezoekdatum.',
            );
        }

        if (!array_key_exists($sector, BookingProgramConfig::SCHOOL_TYPES_BY_KEY)) {
            throw new RosterPlanException(
                'INVALID_MANUAL_SECTOR',
                422,
                'Kies een geldige onderwijssector.',
            );
        }

        $programConfig = BookingProgramConfig::PROGRAMS[$program] ?? null;
        if (!is_array($programConfig)) {
            throw new RosterPlanException(
                'INVALID_MANUAL_PROGRAM',
                422,
                'Kies een geldig programma als basis voor het rooster.',
            );
        }

        if (!BookingProgramConfig::isProgramAllowedForSchoolSector($program, $sector)) {
            throw new RosterPlanException(
                'INVALID_MANUAL_PROGRAM_FOR_SECTOR',
                422,
                'Dit programma is niet beschikbaar voor de gekozen onderwijssector.',
            );
        }

        if (!BookingProgramConfig::isProgramAllowedForWeekday($program, (int) $date->format('N'))
            && !($program === 'ochtend' && $allowWeekdayOverride)) {
            throw new RosterPlanException(
                'INVALID_MANUAL_PROGRAM_DATE',
                422,
                'Dit programma is niet beschikbaar op de gekozen bezoekdatum.',
            );
        }

        if (

            $this->availability !== null

            && ($linkedBooking === null || $linkedBooking->visitDate !== $visitDate)

        ) {
            try {
                $availableDate = $this->availability->assertDateIsValid($visitDate);
                $availability = $this->availability->getAvailabilityForDate($availableDate);
            } catch (FieldValidationException $exception) {
                throw new RosterPlanException(
                    'MANUAL_VISIT_DATE_UNAVAILABLE',
                    422,
                    $exception->getMessage(),
                );
            }

            if (
                (int) ($availability['remainingSchoolSlots'] ?? 0) <= 0
                || (int) ($availability['availableStudents'] ?? 0) <= 0
            ) {
                throw new RosterPlanException(
                    'MANUAL_VISIT_DATE_UNAVAILABLE',
                    422,
                    'Op deze datum kan geen nieuw onderwijsbezoek worden ingepland.',
                );
            }
        }

        if ($studentCount < 1) {
            throw new RosterPlanException(
                'INVALID_MANUAL_STUDENT_COUNT',
                422,
                'Het leerlingaantal moet een positief geheel getal zijn.',
            );
        }

        if ($groupCount < 1 || $groupCount > RosterManualProgramPolicy::MAX_GROUP_COUNT) {
            throw new RosterPlanException(
                'INVALID_MANUAL_GROUP_COUNT',
                422,
                'Het aantal roostergroepen moet tussen 1 en 10 liggen.',
            );
        }

        try {
            if (!$this->pdo->beginTransaction()) {
                throw new RuntimeException('Roostertransactie kon niet worden gestart.');
            }

            if ($this->staff !== null) RosterStaffCatalogGuard::lock($this->pdo);

            $planId = $this->plans->createManualPlan(
                visitDate: $visitDate,
                adminId: $actingAdminId,
                bookingId: $bookingId,
            );

            $this->plans->saveManualContext(
                planId: $planId,
                schoolName: $schoolName,
                city: $city,
                sectorKey: $sector,
                programKey: $program,
                studentCount: $studentCount,
                dayStartTime: substr((string) $programConfig['beginTijd'], 0, 5),
                dayEndTime: substr((string) $programConfig['eindTijd'], 0, 5),
            );
            $this->plans->insertGroups($planId, $groupCount);

            if (!$this->pdo->commit()) {
                throw new RuntimeException('Roostertransactie kon niet worden vastgelegd.');
            }
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

            throw new RuntimeException('Handmatig roosterplan kon niet worden aangemaakt.', 0, $exception);
        }

        $plan = $this->getPlan($planId);
        if ($plan === null) {
            throw new RuntimeException('Handmatig roosterplan kon niet worden teruggelezen.');
        }

        return [
            'created' => true,
            'plan' => $plan,
        ];
    }

    /** @param list<mixed>|null $moduleKeys Null laat de bestaande moduleselectie intact. */
    /** @return array<string,mixed> */
    public function convertBookingPlanToCustom(
        int $planId,
        int $expectedRevision,
        int $actingAdminId,
    ): array {
        if ($planId <= 0 || $expectedRevision <= 0 || $actingAdminId <= 0) {
            throw new RosterPlanException(
                'INVALID_ROSTER_CONVERSION_REQUEST',
                422,
                'Ongeldige aanvraag om het rooster naar maatwerk om te zetten.',
            );
        }

        try {
            if (!$this->pdo->beginTransaction()) {
                throw new RuntimeException('Roosterconversie kon niet worden gestart.');
            }

            if ($this->staff !== null) RosterStaffCatalogGuard::lock($this->pdo);

            $plan = $this->plans->lockPlan($planId);
            if ($plan === null) {
                throw new RosterPlanException('PLAN_NOT_FOUND', 404, 'Het roosterplan bestaat niet.');
            }

            if (($plan['planning_mode'] ?? null) !== 'booking_locked' || $plan['booking_id'] === null) {
                throw new RosterPlanException(
                    'ROSTER_CONVERSION_NOT_BOOKING_LOCKED',
                    409,
                    'Alleen een rooster dat nog door een aanvraag wordt bepaald kan naar maatwerk worden omgezet.',
                );
            }

            if ($plan['status'] !== 'concept') {
                throw new RosterPlanException(
                    'ROSTER_CONVERSION_LOCKED',
                    409,
                    'Alleen een conceptrooster kan naar maatwerk worden omgezet.',
                );
            }

            if ($plan['revision'] !== $expectedRevision) {
                throw new RosterPlanException(
                    'REVISION_CONFLICT',
                    409,
                    'Het rooster is ondertussen gewijzigd. Laad het rooster opnieuw.',
                );
            }

            if ($this->sessions !== null && $this->sessions->findByPlanId($planId) !== []) {
                throw new RosterPlanException(
                    'ROSTER_CONVERSION_HAS_SESSIONS',
                    409,
                    'Dit rooster bevat al sessies. Verwijder of herbouw die eerst voordat het programma maatwerk wordt.',
                );
            }

            if ($this->plans->manualContextForPlan($planId) !== null) {
                throw new RosterPlanException(
                    'ROSTER_CONVERSION_CONTEXT_EXISTS',
                    409,
                    'Dit rooster bevat al een maatwerkcontext en kan niet opnieuw worden geconverteerd.',
                );
            }

            $booking = $this->bookings->findById((int) $plan['booking_id']);
            if ($booking === null) {
                throw new RosterPlanException('BOOKING_NOT_FOUND', 404, 'De gekoppelde aanvraag bestaat niet meer.');
            }
            if ($booking->studentCount === null) {
                throw new RosterPlanException(
                    'STUDENT_COUNT_REQUIRED',
                    422,
                    'Het leerlingaantal ontbreekt; het rooster kan niet naar maatwerk worden omgezet.',
                );
            }

            $programConfig = BookingProgramConfig::PROGRAMS[$booking->program] ?? null;
            if (!is_array($programConfig)) {
                throw new RosterPlanException('PROGRAM_NOT_SUPPORTED', 422, 'Onbekend programma.');
            }
            if ($this->planningConfig === null) {
                throw new RuntimeException('Roosterconfiguratie ontbreekt voor maatwerkconversie.');
            }

            $moduleKeys = $this->planningConfig->requiredModuleKeys(
                $booking->schoolSector,
                $booking->program,
                $booking->choiceModuleKey,
            );

            $this->plans->saveManualContext(
                $planId,
                $booking->schoolName,
                $booking->city,
                $booking->schoolSector,
                $booking->program,
                $booking->studentCount,
                (string) $programConfig['beginTijd'],
                (string) $programConfig['eindTijd'],
            );
            $this->plans->saveManualModuleKeys($planId, $moduleKeys);
            $this->plans->convertBookingPlanToCustom($planId, $booking->visitDate);
            $this->plans->advanceRevision($planId, $actingAdminId);

            if (!$this->pdo->commit()) {
                throw new RuntimeException('Roosterconversie kon niet worden vastgelegd.');
            }
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

            throw new RuntimeException('Rooster kon niet naar maatwerk worden omgezet.', 0, $exception);
        }

        $result = $this->getPlan($planId);
        if ($result === null) {
            throw new RuntimeException('Geconverteerd rooster kon niet worden teruggelezen.');
        }

        return $result;
    }

    public function saveManualProgram(
        int $planId,
        int $expectedRevision,
        ?array $moduleKeys,
        int $actingAdminId,
        ?RosterManualTimeline $timeline = null,
    ): array {
        if ($planId <= 0 || $expectedRevision <= 0 || ($moduleKeys === null && $timeline === null)) {
            throw new RosterPlanException(
                'INVALID_MANUAL_PROGRAM_REQUEST',
                422,
                'Het roosterprogramma kon niet worden opgeslagen.',
            );
        }

        $normalized = [];
        foreach ($moduleKeys ?? [] as $moduleKey) {
            if (!is_string($moduleKey)) {
                throw new RosterPlanException(
                    'INVALID_MANUAL_MODULES',
                    422,
                    'De moduleselectie bevat een ongeldige waarde.',
                );
            }

            $moduleKey = trim($moduleKey);
            if ($moduleKey === '' || in_array($moduleKey, $normalized, true)) {
                throw new RosterPlanException(
                    'INVALID_MANUAL_MODULES',
                    422,
                    'Kies iedere lesmodule maximaal één keer.',
                );
            }

            $normalized[] = $moduleKey;
        }

        if (
            $moduleKeys !== null
            && !RosterManualProgramPolicy::supportsModuleCount(count($normalized))
        ) {
            throw new RosterPlanException(
                'INVALID_MANUAL_MODULE_COUNT',
                422,
                'Kies minimaal twee en maximaal vijf lesmodules.',
            );
        }

        try {
            if (!$this->pdo->beginTransaction()) {
                throw new RuntimeException('Programmawijziging kon niet worden gestart.');
            }

            if ($this->staff !== null) RosterStaffCatalogGuard::lock($this->pdo);

            $plan = $this->plans->lockPlan($planId);
            if ($plan === null) {
                throw new RosterPlanException(
                    'PLAN_NOT_FOUND',
                    404,
                    'Het roosterplan bestaat niet.',
                );
            }

            if (($plan['planning_mode'] ?? null) !== 'custom') {
                throw new RosterPlanException(
                    'MANUAL_PROGRAM_ONLY',
                    409,
                    'Deze programmastap is alleen beschikbaar voor een zelfstandig rooster.',
                );
            }

            if ($plan['status'] !== 'concept') {
                throw new RosterPlanException(
                    'MANUAL_PROGRAM_LOCKED',
                    409,
                    'Alleen een conceptrooster kan via deze wizard worden aangepast.',
                );
            }

            if ($plan['revision'] !== $expectedRevision) {
                throw new RosterPlanException(
                    'REVISION_CONFLICT',
                    409,
                    'Het rooster is ondertussen gewijzigd. Laad het rooster opnieuw.',
                );
            }

            if ($this->sessions !== null && $this->sessions->findByPlanId($planId) !== []) {
                throw new RosterPlanException(
                    'MANUAL_PROGRAM_HAS_SESSIONS',
                    409,
                    'Het programma en de dagindeling kunnen niet via de wizard worden gewijzigd nadat sessies zijn aangemaakt.',
                );
            }

            $context = $this->plans->manualContextForPlan($planId);
            if ($context === null) {
                throw new RosterPlanException(
                    'MANUAL_CONTEXT_NOT_FOUND',
                    409,
                    'De basisgegevens van dit zelfstandige rooster ontbreken.',
                );
            }

            $sector = $this->nullableString($context['sector_key'] ?? null);
            $program = $this->nullableString($context['program_key'] ?? null);
            if ($sector === null || $program === null) {
                throw new RosterPlanException(
                    'MANUAL_CONTEXT_INVALID',
                    409,
                    'De onderwijssector of het programma ontbreekt in de roosterbasis.',
                );
            }

            $allowed = array_values(array_unique(array_merge(
                BookingProgramConfig::getStandardModulesForSelection($sector, $program),
                BookingProgramConfig::getChoiceModulesForSelection($sector, $program),
            )));

            foreach ($normalized as $moduleKey) {
                if (!in_array($moduleKey, $allowed, true)) {
                    throw new RosterPlanException(
                        'INVALID_MANUAL_MODULE_FOR_PROGRAM',
                        422,
                        'Een gekozen lesmodule is niet beschikbaar voor deze onderwijssector en dit programma.',
                    );
                }
            }

            $storedModuleKeys = $this->decodeManualModuleKeys(
                $context['module_keys_json'] ?? null,
            );
            $moduleCountChanged = false;

            if ($moduleKeys !== null) {
                $groupPlan = $this->getPlan($planId);
                if ($groupPlan === null) {
                    throw new RosterPlanException(
                        'PLAN_NOT_FOUND',
                        404,
                        'Het roosterplan bestaat niet.',
                    );
                }

                $groupCount = count($groupPlan['groups'] ?? []);
                $maxGroups = RosterManualProgramPolicy::maxGroupsForModuleCount(
                    count($normalized),
                );

                if ($groupCount > $maxGroups) {
                    throw new RosterPlanException(
                        'MANUAL_GROUP_CAPACITY_EXCEEDED',
                        422,
                        sprintf(
                            '%d lesmodules ondersteunen maximaal %d roostergroepen; dit rooster heeft %d groepen.',
                            count($normalized),
                            $maxGroups,
                            $groupCount,
                        ),
                    );
                }

                $moduleCountChanged = $storedModuleKeys === null
                    || count($storedModuleKeys) !== count($normalized);

                $this->plans->saveManualModuleKeys($planId, $normalized);
            }

            if (
                $moduleCountChanged
                && $timeline === null
                && ($context['rounds_json'] ?? null) !== null
            ) {
                $this->plans->clearManualRounds($planId);
            }
            if ($timeline !== null) {
                $effectiveModuleKeys = $moduleKeys !== null
                    ? $normalized
                    : ($storedModuleKeys ?? []);
                $effectiveModuleCount = count($effectiveModuleKeys);

                if (!RosterManualProgramPolicy::supportsModuleCount($effectiveModuleCount)) {
                    throw new RosterPlanException(
                        'INVALID_MANUAL_MODULE_COUNT',
                        422,
                        'Sla eerst een programma met twee tot en met vijf lesmodules op.',
                    );
                }

                if (!RosterManualProgramPolicy::matchesTimelineStructure(
                    $timeline->blocks,
                    $effectiveModuleCount,
                    $program,
                )) {
                    throw new RosterPlanException(
                        'MANUAL_TIMELINE_STRUCTURE_MISMATCH',
                        422,
                        'De dagindeling past niet bij het gekozen aantal lesmodules.',
                    );
                }

                $this->plans->saveManualRounds($planId, $timeline->blocks);
            }
            $this->plans->advanceRevision($planId, $actingAdminId);

            if (!$this->pdo->commit()) {
                throw new RuntimeException('Programmawijziging kon niet worden vastgelegd.');
            }
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

            throw new RuntimeException('Handmatig programma kon niet worden opgeslagen.', 0, $exception);
        }

        $result = $this->getPlan($planId);
        if ($result === null) {
            throw new RuntimeException('Opgeslagen roosterprogramma kon niet worden teruggelezen.');
        }

        return $result;
    }

    public function deleteConcept(int $planId): void
    {
        try {
            if (!$this->pdo->beginTransaction()) {
                throw new RuntimeException('Roosterverwijdering kon niet worden gestart.');
            }

            if ($this->staff !== null) RosterStaffCatalogGuard::lock($this->pdo);

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
                    'PLAN_NOT_DELETABLE',
                    409,
                    'Alleen een conceptrooster kan worden verwijderd.',
                );
            }

            if (($this->snapshots?->state($planId)['historyCount'] ?? 0) > 0) {
                throw new RosterPlanException('FINAL_HISTORY_EXISTS', 409, 'Dit rooster is eerder definitief geweest. De finale historie moet behouden blijven.');
            }
            if (!$this->plans->deleteById($planId)) {
                throw new RuntimeException('Roosterplan kon niet worden verwijderd.');
            }

            if (!$this->pdo->commit()) {
                throw new RuntimeException('Roosterverwijdering kon niet worden vastgelegd.');
            }
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
                'Roosterplan kon niet worden verwijderd.',
                0,
                $exception,
            );
        }
    }

    /** @return array<string,mixed>|null */
    public function getPlan(int $planId): ?array
    {
        $row = $this->plans->findById($planId);
        if ($row === null) {
            return null;
        }

        $snapshotState = $this->snapshots?->state($planId) ?? ['current' => null, 'historyCount' => 0];
        $snapshot = $snapshotState['current'];
        $finalization = [
            'trusted' => $snapshot !== null,
            'snapshotId' => $snapshot === null ? null : (int) $snapshot['id'],
            'sourceRevision' => $snapshot === null ? null : (int) $snapshot['source_revision'],
            'publishedRevision' => $snapshot === null ? null : (int) $snapshot['published_revision'],
            'finalizedAt' => $snapshot === null ? null : str_replace(' ', 'T', $snapshot['finalized_at']) . 'Z',
            'historyCount' => $snapshotState['historyCount'],
        ];
        if ($snapshot !== null) {
            return [...RosterPresentationInformation::fromSnapshot($snapshot['content']), 'status' => 'published', 'revision' => (int) $row['revision'], 'finalization' => $finalization];
        }

        $bookingId = $this->nullableInt($row['booking_id'] ?? null);
        $planningMode = $this->nullableString($row['planning_mode'] ?? null)
            ?? ($bookingId === null ? 'custom' : 'booking_locked');
        $currentBooking = $bookingId === null ? null : $this->bookings->findById($bookingId);
        $information = RosterPresentationInformation::fromBooking($currentBooking);
        $manualContext = $planningMode === 'custom'
            ? $this->plans->manualContextForPlan($planId)
            : null;
        $sourceFingerprint = $this->nullableString($row['source_booking_fingerprint'] ?? null);
        $sourceCurrent = $planningMode === 'custom'
            ? $manualContext !== null
            : $currentBooking !== null
                && $sourceFingerprint !== null
                && hash_equals($sourceFingerprint, $this->fingerprint($currentBooking));

        if ($planningMode === 'custom' && $manualContext !== null) {
            $row['schoolnaam'] = $manualContext['school_name'] ?? null;
            $row['plaats'] = $manualContext['city'] ?? null;
            $row['onderwijs_sector'] = $manualContext['sector_key'] ?? null;
            $row['programma'] = $manualContext['program_key'] ?? null;
            $row['keuzemodule_key'] = null;
            $row['aantal_leerlingen'] = $manualContext['student_count'] ?? null;
        }

        $groups = array_map(
            static fn (array $group): array => [
                'id' => (int) $group['id'],
                'label' => (string) $group['label'],
                'position' => (int) $group['position'],
                'studentCount' => $group['student_count'] === null ? null : (int) $group['student_count'],
            ],
            is_array($row['groups'] ?? null) ? $row['groups'] : [],
        );

        $sector = $this->nullableString($row['onderwijs_sector'] ?? null)
            ?? $this->nullableString($manualContext['sector_key'] ?? null);
        $program = $this->nullableString($row['programma'] ?? null)
            ?? $this->nullableString($manualContext['program_key'] ?? null);
        $choiceModule = $this->nullableString($row['keuzemodule_key'] ?? null);
        $studentCount = $this->nullableInt($row['aantal_leerlingen'] ?? null)
            ?? $this->nullableInt($manualContext['student_count'] ?? null);
        $manualModuleKeys = $manualContext === null
            ? null
            : $this->decodeManualModuleKeys($manualContext['module_keys_json'] ?? null);

        $educationRow = $row;
        if ($manualContext !== null) {
            $educationRow['onderwijs_sector'] = $sector;
            $educationRow['programma'] = $program;
            $educationRow['keuzemodule_key'] = null;
            $educationRow['aantal_leerlingen'] = $studentCount;
        }

        $planning = $this->planning($planId, $sector, $program, $choiceModule, $groups, $manualModuleKeys);
        if ($manualContext !== null) {
            $storedRounds = $manualContext['rounds_json'] ?? null;
            $planning['rounds'] = $storedRounds === null
                ? ($this->generationTemplates?->defaultTimeline(
                $program ?? '',
                ($manualContext['module_keys_json'] ?? null) === null
                    ? null
                    : count($manualModuleKeys ?? []),
            ) ?? [])
                : RosterManualTimeline::fromArray(
                    json_decode((string) $storedRounds, false, 32, JSON_THROW_ON_ERROR),
                )->blocks;
            $planning['roundsSource'] = $storedRounds === null ? 'default' : 'saved';
        } else {
            $planning['rounds'] = $this->generationTemplates?->defaultTimeline(
                $program ?? '',
                count($planning['modules'] ?? []),
            ) ?? [];
            $planning['roundsSource'] = 'booking_standard';
        }

        return [
            'id' => (int) $row['id'],
            'bookingId' => $bookingId,
            'planningMode' => $planningMode,
            'visitDate' => (string) $row['visit_date'],
            'status' => (string) $row['status'],
            'finalization' => $finalization,
            'revision' => (int) $row['revision'],
            'sourceCurrent' => $sourceCurrent,
            'needsReview' => $planningMode === 'booking_locked' && $bookingId !== null && !$sourceCurrent,
            'createdAt' => (string) $row['created_at'],
            'updatedAt' => (string) $row['updated_at'],
            'school' => [
                'name' => $this->stringOrFallback(
                    $row['schoolnaam'] ?? ($manualContext['school_name'] ?? null),
                    'Los rooster',
                ),
                'city' => $this->stringOrFallback(
                    $row['plaats'] ?? ($manualContext['city'] ?? null),
                    '',
                ),
                'contactName' => $currentBooking === null
                    ? ''
                    : trim($currentBooking->contactFirstName . ' ' . $currentBooking->contactLastName),
                'contactPhone' => $currentBooking?->contactPhone ?? '',
                'supervisorCount' => $currentBooking?->supervisorCount,
                ...$information['school'],
            ],
            'education' => [...$this->education($educationRow), ...$information['education']],
            'groups' => $groups,
            'planning' => $planning,
        ];
    }

    /** @return list<array<string,mixed>> */
    public function listPlans(): array
    {
        return array_map(
            function (array $row): array {
                $bookingId = $this->nullableInt($row['booking_id'] ?? null);
                $planningMode = $this->nullableString($row['planning_mode'] ?? null)
                    ?? ($bookingId === null ? 'custom' : 'booking_locked');
                $manualContext = $planningMode === 'custom'
                    ? $this->plans->manualContextForPlan((int) $row['id'])
                    : null;

                if ($planningMode === 'custom' && $manualContext !== null) {
                    $row['schoolnaam'] = $manualContext['school_name'] ?? null;
                    $row['plaats'] = $manualContext['city'] ?? null;
                    $row['onderwijs_sector'] = $manualContext['sector_key'] ?? null;
                    $row['programma'] = $manualContext['program_key'] ?? null;
                    $row['keuzemodule_key'] = null;
                    $row['aantal_leerlingen'] = $manualContext['student_count'] ?? null;
                }

                $sector = $this->nullableString($row['onderwijs_sector'] ?? null);
                $program = $this->nullableString($row['programma'] ?? null);
                $studentCount = $this->nullableInt($row['aantal_leerlingen'] ?? null);
                return [
                    'id' => (int) $row['id'],
                    'bookingId' => $bookingId,
                    'planningMode' => $planningMode,
                    'visitDate' => (string) $row['visit_date'],
                    'status' => (string) $row['status'],
                    'revision' => (int) $row['revision'],
                    'schoolName' => $this->stringOrFallback(
                        $row['schoolnaam'] ?? ($manualContext['school_name'] ?? null),
                        'Los rooster',
                    ),
                    'city' => $this->stringOrFallback(
                        $row['plaats'] ?? ($manualContext['city'] ?? null),
                        '',
                    ),
                    'sectorLabel' => $this->sectorLabel($sector),
                    'programLabel' => $this->programLabel($program),
                    'moduleLabel' => $this->moduleLabel(
                        $this->nullableString($row['keuzemodule_key'] ?? null),
                    ),
                    'studentCount' => $studentCount,
                    'groupCount' => (int) ($row['group_count'] ?? 0),
                ];
            },
            $this->plans->listPlans(),
        );
    }

    /** @return array<string,mixed> */
    private function planning(
        int $planId,
        ?string $sector,
        ?string $program,
        ?string $choiceModule,
        array $groups,
        ?array $manualModuleKeys = null,
    ): array {
        if ($this->planningConfig === null || $sector === null || $program === null) {
            return [
                'modules' => [],
                'sessions' => [],
                'generation' => [
                    'defaultRounds' => [],
                    'templateNote' => 'Geen automatisch tijdtemplate beschikbaar.',
                ],
            ];
        }

        $modules = $manualModuleKeys !== null && $manualModuleKeys !== []
            ? array_map(
                fn (string $moduleKey): array => $this->planningConfig->moduleOption($moduleKey),
                $manualModuleKeys,
            )
            : $this->planningConfig->moduleOptions($sector, $program, $choiceModule);
        $moduleCount = count($modules);
        $modules = array_map(
            function (array $module) use ($moduleCount): array {
                $moduleKey = (string) $module['key'];
                $schoolSupervisionAllowed = $this->planningConfig
                    ->schoolSupervisionAllowedForModuleCount($moduleKey, $moduleCount);

                return [
                    ...$module,
                    'minimumGeoFortStaff' => $this->planningConfig
                        ->requiredGeoFortGuidesForSession(
                            $moduleKey,
                            1,
                            $schoolSupervisionAllowed,
                        ),
                    'schoolSupervisionAllowed' => $schoolSupervisionAllowed,
                ];
            },
            $modules,
        );

        $moduleByKey = [];
        foreach ($modules as $module) {
            $moduleByKey[$module['key']] = $module;
        }

        $groupById = [];
        foreach ($groups as $group) {
            $groupById[$group['id']] = $group;
        }

        $staffAssignments = $this->staff?->assignmentsForPlan($planId) ?? [];
        $groupStaffAssignments = $this->staff?->groupAssignmentsForPlan($planId) ?? [];

        $sessions = [];
        foreach ($this->sessions?->findByPlanId($planId) ?? [] as $session) {
            $moduleKey = $this->nullableString($session['module_key'] ?? null);
            if ($moduleKey === null) {
                continue;
            }

            $module = $moduleByKey[$moduleKey] ?? $this->planningConfig->moduleOption($moduleKey);
            $groupIds = array_values(array_filter(
                array_map('intval', $session['group_ids'] ?? []),
                static fn (int $groupId): bool => isset($groupById[$groupId]),
            ));

            $sid = (int) $session['id'];
            $supervision = [];
            $effectiveGroupStaff = [];
            foreach ($groupIds as $gid) {
                $specific = [];
                foreach ($groupStaffAssignments[$sid] ?? [] as $assignment) if ($assignment['groupId'] === $gid) $specific = $assignment['staffAssignments'];
                $kind = $session['group_supervision'][$gid] ?? null;
                $effective = ($kind === 'school' || $kind === 'open') ? [] : ($kind === 'staff' ? $specific : ($specific !== [] ? $specific : ($staffAssignments[$sid] ?? [])));
                $supervision[] = ['groupId' => $gid, 'kind' => RosterGroupSupervision::kind($kind, $effective)];
                $effectiveGroupStaff[] = ['groupId' => $gid, 'staffAssignments' => $effective];
            }
            $staffedGroupCount = count(array_filter($supervision, static fn (array $g): bool => $g['kind'] !== 'school'));
            $sessions[] = [
                'id' => (int) $session['id'],
                'moduleKey' => $moduleKey,
                'moduleLabel' => $module['label'],
                'color' => $module['color'],
                'startTime' => substr((string) $session['start_time'], 0, 5),
                'endTime' => substr((string) $session['end_time'], 0, 5),
                'location' => $this->nullableString($session['location_label'] ?? null),
                'groupIds' => $groupIds,
                'groupLabels' => array_values(array_map(
                    static fn (int $groupId): string => (string) $groupById[$groupId]['label'],
                    $groupIds,
                )),
                'minimumGeoFortStaff' => $staffedGroupCount === 0 ? 0 : $this->planningConfig->requiredGeoFortGuidesForSession(
                    $moduleKey,
                    $staffedGroupCount,
                    false,
                ),
                'schoolSupervisionAllowed' => $this->planningConfig->schoolSupervisionAllowedForModuleCount(
                    $moduleKey,
                    count($modules),
                ),
                'staffAssignments' => $staffAssignments[(int) $session['id']] ?? [],
                'groupStaffAssignments' => $effectiveGroupStaff,
                'groupSupervision' => $supervision,
            ];
        }

        return [
            'modules' => $modules,
            'sessions' => $sessions,
            'generation' => [
                'defaultRounds' => $this->generationTemplates?->defaultRounds(
                    $program,
                    $moduleCount,
                ) ?? [],
                'templateNote' => $this->generationTemplates?->note($program)
                    ?? 'Controleer de rondetijden voordat je automatisch genereert.',
            ],
            'staffCatalog' => $this->staffCatalog(),
            'catalogRevision' => $this->staff === null ? 0 : RosterStaffCatalogGuard::revision($this->pdo),
            'selectedStaffIds' => $this->staff?->selectedIdsForPlan($planId) ?? [],
            'staffingSettings' => $this->staff?->settingsForPlan($planId) ?? [
                'staffingMode' => 'with_staff',
                'preferGeoFortKe' => true,
                'cookStaffId' => null,
            ],
        ];
    }

    /** @return list<array<string,mixed>> */
    private function staffCatalog(): array
    {
        if ($this->staff === null) return [];
        return array_map(function (array $member): array {
            return [
                'id' => $member['id'],
                'name' => $member['name'],
                'isActive' => $member['isActive'],
                'employmentType' => $member['employmentType'],
                'canGuide' => $member['canGuide'],
                'canCook' => $member['canCook'],
                'preferences' => array_map(
                    fn (array $preference): array => [
                        'moduleKey' => $preference['moduleKey'],
                        'moduleLabel' => BookingProgramConfig::MODULE_LABELS[$preference['moduleKey']] ?? $preference['moduleKey'],
                        'rank' => $preference['rank'],
                    ],
                    RosterPlanningConfig::rosterPreferences($member['preferences']),
                ),
            ];
        }, $this->staff->catalog());
    }

    /** @return array<string,mixed> */
    private function education(array $row): array
    {
        $sector = $this->nullableString($row['onderwijs_sector'] ?? null);
        $program = $this->nullableString($row['programma'] ?? null);
        $module = $this->nullableString($row['keuzemodule_key'] ?? null);

        return [
            'sector' => $sector,
            'sectorLabel' => $this->sectorLabel($sector),
            'program' => $program,
            'programLabel' => $this->programLabel($program),
            'choiceModule' => $module,
            'choiceModuleLabel' => $this->moduleLabel($module),
            'studentCount' => $this->nullableInt($row['aantal_leerlingen'] ?? null),
        ];
    }

    private function fingerprint(StoredBooking $booking): string
    {
        return RosterSourceFingerprint::of($booking);
    }

    private function sectorLabel(?string $sector): string
    {
        if ($sector === null) return 'Niet gekoppeld';

        return isset(BookingProgramConfig::SCHOOL_TYPES_BY_KEY[$sector]['label'])
            ? (string) BookingProgramConfig::SCHOOL_TYPES_BY_KEY[$sector]['label']
            : $this->unknownLabel($sector);
    }

    private function programLabel(?string $program): string
    {
        if ($program === null) return 'Niet gekoppeld';

        return isset(BookingProgramConfig::PROGRAMS[$program]['label'])
            ? (string) BookingProgramConfig::PROGRAMS[$program]['label']
            : $this->unknownLabel($program);
    }

    private function moduleLabel(?string $module): ?string
    {
        if ($module === null) return null;
        return BookingProgramConfig::MODULE_LABELS[$module] ?? $this->unknownLabel($module);
    }

    private function unknownLabel(string $value): string
    {
        $label = trim(str_replace(['-', '_'], ' ', $value));
        return ($label !== '' ? $label : 'Onbekend') . ' (onbekend)';
    }

    /** @return list<string> */
    private function decodeManualModuleKeys(mixed $value): array
    {
        if (!is_string($value) || trim($value) === '') {
            return [];
        }

        try {
            $decoded = json_decode($value, true, 32, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return [];
        }

        if (!is_array($decoded)) {
            return [];
        }

        return array_values(array_filter(
            $decoded,
            static fn (mixed $moduleKey): bool => is_string($moduleKey) && trim($moduleKey) !== '',
        ));
    }

    private function nullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') return null;
        return (int) $value;
    }

    private function nullableString(mixed $value): ?string
    {
        if (!is_string($value)) return null;
        $value = trim($value);
        return $value === '' ? null : $value;
    }

    private function stringOrFallback(mixed $value, string $fallback): string
    {
        $value = $this->nullableString($value);
        return $value ?? $fallback;
    }
}

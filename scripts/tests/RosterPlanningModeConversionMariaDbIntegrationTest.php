<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';
require __DIR__ . '/Support/DisposableBookingMariaDb.php';

use GeoFort\Booking\Stored\StoredBookingAssembler;
use GeoFort\Services\Booking\Roster\RosterGroupCountResolver;
use GeoFort\Services\Dashboard\Roster\RosterGenerationTemplateProvider;
use GeoFort\Services\Dashboard\Roster\RosterPlanException;
use GeoFort\Services\Dashboard\Roster\RosterPlanningConfig;
use GeoFort\Services\Dashboard\Roster\RosterPlanService;
use GeoFort\Services\Sql\RosterPlanSqlRepository;
use GeoFort\Services\Sql\RosterSessionSqlRepository;
use GeoFort\Services\Sql\StoredBookingSqlRepository;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$database = DisposableBookingMariaDb::create('roster_convert');

try {
    $pdo = $database->pdo;
    $root = dirname(__DIR__, 2);

    foreach ([
        '/database/sql/2026-09-21_create_roster_planning_foundation.sql',
        '/database/sql/2026-09-21_create_roster_session_planning.sql',
        '/database/sql/2026-09-26_create_roster_manual_plan_context.sql',
        '/database/sql/2026-09-26_add_roster_manual_program_modules.sql',
        '/database/sql/2026-09-26_add_roster_manual_rounds.sql',
    ] as $migration) {
        $pdo->exec((string) file_get_contents($root . $migration));
    }

    $planningModeExists = (int) $pdo->query(
        "SELECT COUNT(*)
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'roster_plans'
           AND COLUMN_NAME = 'planning_mode'",
    )->fetchColumn();

    if ($planningModeExists === 0) {
        $pdo->exec((string) file_get_contents(
            $root . '/database/sql/2026-09-28_add_roster_planning_mode.sql',
        ));
    }

    $bookings = new StoredBookingSqlRepository($pdo, new StoredBookingAssembler());
    $plans = new RosterPlanSqlRepository($pdo);
    $sessions = new RosterSessionSqlRepository($pdo);
    $config = new RosterPlanningConfig();
    $templates = new RosterGenerationTemplateProvider();
    $service = new RosterPlanService(
        $pdo,
        $bookings,
        $plans,
        new RosterGroupCountResolver(),
        $sessions,
        $config,
        $templates,
    );

    $insertBooking = $pdo->prepare(<<<'SQL'
        INSERT INTO aanvragen (
            status, schoolnaam, land, adres, postcode, plaats,
            school_telefoonnummer, contactpersoon_telefoonnummer,
            contactpersoon_voornaam, contactpersoon_achternaam,
            email, bezoekdatum, cjpPasGebruik, onderwijs_sector,
            programma, keuzemodule_key, aantal_leerlingen,
            aantal_begeleiders, voorwaarden_akkoord
        ) VALUES (
            'Definitief', :school, 'Nederland', 'Testweg 3', '4200 AA',
            'Gorinchem', '0100000000', '0612345678', 'Test', 'Planner',
            :email, :visit_date, 'nee', 'primairOnderwijs', 'dag',
            'Minecraft-Windenergiespeurtocht', 90, 7, 1
        )
        SQL);

    $createBooking = static function (
        string $school,
        string $email,
        string $date
    ) use ($insertBooking, $pdo): int {
        $insertBooking->execute([
            ':school' => $school,
            ':email' => $email,
            ':visit_date' => $date,
        ]);
        $id = (int) $pdo->lastInsertId();
        $pdo->exec(
            "INSERT INTO aanvraag_onderwijs_selecties
             (aanvraag_id,sector_key,sector_label,level_key,level_label,level_position,group_key,group_label,group_position)
             VALUES ({$id},'primairOnderwijs','Primair onderwijs','regulier','Regulier basisonderwijs',1,'groep7','Groep 7',1)",
        );
        return $id;
    };

    $expectCode = static function (
        callable $callback,
        string $code
    ) use ($assert): void {
        try {
            $callback();
            throw new RuntimeException("Expected {$code} was not thrown.");
        } catch (RosterPlanException $exception) {
            $assert(
                $exception->publicCode === $code,
                "Expected {$code}, got {$exception->publicCode}.",
            );
        }
    };

    // Happy path: same plan, same booking, same groups, custom authority.
    $bookingId = $createBooking(
        'D3C conversion school',
        'd3c-main@example.test',
        '2026-11-03',
    );
    $created = $service->createFromBooking($bookingId, 1);
    $before = $created['plan'];

    $planId = (int) $before['id'];
    $groupIdsBefore = array_column($before['groups'], 'id');
    $groupLabelsBefore = array_column($before['groups'], 'label');
    $moduleKeysBefore = array_column($before['planning']['modules'], 'key');

    $assert($before['planningMode'] === 'booking_locked', 'Fixture must start booking_locked.');
    $assert($before['bookingId'] === $bookingId, 'Fixture lost booking relation.');

    $converted = $service->convertBookingPlanToCustom(
        planId: $planId,
        expectedRevision: (int) $before['revision'],
        actingAdminId: 1,
    );

    $assert((int) $converted['id'] === $planId, 'Conversion changed the plan id.');
    $assert($converted['bookingId'] === $bookingId, 'Conversion changed booking_id.');
    $assert($converted['planningMode'] === 'custom', 'Conversion did not set custom authority.');
    $assert((int) $converted['revision'] === ((int) $before['revision']) + 1, 'Conversion must advance revision exactly once.');
    $assert($converted['needsReview'] === false, 'Linked custom plan must not inherit booking needsReview.');
    $assert($converted['school']['name'] === 'D3C conversion school', 'Manual context did not copy school.');
    $assert($converted['school']['city'] === 'Gorinchem', 'Manual context did not copy city.');
    $assert($converted['education']['sector'] === 'primairOnderwijs', 'Manual context did not copy sector.');
    $assert($converted['education']['program'] === 'dag', 'Manual context did not copy program.');
    $assert($converted['education']['studentCount'] === 90, 'Manual context did not copy student count.');
    $assert(array_column($converted['groups'], 'id') === $groupIdsBefore, 'Group ids changed during conversion.');
    $assert(array_column($converted['groups'], 'label') === $groupLabelsBefore, 'Group labels changed during conversion.');
    $assert(array_column($converted['planning']['modules'], 'key') === $moduleKeysBefore, 'Program modules changed during conversion.');
    $assert(($converted['planning']['roundsSource'] ?? null) === 'default', 'Conversion should start with derived default timeline.');

    $context = $plans->manualContextForPlan($planId);
    $assert(is_array($context), 'Manual context was not created.');
    $assert($context['school_name'] === 'D3C conversion school', 'Persisted manual school is wrong.');
    $assert($context['city'] === 'Gorinchem', 'Persisted manual city is wrong.');
    $assert($context['sector_key'] === 'primairOnderwijs', 'Persisted manual sector is wrong.');
    $assert($context['program_key'] === 'dag', 'Persisted manual program is wrong.');
    $assert((int) $context['student_count'] === 90, 'Persisted manual student count is wrong.');
    $assert($context['rounds_json'] === null, 'Conversion should not silently persist a custom timeline.');
    $assert(
        json_decode((string) $context['module_keys_json'], true, 32, JSON_THROW_ON_ERROR) === $moduleKeysBefore,
        'Persisted manual module list does not match booking program.',
    );

    $row = $pdo->query(
        "SELECT booking_id, planning_mode, visit_date, revision, source_booking_fingerprint
         FROM roster_plans
         WHERE id = {$planId}",
    )->fetch(PDO::FETCH_ASSOC);
    $assert(is_array($row), 'Converted roster row missing.');
    $assert((int) $row['booking_id'] === $bookingId, 'Database booking_id changed.');
    $assert($row['planning_mode'] === 'custom', 'Database planning_mode is not custom.');
    $assert($row['visit_date'] === '2026-11-03', 'Converted visit_date does not match current booking.');
    $assert($row['source_booking_fingerprint'] === null, 'Custom plan should no longer keep booking authority fingerprint.');

    // The unique booking relation still prevents a second plan.
    $reopened = $service->createFromBooking($bookingId, 1);
    $assert($reopened['created'] === false, 'Same booking unexpectedly created a second plan.');
    $assert((int) $reopened['plan']['id'] === $planId, 'Same booking did not resolve to the converted plan.');
    $assert(
        (int) $pdo->query("SELECT COUNT(*) FROM roster_plans WHERE booking_id = {$bookingId}")->fetchColumn() === 1,
        'More than one roster exists for the booking.',
    );

    // Programma must now be editable even though booking_id is still present.
    $editableModuleKeys = \GeoFort\Booking\BookingProgramConfig::getStandardModulesForSelection(
        'primairOnderwijs',
        'dag',
    );
    $assert(
        count($editableModuleKeys) === 4,
        'Current PO day defaults must provide four editable modules.',
    );
    $saved = $service->saveManualProgram(
        planId: $planId,
        expectedRevision: (int) $converted['revision'],
        moduleKeys: $editableModuleKeys,
        actingAdminId: 1,
        timeline: null,
    );
    $assert((int) $saved['revision'] === ((int) $converted['revision']) + 1, 'Linked custom Programma save is still blocked.');
    $assert(
        array_column($saved['planning']['modules'], 'key') === $editableModuleKeys,
        'Linked custom Programma did not accept the current editable module set.',
    );

    $expectCode(
        fn () => $service->convertBookingPlanToCustom($planId, (int) $saved['revision'], 1),
        'ROSTER_CONVERSION_NOT_BOOKING_LOCKED',
    );

    // Revision conflict must roll back without creating manual context.
    $revisionBooking = $createBooking(
        'D3C revision school',
        'd3c-revision@example.test',
        '2026-11-04',
    );
    $revisionPlan = $service->createFromBooking($revisionBooking, 1)['plan'];
    $expectCode(
        fn () => $service->convertBookingPlanToCustom(
            (int) $revisionPlan['id'],
            999,
            1,
        ),
        'REVISION_CONFLICT',
    );
    $revisionRow = $plans->findById((int) $revisionPlan['id']);
    $assert(($revisionRow['planning_mode'] ?? null) === 'booking_locked', 'Revision rejection changed planning mode.');
    $assert($plans->manualContextForPlan((int) $revisionPlan['id']) === null, 'Revision rejection left manual context behind.');

    // Non-concept plans are not converted.
    $lockedBooking = $createBooking(
        'D3C locked school',
        'd3c-locked@example.test',
        '2026-11-05',
    );
    $lockedPlan = $service->createFromBooking($lockedBooking, 1)['plan'];
    $pdo->exec("UPDATE roster_plans SET status = 'checked' WHERE id = " . (int) $lockedPlan['id']);
    $expectCode(
        fn () => $service->convertBookingPlanToCustom(
            (int) $lockedPlan['id'],
            (int) $lockedPlan['revision'],
            1,
        ),
        'ROSTER_CONVERSION_LOCKED',
    );
    $lockedRow = $plans->findById((int) $lockedPlan['id']);
    $assert(($lockedRow['planning_mode'] ?? null) === 'booking_locked', 'Locked rejection changed planning mode.');
    $assert($plans->manualContextForPlan((int) $lockedPlan['id']) === null, 'Locked rejection left manual context behind.');

    // Plans with sessions are not silently destroyed or converted.
    $sessionBooking = $createBooking(
        'D3C session school',
        'd3c-session@example.test',
        '2026-11-06',
    );
    $sessionPlan = $service->createFromBooking($sessionBooking, 1)['plan'];
    $sessionId = $sessions->insertActivity(
        (int) $sessionPlan['id'],
        'Dynamische-Globe',
        '10:00',
        '10:45',
        null,
        1,
    );
    $assert($sessionId > 0, 'Session fixture was not created.');

    $expectCode(
        fn () => $service->convertBookingPlanToCustom(
            (int) $sessionPlan['id'],
            (int) $sessionPlan['revision'],
            1,
        ),
        'ROSTER_CONVERSION_HAS_SESSIONS',
    );
    $sessionRow = $plans->findById((int) $sessionPlan['id']);
    $assert(($sessionRow['planning_mode'] ?? null) === 'booking_locked', 'Session rejection changed planning mode.');
    $assert($plans->manualContextForPlan((int) $sessionPlan['id']) === null, 'Session rejection left manual context behind.');
    $assert(
        count($sessions->findByPlanId((int) $sessionPlan['id'])) === 1,
        'Conversion rejection deleted an existing session.',
    );

    fwrite(
        STDOUT,
        "OK: D3C1 booking_locked -> custom MariaDB integration passed.\n",
    );
} finally {
    $database->drop();
}

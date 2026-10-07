<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';
require __DIR__ . '/Support/DisposableBookingMariaDb.php';

use GeoFort\Booking\Stored\StoredBookingAssembler;
use GeoFort\Services\Booking\Availability\BookingAvailabilityService;
use GeoFort\Services\Booking\Roster\RosterGroupCountResolver;
use GeoFort\Services\Dashboard\Roster\RosterGenerationTemplateProvider;
use GeoFort\Services\Dashboard\Roster\RosterPlanException;
use GeoFort\Services\Dashboard\Roster\RosterPlanningConfig;
use GeoFort\Services\Dashboard\Roster\RosterPlanService;
use GeoFort\Services\Sql\BookingCalendarSqlService;
use GeoFort\Services\Sql\DisabledDatesSqlService;
use GeoFort\Services\Sql\RosterPlanSqlRepository;
use GeoFort\Services\Sql\StoredBookingSqlRepository;

$disposable = DisposableBookingMariaDb::create('roster_planning_mode_v8b2d2b2b');
$pdo = $disposable->pdo;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

try {
    $root = dirname(__DIR__, 2);

    foreach ([
        '2026-09-21_create_roster_planning_foundation.sql',
        '2026-09-26_create_roster_manual_plan_context.sql',
        '2026-09-26_add_roster_manual_program_modules.sql',
        '2026-09-26_add_roster_manual_rounds.sql',
    ] as $migration) {
        $pdo->exec((string) file_get_contents(
            $root . '/database/sql/' . $migration,
        ));
    }

    $insert = $pdo->prepare(<<<'SQL'
        INSERT INTO aanvragen (
            schoolnaam,
            land,
            adres,
            postcode,
            plaats,
            school_telefoonnummer,
            contactpersoon_telefoonnummer,
            contactpersoon_voornaam,
            contactpersoon_achternaam,
            email,
            bezoekdatum,
            onderwijs_sector,
            programma,
            keuzemodule_key,
            aantal_leerlingen
        ) VALUES (
            :school,
            'Nederland',
            'Testweg 1',
            '1234 AB',
            'Gorinchem',
            '0100000000',
            '0612345678',
            'Test',
            'Planner',
            :email,
            :visit_date,
            'primairOnderwijs',
            'dag',
            'Earth-Watch',
            70
        )
        SQL);

    $insert->execute([
        ':school' => 'Originele aanvraag A',
        ':email' => 'd2b2b-a@example.test',
        ':visit_date' => '2026-11-12',
    ]);
    $bookingCustom = (int) $pdo->lastInsertId();

    $insert->execute([
        ':school' => 'Originele aanvraag B',
        ':email' => 'd2b2b-b@example.test',
        ':visit_date' => '2026-11-13',
    ]);
    $bookingLocked = (int) $pdo->lastInsertId();

    $service = new RosterPlanService(
        $pdo,
        new StoredBookingSqlRepository($pdo, new StoredBookingAssembler()),
        new RosterPlanSqlRepository($pdo),
        new RosterGroupCountResolver(),
        null,
        new RosterPlanningConfig(),
        new RosterGenerationTemplateProvider(),
        null,
        new BookingAvailabilityService(
            new BookingCalendarSqlService($pdo),
            new DisabledDatesSqlService($pdo),
        ),
    );

    $custom = $service->createManual(
        schoolName: 'Maatwerk School A',
        city: 'Maatwerkstad',
        visitDate: '2026-11-12',
        sector: 'primairOnderwijs',
        program: 'dag',
        studentCount: 64,
        groupCount: 4,
        actingAdminId: 1,
        bookingId: $bookingCustom,
    );

    $customPlan = $custom['plan'];

    $assert(
        $customPlan['planningMode'] === 'custom',
        'Linked custom plan is not custom.',
    );
    $assert(
        $customPlan['bookingId'] === $bookingCustom,
        'Linked custom plan lost booking relation.',
    );

    $saved = $service->saveManualProgram(
        (int) $customPlan['id'],
        (int) $customPlan['revision'],
        ['Klimaat-Experience', 'Klimparcours'],
        1,
        null,
    );

    $assert(
        count($saved['planning']['modules']) === 2,
        'Custom linked plan is still blocked from Programma.',
    );

    try {
        $service->createManual(
            schoolName: 'Dubbele koppeling',
            city: 'Gorinchem',
            visitDate: '2026-11-12',
            sector: 'primairOnderwijs',
            program: 'dag',
            studentCount: 50,
            groupCount: 4,
            actingAdminId: 1,
            bookingId: $bookingCustom,
        );

        throw new RuntimeException(
            'A second roster was incorrectly allowed for the same booking.',
        );
    } catch (RosterPlanException $exception) {
        $assert(
            $exception->publicCode === 'BOOKING_ALREADY_HAS_ROSTER',
            'Duplicate booking link returned the wrong public code.',
        );
    }

    $locked = $service->createFromBooking($bookingLocked, 1);
    $lockedPlan = $locked['plan'];

    $assert(
        $lockedPlan['planningMode'] === 'booking_locked',
        'Booking-created plan is not booking_locked.',
    );

    try {
        $service->saveManualProgram(
            (int) $lockedPlan['id'],
            (int) $lockedPlan['revision'],
            ['Klimaat-Experience', 'Klimparcours'],
            1,
            null,
        );

        throw new RuntimeException(
            'booking_locked plan incorrectly allowed Programma changes.',
        );
    } catch (RosterPlanException $exception) {
        $assert(
            $exception->publicCode === 'MANUAL_PROGRAM_ONLY',
            'booking_locked Programma guard returned wrong public code.',
        );
    }

    $listed = $service->listPlans();

    $customRow = null;
    $lockedRow = null;

    foreach ($listed as $row) {
        if ((int) $row['id'] === (int) $customPlan['id']) {
            $customRow = $row;
        }

        if ((int) $row['id'] === (int) $lockedPlan['id']) {
            $lockedRow = $row;
        }
    }

    $assert(
        is_array($customRow),
        'Linked custom plan is missing from listPlans().',
    );
    $assert(
        is_array($lockedRow),
        'booking_locked plan is missing from listPlans().',
    );

    $assert(
        $customRow['planningMode'] === 'custom',
        'Overview lost custom planning mode.',
    );
    $assert(
        $customRow['schoolName'] === 'Maatwerk School A',
        'Overview shows original booking school for linked custom plan.',
    );
    $assert(
        $customRow['city'] === 'Maatwerkstad',
        'Overview shows original booking city for linked custom plan.',
    );
    $assert(
        $customRow['studentCount'] === 64,
        'Overview shows original booking student count for linked custom plan.',
    );

    $assert(
        $lockedRow['planningMode'] === 'booking_locked',
        'Overview lost booking_locked planning mode.',
    );
    $assert(
        $lockedRow['schoolName'] === 'Originele aanvraag B',
        'booking_locked overview stopped using booking data.',
    );

    fwrite(
        STDOUT,
        "OK: V8B2D2B2B linked-custom list/guard integration passed.\n",
    );
} finally {
    $disposable->drop();
}
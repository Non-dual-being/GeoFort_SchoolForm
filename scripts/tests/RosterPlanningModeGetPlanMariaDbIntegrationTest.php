<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';
require __DIR__ . '/Support/DisposableBookingMariaDb.php';

use GeoFort\Booking\Stored\StoredBookingAssembler;
use GeoFort\Services\Booking\Availability\BookingAvailabilityService;
use GeoFort\Services\Booking\Roster\RosterGroupCountResolver;
use GeoFort\Services\Dashboard\Roster\RosterGenerationTemplateProvider;
use GeoFort\Services\Dashboard\Roster\RosterPlanningConfig;
use GeoFort\Services\Dashboard\Roster\RosterPlanService;
use GeoFort\Services\Sql\BookingCalendarSqlService;
use GeoFort\Services\Sql\DisabledDatesSqlService;
use GeoFort\Services\Sql\RosterPlanSqlRepository;
use GeoFort\Services\Sql\StoredBookingSqlRepository;

$disposable = DisposableBookingMariaDb::create('roster_planning_mode_v8b2d2b1');
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
        ':school' => 'Originele aanvraag',
        ':email' => 'd2b1-custom@example.test',
        ':visit_date' => '2026-11-12',
    ]);
    $bookingCustom = (int) $pdo->lastInsertId();

    $insert->execute([
        ':school' => 'Booking locked school',
        ':email' => 'd2b1-locked@example.test',
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
        schoolName: 'Aangepaste roosternaam',
        city: 'Nieuwe plaats',
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
        'Linked custom plan is not returned as custom.',
    );
    $assert(
        $customPlan['bookingId'] === $bookingCustom,
        'Linked custom plan lost booking relation.',
    );
    $assert(
        $customPlan['school']['name'] === 'Aangepaste roosternaam',
        'Original booking school still overrides custom school.',
    );
    $assert(
        $customPlan['school']['city'] === 'Nieuwe plaats',
        'Original booking city still overrides custom city.',
    );
    $assert(
        $customPlan['education']['studentCount'] === 64,
        'Original booking student count still overrides custom value.',
    );
    $assert(
        $customPlan['sourceCurrent'] === true,
        'Custom context should be current.',
    );
    $assert(
        $customPlan['needsReview'] === false,
        'Linked custom plan must not use booking fingerprint review.',
    );

    $reopened = $service->getPlan((int) $customPlan['id']);

    $assert(is_array($reopened), 'Linked custom plan could not be reopened.');
    $assert(
        $reopened['school']['name'] === 'Aangepaste roosternaam',
        'Reopened custom plan lost custom school.',
    );
    $assert(
        $reopened['planningMode'] === 'custom',
        'Reopened custom plan lost planning mode.',
    );

    $locked = $service->createFromBooking($bookingLocked, 1);
    $lockedPlan = $locked['plan'];

    $assert(
        $lockedPlan['planningMode'] === 'booking_locked',
        'Booking-created plan is not booking_locked.',
    );
    $assert(
        $lockedPlan['school']['name'] === 'Booking locked school',
        'booking_locked plan no longer uses booking data.',
    );

    fwrite(
        STDOUT,
        "OK: V8B2D2B1 linked-custom getPlan integration passed.\n",
    );
} finally {
    $disposable->drop();
}
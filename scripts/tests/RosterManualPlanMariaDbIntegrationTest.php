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

$disposable = DisposableBookingMariaDb::create('roster_manual_plan_v8b1');
$pdo = $disposable->pdo;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};

try {
    $root = dirname(__DIR__, 2);
    $pdo->exec((string) file_get_contents(
        $root . '/database/sql/2026-09-21_create_roster_planning_foundation.sql',
    ));
    $pdo->exec((string) file_get_contents(
        $root . '/database/sql/2026-09-26_create_roster_manual_plan_context.sql',
    ));

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

    $created = $service->createManual(
        schoolName: 'Zelfstandige Testschool',
        city: 'Gorinchem',
        visitDate: '2026-11-12',
        sector: 'primairOnderwijs',
        program: 'dag',
        studentCount: 90,
        groupCount: 6,
        actingAdminId: 1,
    );

    $assert($created['created'] === true, 'Handmatig rooster werd niet als nieuw gemarkeerd.');

    $plan = $created['plan'];
    $assert($plan['bookingId'] === null, 'Handmatig rooster kreeg ten onrechte een aanvraagkoppeling.');
    $assert($plan['school']['name'] === 'Zelfstandige Testschool', 'Schoolnaam ontbreekt.');
    $assert($plan['school']['city'] === 'Gorinchem', 'Plaats ontbreekt.');
    $assert($plan['education']['sector'] === 'primairOnderwijs', 'Sector ontbreekt.');
    $assert($plan['education']['program'] === 'dag', 'Programma ontbreekt.');
    $assert($plan['education']['studentCount'] === 90, 'Leerlingaantal ontbreekt.');
    $assert(count($plan['groups']) === 6, 'Handmatig groepsaantal werd niet opgeslagen.');
    $assert(count($plan['planning']['modules']) === 4, 'V8B1 moet de huidige standaardmodules nog kunnen teruglezen.');

    $context = $pdo->query(
        'SELECT school_name, city, sector_key, program_key, student_count, day_start_time, day_end_time
         FROM roster_manual_plan_context LIMIT 1',
    )->fetch(PDO::FETCH_ASSOC);

    $assert(is_array($context), 'Handmatige context ontbreekt in de database.');
    $assert($context['school_name'] === 'Zelfstandige Testschool', 'Handmatige context heeft verkeerde schoolnaam.');
    $assert(substr((string) $context['day_start_time'], 0, 5) === '10:00', 'Standaard starttijd ontbreekt.');
    $assert(substr((string) $context['day_end_time'], 0, 5) === '15:00', 'Standaard eindtijd ontbreekt.');

    $listed = $service->listPlans();
    $assert(count($listed) === 1, 'Handmatig rooster ontbreekt in rooster-overzicht.');
    $assert($listed[0]['schoolName'] === 'Zelfstandige Testschool', 'Roosteroverzicht gebruikt handmatige schoolnaam niet.');
    $assert($listed[0]['bookingId'] === null, 'Roosteroverzicht markeert handmatig rooster niet als los.');

    try {
        $service->createManual(
            schoolName: 'Ongeldig',
            city: '',
            visitDate: '2026-11-13',
            sector: 'primairOnderwijs',
            program: 'dag',
            studentCount: 90,
            groupCount: 0,
            actingAdminId: 1,
        );
        throw new RuntimeException('Ongeldig groepsaantal werd ten onrechte geaccepteerd.');
    } catch (RosterPlanException $exception) {
        $assert(
            $exception->publicCode === 'INVALID_MANUAL_GROUP_COUNT',
            'Onverwachte foutcode voor ongeldig handmatig groepsaantal.',
        );
    }

    try {
        $service->createManual(
            schoolName: 'VO ochtend ongeldig',
            city: 'Gorinchem',
            visitDate: '2026-11-11',
            sector: 'voortgezetOnderbouw',
            program: 'ochtend',
            studentCount: 60,
            groupCount: 4,
            actingAdminId: 1,
        );
        throw new RuntimeException('Ochtendprogramma werd ten onrechte voor voortgezet onderwijs geaccepteerd.');
    } catch (RosterPlanException $exception) {
        $assert(
            $exception->publicCode === 'INVALID_MANUAL_PROGRAM_FOR_SECTOR',
            'Onverwachte foutcode voor sector/programmacombinatie.',
        );
    }

    $pdo->exec(
        "INSERT INTO disabled_dates (datum, type, reden, source)
         VALUES ('2026-11-16', 'manual', 'V8B2A1 testblokkade', 'manual')",
    );

    try {
        $service->createManual(
            schoolName: 'Geblokkeerde datum',
            city: 'Gorinchem',
            visitDate: '2026-11-16',
            sector: 'primairOnderwijs',
            program: 'dag',
            studentCount: 60,
            groupCount: 4,
            actingAdminId: 1,
        );
        throw new RuntimeException('Geblokkeerde bezoekdatum werd ten onrechte geaccepteerd.');
    } catch (RosterPlanException $exception) {
        $assert(
            $exception->publicCode === 'MANUAL_VISIT_DATE_UNAVAILABLE',
            'Onverwachte foutcode voor geblokkeerde bezoekdatum.',
        );
    }

    fwrite(STDOUT, "OK: manual roster V8B2A1 availability + program rules integration passed.\n");
} finally {
    $disposable->drop();
}

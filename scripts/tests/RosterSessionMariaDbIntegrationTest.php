<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';
require __DIR__ . '/Support/DisposableBookingMariaDb.php';

use GeoFort\Booking\Stored\StoredBookingAssembler;
use GeoFort\Services\Booking\Roster\RosterGroupCountResolver;
use GeoFort\Services\Dashboard\Roster\RosterPlanException;
use GeoFort\Services\Dashboard\Roster\RosterPlanningConfig;
use GeoFort\Services\Dashboard\Roster\RosterPlanService;
use GeoFort\Services\Dashboard\Roster\RosterSessionService;
use GeoFort\Services\Sql\RosterPlanSqlRepository;
use GeoFort\Services\Sql\RosterSessionSqlRepository;
use GeoFort\Services\Sql\StoredBookingSqlRepository;

$disposable = DisposableBookingMariaDb::create('roster_sessions');
$pdo = $disposable->pdo;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};

try {
    $root = dirname(__DIR__, 2);
    $pdo->exec((string) file_get_contents($root . '/database/sql/2026-09-21_create_roster_planning_foundation.sql'));
    $pdo->exec((string) file_get_contents($root . '/database/sql/2026-09-21_create_roster_session_planning.sql'));

    $pdo->exec(<<<'SQL'
        INSERT INTO aanvragen (
            status, schoolnaam, land, adres, postcode, plaats,
            school_telefoonnummer, contactpersoon_telefoonnummer,
            contactpersoon_voornaam, contactpersoon_achternaam,
            email, bezoekdatum, cjpPasGebruik, onderwijs_sector,
            programma, keuzemodule_key, aantal_leerlingen,
            aantal_begeleiders, voorwaarden_akkoord
        ) VALUES (
            'Definitief', 'Run 2 testschool', 'Nederland', 'Testweg 2',
            '1234 AB', 'Gorinchem', '0100000000', '0612345678',
            'Test', 'Planner', 'run2@example.test', '2026-10-09',
            'nee', 'primairOnderwijs', 'dag', 'Minecraft-Windenergiespeurtocht', 100, 7, 1
        )
        SQL);
    $bookingId = (int) $pdo->lastInsertId();

    $pdo->exec(
        "INSERT INTO aanvraag_onderwijs_selecties
         (aanvraag_id,sector_key,sector_label,level_key,level_label,level_position,group_key,group_label,group_position)
         VALUES ({$bookingId},'primairOnderwijs','Primair onderwijs','regulier','Regulier basisonderwijs',1,'groep7','Groep 7',1)",
    );

    $bookings = new StoredBookingSqlRepository($pdo, new StoredBookingAssembler());
    $plans = new RosterPlanSqlRepository($pdo);
    $sessions = new RosterSessionSqlRepository($pdo);
    $config = new RosterPlanningConfig();

    $planService = new RosterPlanService(
        $pdo,
        $bookings,
        $plans,
        new RosterGroupCountResolver(),
        $sessions,
        $config,
    );
    $sessionService = new RosterSessionService($pdo, $bookings, $plans, $sessions, $config);

    $created = $planService->createFromBooking($bookingId, 1);
    $plan = $created['plan'];
    $groups = $plan['groups'];
    $assert(count($groups) === 6, 'Fixture moet zes roostergroepen hebben.');

    $moduleKeys = array_column($plan['planning']['modules'], 'key');
    $assert(
        in_array('Minecraft-Windenergiespeurtocht', $moduleKeys, true),
        'Opgeslagen historische keuzemodule ontbreekt in het roosterprogramma.',
    );
    $assert(
        count($moduleKeys) === 5,
        'Dagprogramma moet vier standaardmodules plus de opgeslagen keuzemodule bevatten.',
    );

    $first = $sessionService->saveActivity(
        $plan['id'],
        null,
        1,
        'Voedsel-Innovatie',
        '10:00',
        '10:45',
        null,
        [$groups[0]['id'], $groups[1]['id']],
        1,
    );
    $assert($first['revision'] === 2, 'Eerste sessie verhoogt revisie niet.');
    $assert((int) $pdo->query('SELECT COUNT(*) FROM roster_sessions')->fetchColumn() === 1, 'Sessie ontbreekt.');
    $assert((int) $pdo->query('SELECT COUNT(*) FROM roster_session_groups')->fetchColumn() === 2, 'Groepskoppelingen ontbreken.');

    try {
        $sessionService->saveActivity(
            $plan['id'],
            null,
            2,
            'Voedsel-Innovatie',
            '11:00',
            '11:45',
            null,
            [$groups[0]['id']],
            1,
        );
        throw new RuntimeException('Dubbele module per groep werd niet geblokkeerd.');
    } catch (RosterPlanException $exception) {
        $assert($exception->publicCode === 'DUPLICATE_MODULE_FOR_GROUP', 'Verkeerde duplicate-module fout.');
    }

    try {
        $sessionService->saveActivity(
            $plan['id'],
            null,
            2,
            'Dynamische-Globe',
            '10:30',
            '11:15',
            null,
            [$groups[0]['id']],
            1,
        );
        throw new RuntimeException('Groepsoverlap werd niet geblokkeerd.');
    } catch (RosterPlanException $exception) {
        $assert($exception->publicCode === 'GROUP_TIME_CONFLICT', 'Verkeerde groepsoverlap-fout.');
    }

    $second = $sessionService->saveActivity(
        $plan['id'], null, 2, 'Dynamische-Globe', '11:00', '11:45', null, [$groups[2]['id']], 1,
    );
    $third = $sessionService->saveActivity(
        $plan['id'], null, $second['revision'], 'Dynamische-Globe', '11:00', '11:45', null, [$groups[3]['id']], 1,
    );

    try {
        $sessionService->saveActivity(
            $plan['id'], null, $third['revision'], 'Dynamische-Globe', '11:00', '11:45', null, [$groups[4]['id']], 1,
        );
        throw new RuntimeException('Derde standaard parallelsessie werd niet geblokkeerd.');
    } catch (RosterPlanException $exception) {
        $assert($exception->publicCode === 'PARALLEL_SESSION_LIMIT', 'Verkeerde parallel-fout.');
    }

    $revision = $third['revision'];
    $climate1 = $sessionService->saveActivity(
        $plan['id'], null, $revision, 'Klimaat-Experience', '12:00', '12:45', null, [$groups[0]['id']], 1,
    );
    $climate2 = $sessionService->saveActivity(
        $plan['id'], null, $climate1['revision'], 'Klimaat-Experience', '12:00', '12:45', null, [$groups[1]['id']], 1,
    );
    $climate3 = $sessionService->saveActivity(
        $plan['id'], null, $climate2['revision'], 'Klimaat-Experience', '12:00', '12:45', null, [$groups[2]['id']], 1,
    );
    $assert($climate3['revision'] > $climate2['revision'], 'Derde Klimaat Experience sessie moet toegestaan zijn.');

    $updated = $sessionService->saveActivity(
        $plan['id'],
        $first['sessionId'],
        $climate3['revision'],
        'Voedsel-Innovatie',
        '10:00',
        '10:45',
        'Auditorium',
        [$groups[0]['id'], $groups[1]['id'], $groups[5]['id']],
        1,
    );
    $linked = (int) $pdo->query(
        'SELECT COUNT(*) FROM roster_session_groups WHERE roster_session_id=' . (int) $first['sessionId'],
    )->fetchColumn();
    $assert($linked === 3, 'Sessie-update vervangt groepskoppelingen niet.');

    $deleted = $sessionService->deleteActivity(
        $plan['id'],
        $first['sessionId'],
        $updated['revision'],
        1,
    );
    $assert($deleted['revision'] === $updated['revision'] + 1, 'Delete verhoogt revisie niet.');
    $assert(
        (int) $pdo->query('SELECT COUNT(*) FROM roster_sessions WHERE id=' . (int) $first['sessionId'])->fetchColumn() === 0,
        'Verwijderde sessie bestaat nog.',
    );

    fwrite(STDOUT, "OK: roster session MariaDB integration passed.\n");
} finally {
    $disposable->drop();
}
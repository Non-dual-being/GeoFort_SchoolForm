<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';
require __DIR__ . '/Support/DisposableBookingMariaDb.php';

use GeoFort\Booking\Stored\StoredBookingAssembler;
use GeoFort\Services\Booking\Roster\RosterGroupCountResolver;
use GeoFort\Services\Dashboard\Roster\RosterGenerationTemplateProvider;
use GeoFort\Services\Dashboard\Roster\RosterPlanningConfig;
use GeoFort\Services\Dashboard\Roster\RosterPlanException;
use GeoFort\Services\Dashboard\Roster\RosterPlanService;
use GeoFort\Services\Dashboard\Roster\RosterSessionService;
use GeoFort\Services\Sql\RosterPlanSqlRepository;
use GeoFort\Services\Sql\RosterSessionSqlRepository;
use GeoFort\Services\Sql\RosterStaffSqlRepository;
use GeoFort\Services\Sql\StoredBookingSqlRepository;

$disposable = DisposableBookingMariaDb::create('roster_manual_staffing');
$pdo = $disposable->pdo;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};

try {
    $root = dirname(__DIR__, 2);
    foreach ([
        '/database/sql/2026-09-21_create_roster_planning_foundation.sql',
        '/database/sql/2026-09-21_create_roster_session_planning.sql',
        '/database/sql/2026-09-22_create_roster_staff_catalog.sql',
        '/database/sql/2026-09-22_create_roster_staff_assignments.sql',
        '/database/sql/2026-09-22_extend_roster_staff_roles.sql',
    ] as $migration) {
        $pdo->exec((string) file_get_contents($root . $migration));
    }

    $pdo->exec(<<<'SQL'
        INSERT INTO aanvragen (
            status, schoolnaam, land, adres, postcode, plaats,
            school_telefoonnummer, contactpersoon_telefoonnummer,
            contactpersoon_voornaam, contactpersoon_achternaam,
            email, bezoekdatum, cjpPasGebruik, onderwijs_sector,
            programma, keuzemodule_key, aantal_leerlingen,
            aantal_begeleiders, voorwaarden_akkoord
        ) VALUES (
            'Definitief', 'Handmatige testschool', 'Nederland', 'Testweg 5',
            '1234 AB', 'Gorinchem', '1', '2', 'Joelle', 'Planner',
            'manual@example.test', '2026-10-20', 'nee',
            'primairOnderwijs', 'dag', 'Earth-Watch', 90, 6, 1
        )
        SQL);
    $bookingId = (int) $pdo->lastInsertId();

    $pdo->exec(
        "INSERT INTO aanvraag_onderwijs_selecties (
            aanvraag_id,sector_key,sector_label,level_key,level_label,
            level_position,group_key,group_label,group_position
        ) VALUES (
            {$bookingId},'primairOnderwijs','Primair onderwijs','groep7','Groep 7',
            1,'groep7','Groep 7',1
        )",
    );

    $bookings = new StoredBookingSqlRepository($pdo, new StoredBookingAssembler());
    $plans = new RosterPlanSqlRepository($pdo);
    $sessions = new RosterSessionSqlRepository($pdo);
    $staff = new RosterStaffSqlRepository($pdo);
    $config = new RosterPlanningConfig();
    $templates = new RosterGenerationTemplateProvider();

    $planService = new RosterPlanService(
        $pdo,
        $bookings,
        $plans,
        new RosterGroupCountResolver(),
        $sessions,
        $config,
        $templates,
        $staff,
    );
    $sessionService = new RosterSessionService(
        $pdo,
        $bookings,
        $plans,
        $sessions,
        $config,
        $staff,
    );

    $plan = $planService->createFromBooking($bookingId, 1)['plan'];
    $groups = $plan['groups'];
    $assert(count($groups) >= 3, 'Testrooster heeft onvoldoende groepen.');

    $catalog = $staff->catalog();
    $byName = [];
    foreach ($catalog as $member) $byName[$member['name']] = $member;

    $nellekeId = (int) $byName['Nelleke de With']['id'];
    $maykeId = (int) $byName['Mayke van den Boom']['id'];
    $frankId = (int) $byName['Frank Duijnhouwer']['id'];

    $staff->savePlanSettings($plan['id'], 'lesson_only', true, null);

    // Alleen-lesrooster: sessie zonder personeel moet opgeslagen kunnen worden.
    $vi = $sessionService->saveActivity(
        $plan['id'],
        null,
        1,
        'Voedsel-Innovatie',
        '10:15',
        '11:00',
        'Auditorium',
        [(int) $groups[0]['id']],
        1,
        [],
        null,
    );
    $assert($vi['revision'] === 2, 'Alleen-lesrooster sessie werd niet opgeslagen.');
    $assert($staff->settingsForPlan($plan['id'])['staffingMode'] === 'lesson_only', 'Les-only modus veranderde onterecht.');

    // Later vanuit Handmatige correcties personeel + kok invullen.
    $viWithStaff = $sessionService->saveActivity(
        $plan['id'],
        $vi['sessionId'],
        2,
        'Voedsel-Innovatie',
        '10:15',
        '11:00',
        'Auditorium',
        [(int) $groups[0]['id']],
        1,
        [$maykeId],
        $nellekeId,
    );
    $assert($viWithStaff['revision'] === 3, 'Personeel kon niet later worden toegevoegd.');
    $settings = $staff->settingsForPlan($plan['id']);
    $assert($settings['staffingMode'] === 'with_staff', 'Handmatige personeelsinvulling schakelt modus niet om.');
    $assert($settings['cookStaffId'] === $nellekeId, 'Nelleke werd niet als kok opgeslagen.');

    // Nelleke is hard cook-only en mag nooit als begeleider worden geaccepteerd.
    try {
        $sessionService->saveActivity(
            $plan['id'],
            $vi['sessionId'],
            3,
            'Voedsel-Innovatie',
            '10:15',
            '11:00',
            'Auditorium',
            [(int) $groups[0]['id']],
            1,
            [$nellekeId],
            $nellekeId,
        );
        throw new RuntimeException('Nelleke werd ten onrechte als begeleider geaccepteerd.');
    } catch (RosterPlanException $exception) {
        $assert(
            in_array($exception->publicCode, ['STAFF_NOT_GUIDE', 'STAFF_COOK_CONFLICT'], true),
            'Onverwachte foutcode voor cook-only medewerker.',
        );
    }

    // Een ongeschikte docent op een module is een harde blokkade.
    try {
        $sessionService->saveActivity(
            $plan['id'],
            null,
            3,
            'Dynamische-Globe',
            '11:15',
            '12:00',
            null,
            [(int) $groups[1]['id']],
            1,
            [$maykeId],
            $nellekeId,
        );
        throw new RuntimeException('Ongeschikte docent werd ten onrechte geaccepteerd.');
    } catch (RosterPlanException $exception) {
        $assert($exception->publicCode === 'STAFF_MODULE_NOT_ALLOWED', 'Verkeerde foutcode bij ongeschikte docent.');
    }

    $dg = $sessionService->saveActivity(
        $plan['id'],
        null,
        3,
        'Dynamische-Globe',
        '11:15',
        '12:00',
        null,
        [(int) $groups[1]['id']],
        1,
        [$frankId],
        $nellekeId,
    );
    $assert($dg['revision'] === 4, 'Geldige DG-correctie kon niet worden opgeslagen.');

    // Een lang gat is soft: opslaan mag, maar er moet gewaarschuwd worden.
    $ke = $sessionService->saveActivity(
        $plan['id'],
        null,
        4,
        'Klimaat-Experience',
        '13:15',
        '14:00',
        null,
        [(int) $groups[2]['id']],
        1,
        [$frankId],
        $nellekeId,
    );
    $warningCodes = array_column($ke['warnings'], 'code');
    $assert(in_array('STAFF_GAP', $warningCodes, true), 'Tussenuur levert geen zachte waarschuwing op.');

    fwrite(STDOUT, "OK: roster manual staffing MariaDB integration passed.\n");
} finally {
    $disposable->drop();
}

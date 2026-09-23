<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';
require __DIR__ . '/Support/DisposableBookingMariaDb.php';

use GeoFort\Booking\Stored\StoredBookingAssembler;
use GeoFort\Services\Booking\Roster\RosterGroupCountResolver;
use GeoFort\Services\Dashboard\Roster\RosterAutoGenerator;
use GeoFort\Services\Dashboard\Roster\RosterGenerationTemplateProvider;
use GeoFort\Services\Dashboard\Roster\RosterPlanningConfig;
use GeoFort\Services\Dashboard\Roster\RosterPlanService;
use GeoFort\Services\Sql\RosterPlanSqlRepository;
use GeoFort\Services\Sql\RosterSessionSqlRepository;
use GeoFort\Services\Sql\StoredBookingSqlRepository;

$disposable = DisposableBookingMariaDb::create('roster_generator');
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
            'Definitief', 'Generator testschool', 'Nederland', 'Testweg 3',
            '1234 AB', 'Gorinchem', '0100000000', '0612345678',
            'Test', 'Planner', 'generator@example.test', '2026-10-12',
            'nee', 'voortgezetOnderbouw', 'dag', 'Minecraft-Klimaatspeurtocht', 90, 7, 1
        )
        SQL);
    $bookingId = (int) $pdo->lastInsertId();

    $pdo->exec(
        "INSERT INTO aanvraag_onderwijs_selecties
         (aanvraag_id,sector_key,sector_label,level_key,level_label,level_position,group_key,group_label,group_position)
         VALUES ({$bookingId},'voortgezetOnderbouw','VO onderbouw','havo','HAVO',1,'havo1','HAVO 1',1)",
    );

    $bookings = new StoredBookingSqlRepository($pdo, new StoredBookingAssembler());
    $plans = new RosterPlanSqlRepository($pdo);
    $sessions = new RosterSessionSqlRepository($pdo);
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
    );
    $generator = new RosterAutoGenerator($pdo, $bookings, $plans, $sessions, $config, $templates);

    $created = $planService->createFromBooking($bookingId, 1);
    $plan = $created['plan'];
    $assert(count($plan['groups']) === 6, '90 leerlingen moeten zes groepen geven.');
    $assert(count($plan['planning']['modules']) === 5, 'Historische keuze moet vijf modules opleveren.');

    $rounds = $templates->defaultRounds('dag');
    $preview = $generator->preview($plan['id'], $rounds);

    $assert(count($preview['sessions']) === 30, 'Zes groepen maal vijf rondes moet dertig sessies geven.');
    $assert($preview['existingSessionCount'] === 0, 'Nieuwe planning heeft onverwacht bestaande sessies.');
    $assert($preview['staffing']['minimumSimultaneousGeoFortStaff'] >= 1, 'Personeelsbehoefte is niet afgeleid.');

    $coverage = [];
    foreach ($preview['sessions'] as $session) {
        $groupId = $session['groupIds'][0];
        $coverage[$groupId] ??= [];
        $coverage[$groupId][] = $session['moduleKey'];
    }
    foreach ($coverage as $modules) {
        $assert(count(array_unique($modules)) === 5, 'Een groep krijgt niet vijf unieke modules.');
    }

    $applied = $generator->apply($plan['id'], 1, $rounds, false, 1);
    $assert($applied['revision'] === 2, 'Automatisch toepassen verhoogt revisie niet.');
    $assert($applied['sessionCount'] === 30, 'Niet alle gegenereerde sessies zijn toegepast.');
    $assert((int) $pdo->query('SELECT COUNT(*) FROM roster_sessions')->fetchColumn() === 30, 'Database bevat niet dertig sessies.');
    $assert((int) $pdo->query('SELECT COUNT(*) FROM roster_session_groups')->fetchColumn() === 30, 'Iedere gegenereerde sessie moet een groepskoppeling hebben.');

    $persisted = $planService->getPlan($plan['id']);
    $assert(is_array($persisted), 'Toegepast rooster kon niet worden teruggelezen.');
    $persistedSessions = $persisted['planning']['sessions'];
    $food = array_values(array_filter(
        $persistedSessions,
        static fn (array $session): bool => $session['moduleKey'] === 'Voedsel-Innovatie',
    ));
    $climate = array_values(array_filter(
        $persistedSessions,
        static fn (array $session): bool => $session['moduleKey'] === 'Klimaat-Experience',
    ));
    $assert(($food[0]['minimumGeoFortStaff'] ?? null) === 1, 'Opgeslagen Voedsel-sessie mist personeelsbehoefte.');
    $assert(($food[0]['schoolSupervisionAllowed'] ?? null) === false, 'Voedsel-sessie krijgt onterecht schoolbegeleiding.');
    $assert(($climate[0]['minimumGeoFortStaff'] ?? null) === 0, 'Opgeslagen Klimaat-sessie vraagt onterecht GeoFort-personeel.');
    $assert(($climate[0]['schoolSupervisionAllowed'] ?? null) === true, 'Opgeslagen Klimaat-sessie mist schoolbegeleidingsmogelijkheid.');

    try {
        $generator->apply($plan['id'], 2, $rounds, false, 1);
        throw new RuntimeException('Bestaande sessies werden zonder expliciete vervanging overschreven.');
    } catch (\GeoFort\Services\Dashboard\Roster\RosterPlanException $exception) {
        $assert($exception->publicCode === 'EXISTING_SESSIONS_REQUIRE_REPLACE', 'Verkeerde overwrite-beveiliging.');
    }

    $replaced = $generator->apply($plan['id'], 2, $rounds, true, 1);
    $assert($replaced['revision'] === 3, 'Expliciete regeneratie verhoogt revisie niet.');
    $assert((int) $pdo->query('SELECT COUNT(*) FROM roster_sessions')->fetchColumn() === 30, 'Regeneratie laat dubbele sessies achter.');

    fwrite(STDOUT, "OK: roster auto generator MariaDB integration passed.\n");
} finally {
    $disposable->drop();
}
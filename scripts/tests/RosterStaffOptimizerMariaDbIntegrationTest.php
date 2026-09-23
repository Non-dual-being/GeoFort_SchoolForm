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
use GeoFort\Services\Dashboard\Roster\RosterStaffOptimizer;
use GeoFort\Services\Sql\RosterPlanSqlRepository;
use GeoFort\Services\Sql\RosterSessionSqlRepository;
use GeoFort\Services\Sql\RosterStaffSqlRepository;
use GeoFort\Services\Sql\StoredBookingSqlRepository;

$disposable = DisposableBookingMariaDb::create('roster_staff_optimizer');
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

    $staff = new RosterStaffSqlRepository($pdo);
    $optimizer = new RosterStaffOptimizer($staff);

    // Gelijk werk voor betaald/vrijwilliger wanneer vaardigheden gelijk zijn.
    $pdo->exec(<<<'SQL'
        INSERT INTO roster_staff_members
            (display_name, is_active, employment_type, can_guide, can_cook)
        VALUES
            ('ZZ Test Paid', 1, 'paid', 1, 0),
            ('ZZ Test Volunteer', 1, 'volunteer', 1, 0)
        SQL);
    $paidId = (int) $pdo->query(
        "SELECT id FROM roster_staff_members WHERE display_name='ZZ Test Paid'",
    )->fetchColumn();
    $volunteerId = (int) $pdo->query(
        "SELECT id FROM roster_staff_members WHERE display_name='ZZ Test Volunteer'",
    )->fetchColumn();
    $preferenceInsert = $pdo->prepare(<<<'SQL'
        INSERT INTO roster_staff_module_preferences (staff_id, module_key, preference_rank)
        VALUES (:staff_id, 'Dynamische-Globe', 1)
        SQL);
    $preferenceInsert->execute([':staff_id' => $paidId]);
    $preferenceInsert->execute([':staff_id' => $volunteerId]);

    $synthetic = [];
    foreach ([
        ['10:15', '11:00'],
        ['11:15', '12:00'],
        ['12:00', '12:45'],
        ['13:15', '14:00'],
    ] as [$start, $end]) {
        $synthetic[] = [
            'moduleKey' => 'Dynamische-Globe',
            'startTime' => $start,
            'endTime' => $end,
            'minimumGeoFortStaff' => 1,
            'schoolSupervisionAllowed' => false,
        ];
    }

    $balanced = $optimizer->optimize($synthetic, [$paidId, $volunteerId], true, null);
    $minutesByName = [];
    foreach ($balanced['staff'] as $member) {
        $minutesByName[$member['name']] = $member['workMinutes'];
    }
    $assert(
        abs(($minutesByName['ZZ Test Paid'] ?? -999) - ($minutesByName['ZZ Test Volunteer'] ?? 999)) <= 45,
        'Betaalde kracht en vrijwilliger worden bij gelijke vaardigheden niet evenwichtig op werkminuten verdeeld.',
    );

    // Volledige roosterflow met kok + KE-voorkeur.
    $pdo->exec(<<<'SQL'
        INSERT INTO aanvragen (
            status, schoolnaam, land, adres, postcode, plaats,
            school_telefoonnummer, contactpersoon_telefoonnummer,
            contactpersoon_voornaam, contactpersoon_achternaam,
            email, bezoekdatum, cjpPasGebruik, onderwijs_sector,
            programma, keuzemodule_key, aantal_leerlingen,
            aantal_begeleiders, voorwaarden_akkoord
        ) VALUES (
            'Definitief', 'Personeel testschool', 'Nederland', 'Testweg 4',
            '1234 AB', 'Gorinchem', '1', '2', 'Test', 'Planner',
            'staff@example.test', '2026-10-13', 'nee',
            'voortgezetOnderbouw', 'dag', 'Minecraft-Klimaatspeurtocht',
            90, 7, 1
        )
        SQL);
    $bookingId = (int) $pdo->lastInsertId();
    $pdo->exec(
        "INSERT INTO aanvraag_onderwijs_selecties (
            aanvraag_id,sector_key,sector_label,level_key,level_label,
            level_position,group_key,group_label,group_position
        ) VALUES (
            {$bookingId},'voortgezetOnderbouw','VO onderbouw','havo','HAVO',
            1,'havo1','HAVO 1',1
        )",
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
        $staff,
    );
    $generator = new RosterAutoGenerator(
        $pdo,
        $bookings,
        $plans,
        $sessions,
        $config,
        $templates,
        $staff,
        $optimizer,
    );

    $plan = $planService->createFromBooking($bookingId, 1)['plan'];

    $names = [
        'Frank van Kessel',
        'Kevin Hink',
        'Marc Lagewaard',
        'Wouter van Berkel',
        'Kevin de Schepper',
    ];
    $catalog = $staff->catalog();
    $selected = [];
    $nellekeId = null;
    foreach ($catalog as $member) {
        if (in_array($member['name'], $names, true)) $selected[] = $member['id'];
        if ($member['name'] === 'Nelleke de With') $nellekeId = $member['id'];
    }

    $assert(count($selected) === 5, 'Testselectie personeel is niet compleet.');
    $assert(is_int($nellekeId), 'Nelleke ontbreekt.');

    $preview = $generator->preview(
        $plan['id'],
        $templates->defaultRounds('dag'),
        $selected,
        'with_staff',
        true,
        $nellekeId,
    );

    $assert($preview['staffing']['cookSelected'] === true, 'Kok wordt niet herkend.');
    $assert(($preview['staffing']['cook']['name'] ?? null) === 'Nelleke de With', 'Verkeerde kok in voorstel.');
    $assert($preview['staffing']['selectedStaffCount'] === 5, 'Begeleiderselectie klopt niet.');

    $applied = $generator->apply(
        $plan['id'],
        1,
        $templates->defaultRounds('dag'),
        false,
        1,
        $selected,
        'with_staff',
        true,
        $nellekeId,
    );
    $assert($applied['sessionCount'] === 30, 'Verwacht 30 legacy sessies voor zes groepen × vijf rondes.');

    $settings = $staff->settingsForPlan($plan['id']);
    $assert($settings['staffingMode'] === 'with_staff', 'Personeelsmodus niet opgeslagen.');
    $assert($settings['preferGeoFortKe'] === true, 'KE-voorkeur niet opgeslagen.');
    $assert($settings['cookStaffId'] === $nellekeId, 'Kok niet opgeslagen.');

    $invalidSkills = (int) $pdo->query(<<<'SQL'
        SELECT COUNT(*)
        FROM roster_session_staff rss
        INNER JOIN roster_sessions rs ON rs.id = rss.roster_session_id
        LEFT JOIN roster_staff_module_preferences pref
          ON pref.staff_id = rss.staff_id
         AND pref.module_key = rs.module_key
        WHERE pref.staff_id IS NULL
        SQL)->fetchColumn();
    $assert($invalidSkills === 0, 'Een medewerker is op een module gezet die hij/zij niet kan geven.');

    fwrite(STDOUT, "OK: roster staff optimizer MariaDB integration passed.\n");
} finally {
    $disposable->drop();
}

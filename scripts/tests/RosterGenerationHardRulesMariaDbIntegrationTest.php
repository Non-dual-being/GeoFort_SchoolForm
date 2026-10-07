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

$disposable = DisposableBookingMariaDb::create('roster_v4');
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
        '/database/sql/2026-09-25_create_roster_plan_staff_preferences.sql',
        '/database/sql/2026-10-01_create_roster_final_snapshots.sql',
        '/database/sql/2026-09-25_create_roster_session_group_staff.sql',
        '/database/sql/2026-10-01_add_roster_group_supervision.sql',
    ] as $migration) {
        $pdo->exec((string) file_get_contents($root . $migration));
    }
    require_once __DIR__ . '/Support/RosterStaffManagementFixtures.php';
    RosterStaffManagementFixtures::schemaAndBootstrap($pdo);

    $pdo->exec(<<<'SQL'
        INSERT INTO aanvragen (
            status, schoolnaam, land, adres, postcode, plaats,
            school_telefoonnummer, contactpersoon_telefoonnummer,
            contactpersoon_voornaam, contactpersoon_achternaam,
            email, bezoekdatum, cjpPasGebruik, onderwijs_sector,
            programma, keuzemodule_key, aantal_leerlingen,
            aantal_begeleiders, voorwaarden_akkoord
        ) VALUES (
            'Definitief', 'V4 testschool', 'Nederland', 'Testweg 6',
            '1234 AB', 'Gorinchem', '1', '2', 'Test', 'Planner',
            'v4@example.test', '2026-10-15', 'nee',
            'voortgezetOnderbouw', 'dag', 'Minecraft-Klimaatspeurtocht',
            90, 7, 1
        )
        SQL);

    $bookingId = (int) $pdo->lastInsertId();

    $pdo->exec(
        "INSERT INTO aanvraag_onderwijs_selecties (
            aanvraag_id, sector_key, sector_label, level_key, level_label,
            level_position, group_key, group_label, group_position
        ) VALUES (
            {$bookingId}, 'voortgezetOnderbouw', 'VO onderbouw',
            'havo', 'HAVO', 1, 'havo1', 'HAVO 1', 1
        )",
    );

    $bookings = new StoredBookingSqlRepository($pdo, new StoredBookingAssembler());
    $plans = new RosterPlanSqlRepository($pdo);
    $sessions = new RosterSessionSqlRepository($pdo);
    $staff = new RosterStaffSqlRepository($pdo);
    $config = new RosterPlanningConfig();
    $templates = new RosterGenerationTemplateProvider();
    $optimizer = new RosterStaffOptimizer($staff);

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

    $wantedNames = [
        'Marc Lagewaard',
        'Mayke van den Boom',
        'Nathalja Beens',
        'Kevin de Schepper',
    ];
    $selected = [];
    $nellekeId = null;

    foreach ($staff->catalog() as $member) {
        if (in_array($member['name'], $wantedNames, true)) {
            $selected[] = $member['id'];
        }

        if ($member['id'] === 20) {
            $nellekeId = $member['id'];
        }
    }

    $assert(count($selected) === 4, 'De vier begeleiders voor de praktijktest ontbreken.');
    $assert(is_int($nellekeId), 'Nelleke ontbreekt.');

    $preview = $generator->preview(
        $plan['id'],
        $templates->defaultRounds('dag'),
        $selected,
        'with_staff',
        true,
        $nellekeId,
    );

    $assert(count($preview['sessions']) > 0, 'De generator moet altijd een voorbeeldrooster teruggeven.');
    $assert($preview['canApply'] === true, 'Ieder gegenereerd voorstel moet als concept toepasbaar zijn.');
    $assert(isset($preview['canFinalize']), 'canFinalize ontbreekt uit previewcontract.');

    // Harde modulecapaciteit = AANTAL GROEPEN per module/tijdvak.
    $usage = [];
    foreach ($preview['sessions'] as $session) {
        $key = $session['startTime'] . '|' . $session['moduleKey'];
        $usage[$key] = ($usage[$key] ?? 0) + count($session['groupIds']);
    }

    foreach ($usage as $key => $groupCount) {
        [, $moduleKey] = explode('|', $key, 2);
        $assert(
            $groupCount <= $config->maxParallel($moduleKey),
            "{$moduleKey} overschrijdt de groepscapaciteit in een tijdvak.",
        );
    }

    // Geen medewerker mag op meer dan drie verschillende modules staan.
    foreach ($preview['staffing']['staff'] as $member) {
        $assert(
            (int) $member['moduleCount'] <= 3,
            "{$member['name']} staat op meer dan drie verschillende modules.",
        );
    }

    // Schoolfallback mag in deze 5-modulevariant alleen bij KE of VS terechtkomen.
    foreach ($preview['sessions'] as $session) {
        if (($session['schoolFallback'] ?? false) !== true) continue;

        $assert(
            in_array($session['moduleKey'], ['Klimaat-Experience', 'Klimparcours'], true),
            'School is gebruikt op een verboden module.',
        );
    }

    // VI blijft per paar: 1 kok + 1 begeleider.
    $vi = array_values(array_filter(
        $preview['sessions'],
        static fn (array $session): bool => $session['moduleKey'] === 'Voedsel-Innovatie',
    ));
    $assert(count($vi) === 3, 'Zes groepen moeten drie gedeelde VI-sessies opleveren.');
    foreach ($vi as $session) {
        $assert(count($session['groupIds']) === 2, 'VI moet twee groepen per sessie kunnen bevatten.');
        $assert((int) $session['minimumGeoFortStaff'] === 1, 'VI vraagt Ã©Ã©n begeleider naast de kok.');
    }

    // Zonder kok: concept is nog steeds toepasbaar, maar niet finaliseerbaar.
    $withoutCook = $generator->preview(
        $plan['id'],
        $templates->defaultRounds('dag'),
        $selected,
        'with_staff',
        true,
        null,
    );

    $assert($withoutCook['canApply'] === true, 'Onvolledig rooster moet als concept toepasbaar blijven.');
    $assert($withoutCook['canFinalize'] === false, 'VI zonder kok mag niet finaliseerbaar zijn.');
    $assert(
        in_array('COOK_REQUIRED', array_column($withoutCook['hardIssues'], 'code'), true),
        'De preview verklaart de ontbrekende kok niet.',
    );

    $applied = $generator->apply(
        $plan['id'],
        1,
        $templates->defaultRounds('dag'),
        false,
        1,
        $selected,
        'with_staff',
        true,
        null,
    );

    $assert($applied['sessionCount'] > 0, 'Onvolledig concept kon niet worden toegepast.');
    $persisted = $planService->getPlan($plan['id']);
    $assert(($persisted['status'] ?? null) === 'concept', 'Onvolledig toegepast rooster moet concept blijven.');

    fwrite(STDOUT, "OK: roster V4 capacity + concept workflow integration passed.\n");
} finally {
    $disposable->drop();
}

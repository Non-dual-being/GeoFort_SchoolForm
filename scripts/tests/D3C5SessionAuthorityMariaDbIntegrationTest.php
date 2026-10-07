<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';
require __DIR__ . '/Support/DisposableBookingMariaDb.php';

use GeoFort\Booking\Stored\StoredBookingAssembler;
use GeoFort\Services\Dashboard\Roster\RosterPlanException;
use GeoFort\Services\Dashboard\Roster\RosterPlanningConfig;
use GeoFort\Services\Dashboard\Roster\RosterSessionService;
use GeoFort\Services\Sql\RosterPlanSqlRepository;
use GeoFort\Services\Sql\RosterSessionSqlRepository;
use GeoFort\Services\Sql\StoredBookingSqlRepository;

$database = DisposableBookingMariaDb::create('roster_session_authority');

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

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
    $service = new RosterSessionService(
        $pdo,
        $bookings,
        $plans,
        $sessions,
        $config,
    );

    // Standalone custom plan: deliberately no booking_id.
    $planId = $plans->createManualPlan('2026-11-10', 1, null);
    $plans->saveManualContext(
        $planId,
        'D3C5 custom school',
        'Gorinchem',
        'primairOnderwijs',
        'dag',
        60,
        '10:00',
        '15:00',
    );
    $plans->saveManualModuleKeys($planId, [
        'Dynamische-Globe',
        'Voedsel-Innovatie',
        'Klimaat-Experience',
        'Minecraft-Klimaatspeurtocht',
    ]);
    $plans->insertGroups($planId, 4);

    $planRow = $plans->findById($planId);

    $groups = is_array($planRow['groups'] ?? null)

        ? array_values($planRow['groups'])

        : [];
    $assert(count($groups) === 4, 'Custom fixture mist roostergroepen.');

    $saved = $service->saveActivity(
        $planId,
        null,
        1,
        'Dynamische-Globe',
        '10:15',
        '11:00',
        null,
        [(int) $groups[0]['id']],
        1,
    );

    $assert($saved['revision'] === 2, 'Standalone custom sessie werd niet opgeslagen.');
    $assert(
        (int) $pdo->query(
            "SELECT COUNT(*) FROM roster_sessions WHERE roster_plan_id = {$planId}",
        )->fetchColumn() === 1,
        'Standalone custom sessie ontbreekt in database.',
    );

    try {
        $service->saveActivity(
            $planId,
            null,
            2,
            'Earth-Watch',
            '11:00',
            '11:45',
            null,
            [(int) $groups[1]['id']],
            1,
        );
        throw new RuntimeException('Niet-geselecteerde custom module werd ten onrechte toegestaan.');
    } catch (RosterPlanException $exception) {
        $assert(
            $exception->publicCode === 'MODULE_NOT_ALLOWED',
            'Custom module-authority gaf verkeerde foutcode.',
        );
    }

    // Same selected module set has four modules, so KE uses the four-module policy.
    $ke = $service->saveActivity(
        $planId,
        null,
        2,
        'Klimaat-Experience',
        '11:00',
        '11:45',
        null,
        [(int) $groups[1]['id']],
        1,
    );
    $assert($ke['revision'] === 3, 'Custom KE-sessie kon niet worden opgeslagen.');

    fwrite(STDOUT, "OK: D3C5A2 standalone custom session authority MariaDB integration passed.\n");
} finally {
    $database->drop();
}
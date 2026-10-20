<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';
require __DIR__ . '/Support/DisposableBookingMariaDb.php';

use GeoFort\Booking\Stored\StoredBookingAssembler;
use GeoFort\Services\Dashboard\Roster\RosterAutoGenerator;
use GeoFort\Services\Dashboard\Roster\RosterGenerationTemplateProvider;
use GeoFort\Services\Dashboard\Roster\RosterPlanningConfig;
use GeoFort\Services\Sql\RosterPlanSqlRepository;
use GeoFort\Services\Sql\RosterSessionSqlRepository;
use GeoFort\Services\Sql\StoredBookingSqlRepository;

$database = DisposableBookingMariaDb::create('roster_custom_preview_runtime');

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
        '/database/sql/2026-10-01_create_roster_final_snapshots.sql',
        '/database/sql/2026-10-13_add_roster_manual_program_version.sql',
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

    $generator = new RosterAutoGenerator(
        $pdo,
        $bookings,
        $plans,
        $sessions,
        $config,
        $templates,
    );

    $planId = $plans->createManualPlan('2027-07-08', 1, null);
    $plans->saveManualContext(
        $planId,
        'Runtime custom school',
        'Elst',
        'voortgezetOnderwijsOnderbouw',
        'dag',
        90,
        '10:00',
        '15:00',
    );
    $plans->saveManualModuleKeys($planId, [
        'Dynamische-Globe',
        'Stop-de-Klimaat-Klok',
        'Earth-Watch',
        'Minecraft-Klimaatspeurtocht',
    ]);
    $plans->insertGroups($planId, 2);

    $rounds = [
        ['label' => 'Ronde 1', 'startTime' => '10:15', 'endTime' => '11:00'],
        ['label' => 'Ronde 2', 'startTime' => '11:00', 'endTime' => '11:45'],
        ['label' => 'Ronde 3', 'startTime' => '12:15', 'endTime' => '13:00'],
        ['label' => 'Ronde 4', 'startTime' => '13:00', 'endTime' => '13:45'],
    ];

    $proposal = $generator->preview(
        $planId,
        $rounds,
        [],
        'lesson_only',
        false,
        null,
        [],
    );

    $assert((int) $proposal['planId'] === $planId, 'Custom preview plan-id klopt niet.');
    $assert(count($proposal['rounds']) === 4, 'Custom preview gebruikt niet exact vier rondes.');
    $assert(
        is_string($proposal['templateNote'] ?? null),
        'Custom preview mist templateNote of crasht bij het bepalen ervan.',
    );

    fwrite(STDOUT, "OK: D3C5 custom generator preview runtime MariaDB integration passed.\n");
} finally {
    $database->drop();
}

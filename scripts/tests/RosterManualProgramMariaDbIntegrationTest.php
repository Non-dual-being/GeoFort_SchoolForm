<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';
require __DIR__ . '/Support/DisposableBookingMariaDb.php';

use GeoFort\Booking\Stored\StoredBookingAssembler;
use GeoFort\Services\Booking\Roster\RosterGroupCountResolver;
use GeoFort\Services\Dashboard\Roster\RosterPlanException;
use GeoFort\Services\Dashboard\Roster\RosterPlanningConfig;
use GeoFort\Services\Dashboard\Roster\RosterPlanService;
use GeoFort\Services\Sql\RosterPlanSqlRepository;
use GeoFort\Services\Sql\StoredBookingSqlRepository;

$disposable = DisposableBookingMariaDb::create('roster_manual_program_v8b2b');
$pdo = $disposable->pdo;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};

try {
    $root = dirname(__DIR__, 2);
    foreach ([
        '2026-09-21_create_roster_planning_foundation.sql',
        '2026-09-26_create_roster_manual_plan_context.sql',
        '2026-09-26_add_roster_manual_program_modules.sql',
    ] as $migration) {
        $pdo->exec((string) file_get_contents($root . '/database/sql/' . $migration));
    }

    $plans = new RosterPlanSqlRepository($pdo);
    $service = new RosterPlanService(
        $pdo,
        new StoredBookingSqlRepository($pdo, new StoredBookingAssembler()),
        $plans,
        new RosterGroupCountResolver(),
        null,
        new RosterPlanningConfig(),
    );

    $created = $service->createManual(
        schoolName: 'Programma Testschool',
        city: 'Gorinchem',
        visitDate: '2026-11-12',
        sector: 'primairOnderwijs',
        program: 'dag',
        studentCount: 90,
        groupCount: 6,
        actingAdminId: 1,
    );

    $planId = (int) $created['plan']['id'];
    $assert($created['plan']['revision'] === 1, 'Nieuw zelfstandig rooster moet revisie 1 hebben.');
    $assert(
        array_column($created['plan']['planning']['modules'], 'key') === [
            'Klimaat-Experience',
            'Klimparcours',
            'Voedsel-Innovatie',
            'Dynamische-Globe',
        ],
        'Voor opslaan moet de bestaande standaardset terugvallen uit BookingProgramConfig.',
    );

    $chosen = [
        'Minecraft-Programmeren',
        'Klimaat-Experience',
        'Klimparcours',
        'Voedsel-Innovatie',
        'Dynamische-Globe',
    ];

    $saved = $service->saveManualProgram(
        planId: $planId,
        expectedRevision: 1,
        moduleKeys: $chosen,
        actingAdminId: 1,
    );

    $assert($saved['revision'] === 2, 'Programma opslaan moet de revisie verhogen.');
    $assert(
        array_column($saved['planning']['modules'], 'key') === $chosen,
        'Opgeslagen modulevolgorde wordt niet door getPlan gerespecteerd.',
    );

    $statement = $pdo->prepare(
        'SELECT module_keys_json FROM roster_manual_plan_context WHERE roster_plan_id = :plan_id',
    );
    $statement->execute([':plan_id' => $planId]);
    $json = $statement->fetchColumn();
    $assert(is_string($json), 'module_keys_json is niet opgeslagen.');
    $assert(json_decode($json, true, 32, JSON_THROW_ON_ERROR) === $chosen, 'JSON-modulevolgorde wijkt af.');

    try {
        $service->saveManualProgram(
            planId: $planId,
            expectedRevision: 2,
            moduleKeys: ['Niet-Bestaande-Module'],
            actingAdminId: 1,
        );
        throw new RuntimeException('Onbekende module werd ten onrechte geaccepteerd.');
    } catch (RosterPlanException $exception) {
        $assert($exception->publicCode === 'INVALID_MANUAL_MODULE_FOR_PROGRAM', 'Onverwachte foutcode voor onbekende module.');
    }

    try {
        $service->saveManualProgram(
            planId: $planId,
            expectedRevision: 2,
            moduleKeys: ['Klimaat-Experience', 'Klimaat-Experience'],
            actingAdminId: 1,
        );
        throw new RuntimeException('Dubbele module werd ten onrechte geaccepteerd.');
    } catch (RosterPlanException $exception) {
        $assert($exception->publicCode === 'INVALID_MANUAL_MODULES', 'Onverwachte foutcode voor dubbele module.');
    }

    try {
        $service->saveManualProgram(
            planId: $planId,
            expectedRevision: 1,
            moduleKeys: ['Klimaat-Experience'],
            actingAdminId: 1,
        );
        throw new RuntimeException('Verouderde revisie werd ten onrechte geaccepteerd.');
    } catch (RosterPlanException $exception) {
        $assert($exception->publicCode === 'REVISION_CONFLICT', 'Onverwachte foutcode voor revisieconflict.');
    }

    fwrite(STDOUT, "OK: manual roster V8B2B Programma MariaDB integration passed.\n");
} finally {
    $disposable->drop();
}

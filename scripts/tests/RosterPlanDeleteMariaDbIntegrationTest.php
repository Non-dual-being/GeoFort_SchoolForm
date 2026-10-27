<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';
require __DIR__ . '/Support/DisposableBookingMariaDb.php';

use GeoFort\Booking\Stored\StoredBookingAssembler;
use GeoFort\Services\Booking\Roster\RosterGroupCountResolver;
use GeoFort\Services\Dashboard\Roster\RosterPlanException;
use GeoFort\Services\Dashboard\Roster\RosterPlanService;
use GeoFort\Services\Sql\RosterPlanSqlRepository;
use GeoFort\Services\Sql\StoredBookingSqlRepository;

$disposable = DisposableBookingMariaDb::create('roster_delete_v8b2a2b');
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
        '2026-09-21_create_roster_session_planning.sql',
        '2026-09-26_create_roster_manual_plan_context.sql',
        '2026-10-13_add_roster_manual_program_version.sql',
    ] as $migration) {
        $pdo->exec((string) file_get_contents($root . '/database/sql/' . $migration));
    }

    $plans = new RosterPlanSqlRepository($pdo);
    $service = new RosterPlanService(
        $pdo,
        new StoredBookingSqlRepository($pdo, new StoredBookingAssembler()),
        $plans,
        new RosterGroupCountResolver(),
    );

    $manual = $service->createManual(
        schoolName: 'Verwijder Testschool',
        city: 'Gorinchem',
        visitDate: '2026-11-12',
        sector: 'primairOnderwijs',
        program: 'dag',
        studentCount: 60,
        groupCount: 4,
        actingAdminId: 1,
    );
    $manualId = (int) $manual['plan']['id'];
    $manualGroupId = (int) $manual['plan']['groups'][0]['id'];

    $insertSession = $pdo->prepare(<<<'SQL'
        INSERT INTO roster_sessions (
            roster_plan_id,
            item_type,
            module_key,
            start_time,
            end_time,
            location_label,
            created_by_admin_id,
            updated_by_admin_id
        ) VALUES (
            :plan_id,
            'activity',
            'Klimaat-Experience',
            '10:15',
            '11:00',
            'Testlocatie',
            1,
            1
        )
        SQL);
    $insertSession->execute([':plan_id' => $manualId]);
    $sessionId = (int) $pdo->lastInsertId();

    $linkGroup = $pdo->prepare(
        'INSERT INTO roster_session_groups (roster_session_id, roster_group_id) VALUES (:session_id, :group_id)',
    );
    $linkGroup->execute([
        ':session_id' => $sessionId,
        ':group_id' => $manualGroupId,
    ]);

    $service->deleteConcept($manualId);

    foreach ([
        'roster_plans',
        'roster_groups',
        'roster_sessions',
        'roster_manual_plan_context',
    ] as $table) {
        $statement = $pdo->prepare("SELECT COUNT(*) FROM {$table} WHERE " . (
            $table === 'roster_plans'
                ? 'id = :plan_id'
                : 'roster_plan_id = :plan_id'
        ));
        $statement->execute([':plan_id' => $manualId]);
        $assert((int) $statement->fetchColumn() === 0, "{$table} is niet opgeschoond.");
    }

    $pdo->exec(<<<'SQL'
        INSERT INTO aanvragen (
            status,
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
            aantal_leerlingen,
            aantal_begeleiders
        ) VALUES (
            'In optie',
            'Gekoppelde Testschool',
            'Nederland',
            'Teststraat 1',
            '4200AA',
            'Gorinchem',
            '0183000000',
            '0612345678',
            'Test',
            'Planner',
            'test@example.test',
            '2026-11-13',
            'primairOnderwijs',
            'dag',
            60,
            8
        )
        SQL);
    $bookingId = (int) $pdo->lastInsertId();

    $linked = $plans->createForBooking(
        bookingId: $bookingId,
        visitDate: '2026-11-13',
        sourceBookingFingerprint: str_repeat('a', 64),
        adminId: 1,
    );
    $plans->insertGroups($linked['id'], 4);

    $service->deleteConcept($linked['id']);

    $bookingCount = $pdo
        ->query("SELECT COUNT(*) FROM aanvragen WHERE id = {$bookingId}")
        ->fetchColumn();
    $assert((int) $bookingCount === 1, 'De gekoppelde onderwijsaanvraag is ten onrechte verwijderd.');

    $publishedId = $plans->createManualPlan('2026-11-16', 1);
    $pdo->exec("UPDATE roster_plans SET status = 'published' WHERE id = {$publishedId}");

    try {
        $service->deleteConcept($publishedId);
        throw new RuntimeException('Gepubliceerd rooster werd ten onrechte verwijderd.');
    } catch (RosterPlanException $exception) {
        $assert(
            $exception->publicCode === 'PLAN_NOT_DELETABLE',
            'Onverwachte foutcode voor niet-verwijderbaar rooster.',
        );
    }

    $publishedCount = $pdo
        ->query("SELECT COUNT(*) FROM roster_plans WHERE id = {$publishedId}")
        ->fetchColumn();
    $assert((int) $publishedCount === 1, 'Niet-conceptrooster is toch verwijderd.');

    fwrite(STDOUT, "OK: roster V8B2A2b delete MariaDB integration passed.\n");
} finally {
    $disposable->drop();
}

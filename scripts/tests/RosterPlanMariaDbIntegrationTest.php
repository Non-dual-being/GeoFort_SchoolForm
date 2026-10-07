<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';
require __DIR__ . '/Support/DisposableBookingMariaDb.php';

use GeoFort\Booking\Stored\StoredBookingAssembler;
use GeoFort\Services\Booking\Roster\RosterGroupCountResolver;
use GeoFort\Services\Dashboard\Roster\RosterPlanService;
use GeoFort\Services\Sql\RosterPlanSqlRepository;
use GeoFort\Services\Sql\StoredBookingSqlRepository;

$disposable = DisposableBookingMariaDb::create('roster_plan');
$pdo = $disposable->pdo;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

try {
    $migration = file_get_contents(
        dirname(__DIR__, 2) . '/database/sql/2026-09-21_create_roster_planning_foundation.sql',
    );
    if (!is_string($migration) || trim($migration) === '') {
        throw new RuntimeException('Roostermigratie ontbreekt.');
    }

    $pdo->exec($migration);

    $planColumns = (int) $pdo->query(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'roster_plans'
           AND COLUMN_NAME IN (
             'id','booking_id','visit_date','status','revision',
             'source_booking_fingerprint','created_by_admin_id',
             'updated_by_admin_id','created_at','updated_at'
           )",
    )->fetchColumn();

    $groupColumns = (int) $pdo->query(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'roster_groups'
           AND COLUMN_NAME IN (
             'id','roster_plan_id','label','position',
             'student_count','created_at','updated_at'
           )",
    )->fetchColumn();

    $foreignKeys = (int) $pdo->query(
        "SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
         WHERE CONSTRAINT_SCHEMA = DATABASE()
           AND TABLE_NAME IN ('roster_plans','roster_groups')
           AND CONSTRAINT_TYPE = 'FOREIGN KEY'",
    )->fetchColumn();

    $assert($planColumns === 10, 'roster_plans-schema is onvolledig.');
    $assert($groupColumns === 7, 'roster_groups-schema is onvolledig.');
    $assert($foreignKeys === 4, 'Verwacht vier foreign keys in roosterfundering.');

    $insert = $pdo->prepare(<<<'SQL'
        INSERT INTO aanvragen (
            status, schoolnaam, land, adres, postcode, plaats,
            school_telefoonnummer, contactpersoon_telefoonnummer,
            contactpersoon_voornaam, contactpersoon_achternaam,
            email, bezoekdatum, cjpPasGebruik, onderwijs_sector,
            programma, keuzemodule_key, aantal_leerlingen,
            aantal_begeleiders, voorwaarden_akkoord
        ) VALUES (
            'Definitief', 'MariaDB Roostertest', 'Nederland', 'Testweg 1',
            '1234 AB', 'Gorinchem', '0100000000', '0612345678',
            'Test', 'Planner', 'rooster@example.test', '2026-10-08',
            'nee', 'primairOnderwijs', 'dag', 'Earth-Watch', 100, 7, 1
        )
        SQL);
    $insert->execute();
    $bookingId = (int) $pdo->lastInsertId();

    $selection = $pdo->prepare(<<<'SQL'
        INSERT INTO aanvraag_onderwijs_selecties (
            aanvraag_id, sector_key, sector_label,
            level_key, level_label, level_position,
            group_key, group_label, group_position
        ) VALUES (
            :booking_id, 'primairOnderwijs', 'Primair onderwijs',
            'regulier', 'Regulier basisonderwijs', 1,
            'groep7', 'Groep 7', 1
        )
        SQL);
    $selection->execute([':booking_id' => $bookingId]);

    $service = new RosterPlanService(
        $pdo,
        new StoredBookingSqlRepository($pdo, new StoredBookingAssembler()),
        new RosterPlanSqlRepository($pdo),
        new RosterGroupCountResolver(),
    );

    $first = $service->createFromBooking($bookingId, 1);
    $assert($first['created'] === true, 'Eerste MariaDB-creatie is niet nieuw.');
    $assert(count($first['plan']['groups']) === 6, '100 leerlingen moeten zes roostergroepen krijgen.');
    $assert($first['plan']['sourceCurrent'] === true, 'Nieuwe MariaDB-planning is direct stale.');

    $second = $service->createFromBooking($bookingId, 1);
    $assert($second['created'] === false, 'Tweede creatie maakt geen bestaand plan open.');
    $assert(
        $second['plan']['id'] === $first['plan']['id'],
        'Idempotente creatie levert een ander rooster-id op.',
    );

    $planCount = (int) $pdo->query(
        'SELECT COUNT(*) FROM roster_plans',
    )->fetchColumn();
    $groupCount = (int) $pdo->query(
        'SELECT COUNT(*) FROM roster_groups',
    )->fetchColumn();

    $assert($planCount === 1, 'Er is meer dan Ã©Ã©n plan voor dezelfde aanvraag.');
    $assert($groupCount === 6, 'MariaDB bevat niet precies zes roostergroepen.');

    $pdo->exec(
        "UPDATE aanvragen
         SET aantal_leerlingen = 120
         WHERE id = {$bookingId}",
    );

    $changed = $service->getPlan((int) $first['plan']['id']);
    $assert(
        is_array($changed) && $changed['needsReview'] === true,
        'Gewijzigde aanvraag markeert MariaDB-plan niet voor herbeoordeling.',
    );

    try {
        $pdo->exec("DELETE FROM aanvragen WHERE id = {$bookingId}");
        throw new RuntimeException('FK RESTRICT op gekoppelde aanvraag ontbreekt.');
    } catch (PDOException $exception) {
        $assert(
            (string) $exception->getCode() === '23000',
            'Verwijderen gekoppelde aanvraag faalt met onverwachte SQLSTATE.',
        );
    }

    fwrite(STDOUT, "OK: roosterfundering MariaDB-integratie geslaagd.\n");
} finally {
    $disposable->drop();
}
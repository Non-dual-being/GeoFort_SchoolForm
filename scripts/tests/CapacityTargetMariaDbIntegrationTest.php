<?php
declare(strict_types=1);

use GeoFort\Services\Dashboard\Booking\Analytics\CapacityTargetManagementService;
use GeoFort\Services\Sql\CapacityTargetSchemaInspector;
use GeoFort\Services\Sql\CapacityTargetSqlRepository;

require dirname(__DIR__, 2) . '/vendor/autoload.php';
require __DIR__ . '/Support/DisposableBookingMariaDb.php';

if (getenv('STATUS_TEST_DB_CONFIRM') !== 'YES_DISPOSABLE') {
    fwrite(STDERR, "SKIP: STATUS_TEST_DB_CONFIRM=YES_DISPOSABLE ontbreekt.\n");
    exit(0);
}

$assert = static fn (bool $condition, string $message) => $condition ?: throw new RuntimeException($message);
$database = DisposableBookingMariaDb::create('capacity_target');
$pdo = $database->pdo;
try {
    $pdo->exec('ALTER TABLE admin_users CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci');
    $migration = (string) file_get_contents(dirname(__DIR__, 2) . '/database/sql/2026-08-07_create_capacity_targets.sql');
    $pdo->exec($migration);
    $pdo->exec($migration);

    $inspector = new CapacityTargetSchemaInspector($pdo);
    $schemaIssues = $inspector->issues();
    $assert($inspector->tableExists() && $schemaIssues === [], 'Migratie maakt geen heruitvoerbaar, compatibel capacity_targets-schema: ' . implode('; ', $schemaIssues));
    $assert((int) $pdo->query('SELECT COUNT(*) FROM capacity_targets')->fetchColumn() === 0, 'Een leeg targetmodel is niet geldig.');

    $adminId = (int) $pdo->query('SELECT id FROM admin_users ORDER BY id LIMIT 1')->fetchColumn();
    $insert = $pdo->prepare('INSERT INTO capacity_targets (effective_date, students_per_available_day, bookings_per_available_day, created_by_admin_id, updated_by_admin_id) VALUES (?, ?, ?, ?, ?)');
    $insert->execute(['2098-01-01', 0, '0.0', $adminId, $adminId]);
    $insert->execute(['2098-01-02', 160, '2.0', $adminId, $adminId]);
    foreach ([
        ['2098-01-03', -1, '1.0'],
        ['2098-01-04', 161, '1.0'],
        ['2098-01-05', 100, '-0.1'],
        ['2098-01-06', 100, '2.1'],
    ] as [$date, $students, $bookings]) {
        try {
            $insert->execute([$date, $students, $bookings, $adminId, $adminId]);
            throw new RuntimeException("Databaseconstraint accepteert ongeldige grenswaarden voor {$date}.");
        } catch (PDOException) {
            // Zowel UNSIGNED als CHECK mag de ongeldige waarde afwijzen.
        }
    }
    $pdo->exec("DELETE FROM capacity_targets WHERE effective_date LIKE '2098-%'");

    $repository = new CapacityTargetSqlRepository($pdo);
    $service = new CapacityTargetManagementService($pdo, $repository);
    $today = new DateTimeImmutable('today', new DateTimeZone('Europe/Amsterdam'));
    $effective = $today->modify('+1 day')->format('Y-m-d');
    $created = $service->save($effective, 120, 1.5, null, $adminId, 'admin');
    $assert($created['ok'] && $created['target']['studentsPerAvailableDay'] === 120 && $created['target']['derivedAverageStudentsPerBooking'] === 80.0, 'Target wordt niet correct aangemaakt of afgeleid.');
    $conflict = $service->save($effective, 130, 1.6, null, $adminId, 'admin');
    $assert(!$conflict['ok'] && $conflict['code'] === 'TARGET_CONFLICT', 'Ongemerkt overschrijven wordt niet geweigerd.');
    $updated = $service->save($effective, 130, 1.6, (string) $created['target']['updatedAt'], $adminId, 'planner');
    $assert($updated['ok'] && $updated['target']['studentsPerAvailableDay'] === 130 && $updated['target']['createdAt'] === $created['target']['createdAt'], 'Geautoriseerde update bewaart creatiehistorie niet.');
    $past = $service->save($today->modify('-1 day')->format('Y-m-d'), 100, 1.0, null, $adminId, 'admin');
    $assert(!$past['ok'] && $past['code'] === 'INVALID_TARGET', 'Historische analytics kunnen met een terugwerkende targetmutatie wijzigen.');
    $forbidden = $service->save($today->modify('+2 days')->format('Y-m-d'), 100, 1.0, null, $adminId, 'viewer');
    $assert(!$forbidden['ok'] && $forbidden['code'] === 'FORBIDDEN', 'Onbevoegde rol kan targets wijzigen.');
    $reloadDate = $today->modify('+3 days')->format('Y-m-d');
    $reloadTarget = $service->save($reloadDate, 120, 1.0, null, $adminId, 'admin');
    $assert($reloadTarget['ok'] && $reloadTarget['target']['derivedAverageStudentsPerBooking'] === 120.0, 'Target 120/1,0 wordt niet als gemiddeld 120 leerlingen per boeking afgeleid.');
    $reloaded = (new CapacityTargetSqlRepository($database->connect()))->findEffectiveOn($reloadDate);
    $assert($reloaded?->studentsPerAvailableDay === 120 && $reloaded->bookingsPerAvailableDay === 1.0, 'Officieel target blijft na een nieuwe databaseverbinding niet behouden.');
    $minimum = $service->save($today->modify('+4 days')->format('Y-m-d'), 0, 0.0, null, $adminId, 'admin');
    $maximum = $service->save($today->modify('+5 days')->format('Y-m-d'), 160, 2.0, null, $adminId, 'admin');
    $assert($minimum['ok'] && $minimum['target']['derivedAverageStudentsPerBooking'] === null, 'Minimumtarget of nuldelingsbeveiliging wordt niet opgeslagen.');
    $assert($maximum['ok'] && $maximum['target']['studentsPerAvailableDay'] === 160 && $maximum['target']['bookingsPerAvailableDay'] === 2.0, 'Maximumtarget wordt niet opgeslagen.');

    $rowStatement = $pdo->prepare('SELECT created_by_admin_id, updated_by_admin_id, students_per_available_day, bookings_per_available_day FROM capacity_targets WHERE effective_date = ?');
    $rowStatement->execute([$effective]);
    $row = $rowStatement->fetch(PDO::FETCH_ASSOC);
    $assert((int) $row['created_by_admin_id'] === $adminId && (int) $row['updated_by_admin_id'] === $adminId && (int) $row['students_per_available_day'] === 130 && (float) $row['bookings_per_available_day'] === 1.6, 'Auditvelden of decimale opslag zijn onjuist.');

    fwrite(STDOUT, "Capacity target database integration tests passed.\n");
} finally {
    $database->drop();
}

<?php
declare(strict_types=1);
require __DIR__ . '/Support/RosterLifecycleFixtures.php';
require dirname(__DIR__) . '/release/FourModuleSchema.php';

$db = DisposableBookingMariaDb::create('four_migration');
$checks = 0;
$assert = static function (bool $ok, string $why) use (&$checks): void { if (!$ok) throw new RuntimeException($why); $checks++; };
try {
    $pdo = $db->pdo;
    RosterLifecycleFixtures::schema($pdo);
    $s = RosterLifecycleFixtures::services($pdo);
    $plan = RosterLifecycleFixtures::validPlan($pdo, $s);
    $pdo->exec("INSERT INTO aanvragen(schoolnaam,land,adres,postcode,plaats,school_telefoonnummer,contactpersoon_telefoonnummer,contactpersoon_voornaam,contactpersoon_achternaam,email,bezoekdatum,onderwijs_sector,programma,keuzemodule_key,aantal_leerlingen,aantal_begeleiders) VALUES('Existing future booking','NL','Test','1234 AB','Test','0100000000','0100000000','Test','Test','test@example.test','2027-02-01','primairOnderwijs','dag','Earth-Watch',40,8)");
    $bookingId = (int)$pdo->lastInsertId();
    // Reconstruct pre-release schema only inside this newly created disposable database.
    $pdo->exec('ALTER TABLE aanvragen DROP COLUMN program_version');
    $pdo->exec('ALTER TABLE roster_manual_plan_context DROP COLUMN program_version');
    $pdo->exec('DROP TABLE booking_program_access');
    $beforeBookings = $pdo->query('SELECT * FROM aanvragen ORDER BY id')->fetchAll();
    $beforePlans = $pdo->query('SELECT * FROM roster_manual_plan_context ORDER BY roster_plan_id')->fetchAll();
    $schema = new FourModuleSchema($pdo, dirname(__DIR__, 2));
    $assert(!$schema->inspect()['schemaReady'] && $schema->inspect()['codeRollbackAllowed'], 'Old schema preflight failed');
    $state = $schema->migrate();
    $assert($state['schemaReady'] && $state['codeRollbackAllowed'], 'Migration did not finish safely');
    $assert((int)$state['setting']['five_module_open'] === 1, 'Legacy form closed without instruction');
    $withoutVersion = static function(array $rows): array { foreach ($rows as &$row) { if ($row['program_version'] !== null) throw new RuntimeException('Historical row backfilled'); unset($row['program_version']); } return $rows; };
    $assert($withoutVersion($pdo->query('SELECT * FROM aanvragen ORDER BY id')->fetchAll()) === $beforeBookings, 'Migration changed existing booking');
    $assert($withoutVersion($pdo->query('SELECT * FROM roster_manual_plan_context ORDER BY roster_plan_id')->fetchAll()) === $beforePlans, 'Migration changed existing custom roster');
    $pdo->exec('UPDATE booking_program_access SET five_module_open=0,revision=1');
    $assert((int)$schema->migrate()['setting']['five_module_open'] === 0, 'Repeated migration resets planner setting');
    $pdo->prepare("UPDATE aanvragen SET program_version='day-four-2027' WHERE id=?")->execute([$bookingId]);
    $assert(!$schema->inspect()['codeRollbackAllowed'], 'Old code rollback allowed after new booking');
    $pdo->prepare('UPDATE aanvragen SET program_version=NULL WHERE id=?')->execute([$bookingId]);
    $pdo->prepare("UPDATE roster_manual_plan_context SET program_version='day-four-2027' WHERE roster_plan_id=?")->execute([$plan['id']]);
    $assert(!$schema->inspect()['codeRollbackAllowed'], 'Old code rollback allowed after versioned custom plan');
    $pdo->exec('UPDATE roster_manual_plan_context SET program_version=NULL');
    $pdo->exec("INSERT INTO roster_planning_pairs(visit_date,workflow,created_by_admin_id) VALUES('2028-10-10','lessons_first',1)");
    $pair = (int)$pdo->lastInsertId();
    $pdo->prepare('INSERT INTO roster_plan_archives(plan_id,pair_id,presentation_json,archived_by_admin_id) VALUES(?,?,?,1)')->execute([$plan['id'],$pair,'{"programVersion":"day-four-2027"}']);
    $assert(!$schema->inspect()['codeRollbackAllowed'] && $schema->inspect()['fourModuleArchives'] === 1, 'Archived four-module agreement permits old code rollback');
    $pdo->exec('ALTER TABLE aanvragen MODIFY program_version VARCHAR(20) NULL');
    try { $schema->migrate(); throw new RuntimeException('Wrong schema accepted'); }
    catch (RuntimeException $e) { $assert(str_contains($e->getMessage(), 'Unexpected existing'), 'Wrong schema not rejected'); }
    echo "PASS: {$checks} migration, retry, data preservation and rollback-guard checks\n";
} finally { $db->drop(); }

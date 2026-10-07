<?php
declare(strict_types=1);
require __DIR__ . '/Support/RosterLifecycleFixtures.php';
use GeoFort\Services\Migration\RosterStaffManagementMigration;
if (getenv('STATUS_TEST_DB_CONFIRM') !== 'YES_DISPOSABLE') { echo "SKIP disposable consent required\n"; exit(0); }
$assert = RosterLifecycleFixtures::assert(...); $databases = [];
try {
    foreach ([true, false] as $fresh) {
        $db = $databases[] = DisposableBookingMariaDb::create('staff_upgrade');
        RosterLifecycleFixtures::schema($db->pdo, false);
        if (!$fresh) $db->pdo->exec("INSERT INTO roster_staff_members (id,display_name,is_active,employment_type,can_guide,can_cook,notes) VALUES (107,'Eigen bestaande medewerker',0,'volunteer',0,1,'Bestaande notitie')");
        $before = $db->pdo->query('SELECT * FROM roster_staff_members ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
        $migration = new RosterStaffManagementMigration($db->pdo, dirname(__DIR__, 2));
        $assert($migration->inspect()['schemaState'] === 'absent', 'Clean pre-C schema detected');
        $result = $migration->apply($fresh);
        $assert($result['after']['schemaState'] === 'complete', 'Complete schema/index/FK/check/trigger inspection');
        $assert($result['bootstrap']['membersInserted'] === ($fresh ? 20 : 0), 'Explicit fresh/existing migration modes');
        if (!$fresh) {
            $after = $db->pdo->query('SELECT * FROM roster_staff_members ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
            foreach ($after as &$row) unset($row['version']); unset($row);
            $assert($after === $before, 'Migration preserves every preexisting field and timestamp');
        }
        $assert($migration->apply(!$fresh)['bootstrap']['changed'] === false, 'Subsequent deployment is no-op regardless of fresh flag');
        $db->pdo->exec('DROP TRIGGER roster_staff_history_no_delete');
        $assert($migration->inspect()['schemaState'] === 'partial', 'Partial schema detected');
        try { $migration->apply(); throw new LogicException('Partial upgrade replay allowed'); }
        catch (RuntimeException $e) { $assert(str_contains($e->getMessage(), 'Partial Ronde C'), 'Partial upgrade must fail closed'); }
    }
    echo "PASS migration preflight/full/partial, fresh/existing/no-op, values preserved\n";
} finally { foreach ($databases as $db) $db->drop(); }

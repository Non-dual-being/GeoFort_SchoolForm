<?php

declare(strict_types=1);

require __DIR__ . '/Support/RosterLifecycleFixtures.php';

use GeoFort\Services\Migration\RosterStaffBootstrapService;

if (getenv('STATUS_TEST_DB_CONFIRM') !== 'YES_DISPOSABLE') { echo "SKIP disposable MariaDB consent required\n"; exit(0); }
$root = dirname(__DIR__, 2);
$databases = [];
$assert = RosterLifecycleFixtures::assert(...);
$rows = static function (PDO $pdo, bool $timestamps = false): array {
    return [
        'members' => $pdo->query('SELECT id, display_name, is_active, employment_type, can_guide, can_cook, notes' . ($timestamps ? ', created_at, updated_at' : '') . ' FROM roster_staff_members ORDER BY id')->fetchAll(),
        'skills' => $pdo->query('SELECT * FROM roster_staff_module_preferences ORDER BY staff_id, module_key')->fetchAll(),
    ];
};
$schema = static function (PDO $pdo) use ($root): void {
    foreach (['2026-09-21_create_roster_planning_foundation', '2026-09-22_create_roster_staff_catalog', '2026-09-22_extend_roster_staff_roles'] as $file) $pdo->exec(file_get_contents($root . '/database/sql/' . $file . '.sql'));
};
$addC = static fn (PDO $pdo) => $pdo->exec(file_get_contents($root . '/database/sql/2026-10-05_add_roster_staff_management.sql'));
$evidence = [];
try {
    $legacy = $databases[] = DisposableBookingMariaDb::create('staff_seed_legacy');
    $legacy->pdo->exec(file_get_contents($root . '/database/sql/2026-09-21_create_roster_planning_foundation.sql'));
    $legacy->pdo->exec(file_get_contents(__DIR__ . '/fixtures/roster-staff-approved-v1.sql'));
    $approved = $rows($legacy->pdo);
    $assert(count($approved['members']) === 20 && count($approved['skills']) === 86, 'Independent pre-C reference counts');

    $fresh = $databases[] = DisposableBookingMariaDb::create('staff_seed_fresh'); $schema($fresh->pdo); $addC($fresh->pdo);
    $bootstrap = new RosterStaffBootstrapService($fresh->pdo, $root . '/database/seeds/roster_staff_v1.json');
    $result = $bootstrap->run(true);
    $assert($result['membersInserted'] === 20 && $result['skillsInserted'] === 86, 'Fresh exact counts');
    $assert($rows($fresh->pdo) === $approved, 'Every initial ID/name/status/employment/role/module/rank equals executable legacy reference');
    $afterFresh = $rows($fresh->pdo, true);
    $assert($bootstrap->run(true)['changed'] === false && $rows($fresh->pdo, true) === $afterFresh, 'Second fresh bootstrap is no-op');
    $evidence['fresh'] = ['members' => 20, 'skills' => 86, 'ids' => range(1, 20), 'differingFieldsFromApprovedReference' => 0, 'catalogSha256' => hash('sha256', serialize($approved)), 'repeatChanged' => false];

    // Existing operation: deliberate names, flags, tied ranks, missing and legacy skills.
    $legacy->pdo->exec("UPDATE roster_staff_members SET display_name = 'Operationele kok', is_active = 0, employment_type = 'volunteer', can_guide = 1 WHERE id = 20");
    $legacy->pdo->exec("UPDATE roster_staff_module_preferences SET preference_rank = 17 WHERE staff_id = 1");
    $legacy->pdo->exec("DELETE FROM roster_staff_module_preferences WHERE staff_id = 2 AND module_key = 'Earth-Watch'");
    $legacy->pdo->exec("INSERT INTO roster_staff_module_preferences VALUES (20, 'Legacy-lokaal', 8)");
    $before = $rows($legacy->pdo, true); $addC($legacy->pdo);
    $adopt = new RosterStaffBootstrapService($legacy->pdo, $root . '/database/seeds/roster_staff_v1.json');
    $result = $adopt->run(false);
    $assert($result['status'] === 'adopted_existing' && $result['membersInserted'] === 0 && $result['skillsInserted'] === 0, 'Existing registration only');
    $assert($rows($legacy->pdo, true) === $before, 'C migration and adoption leave every existing field/timestamp byte/content identical');
    $assert($adopt->run(true)['changed'] === false && $rows($legacy->pdo, true) === $before, 'Later fresh flag cannot reset registered catalog');
    $evidence['existing'] = ['membersInserted' => 0, 'skillsInserted' => 0, 'differingExistingFields' => 0, 'beforeSha256' => hash('sha256', serialize($before)), 'afterSha256' => hash('sha256', serialize($rows($legacy->pdo, true))), 'bootstrapStatus' => 'adopted_existing', 'repeatChanged' => false];

    $different = $databases[] = DisposableBookingMariaDb::create('staff_seed_deviant'); $schema($different->pdo);
    $different->pdo->exec("INSERT INTO roster_staff_members (id, display_name, is_active, employment_type, can_guide, can_cook) VALUES (107, 'Eigen catalogus', 0, 'volunteer', 0, 1)");
    $different->pdo->exec("INSERT INTO roster_staff_module_preferences VALUES (107, 'Oude-module', 255)");
    $before = $rows($different->pdo, true); $addC($different->pdo);
    $adopt = new RosterStaffBootstrapService($different->pdo, $root . '/database/seeds/roster_staff_v1.json');
    $assert($adopt->run(true)['status'] === 'adopted_existing', 'Even fresh flag preserves deviating nonempty catalog');
    $assert($rows($different->pdo, true) === $before && $adopt->run(true)['changed'] === false, 'No silent fill or reset of deviating catalog');
    $evidence['deviating'] = ['members' => 1, 'skills' => 1, 'memberIds' => [107], 'membersInserted' => 0, 'skillsInserted' => 0, 'differingExistingFields' => 0];

    $empty = $databases[] = DisposableBookingMariaDb::create('staff_seed_empty'); $schema($empty->pdo); $addC($empty->pdo);
    $adopt = new RosterStaffBootstrapService($empty->pdo, $root . '/database/seeds/roster_staff_v1.json');
    $assert($adopt->run(false)['status'] === 'adopted_existing' && $adopt->run(true)['changed'] === false, 'Empty existing installation remains deliberately empty');

    $output = getenv('ROSTER_ROUND_C_OUTPUT');
    if ($output) file_put_contents($output . '/seed-comparison.json', json_encode($evidence, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    echo "PASS seed/deploy comparison: fresh 20/86 exact, existing/deviating unchanged, durable no-op marker\n";
} finally { foreach (array_reverse($databases) as $database) $database->drop(); }

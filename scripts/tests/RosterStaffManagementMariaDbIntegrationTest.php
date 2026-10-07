<?php

declare(strict_types=1);

require __DIR__ . '/Support/RosterLifecycleFixtures.php';

use GeoFort\Services\Dashboard\Roster\{RosterStaffChange, RosterStaffManagementService, RosterStaffCatalogGuard, RosterPlanException, RosterPlanStaffSelectionService};
use GeoFort\Services\Sql\RosterStaffManagementSqlRepository;

if (getenv('STATUS_TEST_DB_CONFIRM') !== 'YES_DISPOSABLE') { echo "SKIP disposable MariaDB consent required\n"; exit(0); }
$db = DisposableBookingMariaDb::create('staff_management'); $pdo = $db->pdo;
$assert = RosterLifecycleFixtures::assert(...); $secret = str_repeat('private-test-secret', 3);
$reject = static function (callable $call, string $code) use ($assert): void {
    try { $call(); } catch (RosterPlanException $e) { $assert($e->publicCode === $code, 'Expected ' . $code . ' got ' . $e->publicCode); return; }
    throw new RuntimeException('Missing rejection ' . $code);
};
try {
    RosterLifecycleFixtures::schema($pdo); $services = RosterLifecycleFixtures::services($pdo);
    $management = new RosterStaffManagementService($pdo, $services['staff'], new RosterStaffManagementSqlRepository($pdo));
    $input = static function (int $id, array $overrides = []) use ($management): array {
        $current = $management->show($id); $m = $current['member'];
        return array_replace(['staffId' => $id, 'expectedVersion' => $m['version'], 'expectedCatalogRevision' => $current['catalogRevision'],
            'name' => $m['name'], 'isActive' => $m['isActive'], 'employmentType' => $m['employmentType'], 'canGuide' => $m['canGuide'], 'canCook' => $m['canCook'],
            'preferences' => array_values(array_map(static fn (array $p): array => ['moduleKey' => $p['moduleKey'], 'rank' => $p['rank']], array_filter($m['preferences'], static fn (array $p): bool => !$p['isLegacy']))),
            'removeLegacyModuleKeys' => []], $overrides);
    };
    $save = static function (array $payload) use ($management, $secret): array {
        $preview = $management->preview(RosterStaffChange::fromArray($payload), 1, $secret);
        return $management->save(RosterStaffChange::fromArray([...$payload, 'impactToken' => $preview['impactToken']]), 1, $secret);
    };
    $state = static fn (): array => [
        $pdo->query('SELECT * FROM roster_staff_members ORDER BY id')->fetchAll(),
        $pdo->query('SELECT * FROM roster_staff_module_preferences ORDER BY staff_id,module_key')->fetchAll(),
        $pdo->query('SELECT * FROM roster_staff_change_history ORDER BY id')->fetchAll(),
        $pdo->query('SELECT * FROM roster_staff_catalog_state')->fetchAll(),
        $pdo->query('SELECT * FROM roster_plans ORDER BY id')->fetchAll(),
    ];
    $before = $state(); $management->catalog(); $management->show(20);
    $assert($state() === $before, 'Metadata/show GET services change no database fields');
    $create = ['name' => 'Nieuwe medewerker', 'isActive' => true, 'employmentType' => 'paid', 'canGuide' => true, 'canCook' => true,
        'expectedCatalogRevision' => 1, 'preferences' => [['moduleKey' => 'Earth-Watch', 'rank' => 2], ['moduleKey' => 'Dynamische-Globe', 'rank' => 3]]];
    $reject(fn () => $management->save(RosterStaffChange::fromArray(array_replace($create, ['preferences' => [['moduleKey' => 'Earth-Watch', 'rank' => 2], ['moduleKey' => 'Dynamische-Globe', 'rank' => 2]]]), true), 1, $secret), 'DUPLICATE_STAFF_PREFERENCE_RANK');
    $assert($state() === $before, 'Duplicate create writes no member, skills, catalog revision or audit');
    $created = $management->save(RosterStaffChange::fromArray($create, true), 1, $secret);
    $id = $created['member']['id'];
    $assert($id === 21 && $created['member']['version'] === 1 && count($created['history']) === 1, 'Create version/audit');
    try { $pdo->prepare('DELETE FROM roster_staff_members WHERE id = ?')->execute([$id]); throw new RuntimeException('Unused staff deletion unexpectedly permitted'); }
    catch (PDOException $e) { $assert($e->getCode() === '45000', 'Even unused staff remains protected by immutable deletion trigger/audit'); }

    // Exact legacy preservation, including an unknown key tied with an active module.
    $pdo->prepare('INSERT INTO roster_staff_module_preferences VALUES (?, ?, ?)')->execute([$id, 'Legacy-rang', 2]);
    $skills = static fn (): array => $pdo->query('SELECT * FROM roster_staff_module_preferences WHERE staff_id = 21 ORDER BY module_key')->fetchAll();
    $legacyRanksBefore = $skills();
    $save($input($id, ['name' => 'Legacy behouden', 'employmentType' => 'volunteer', 'canGuide' => false]));
    $assert($skills() === $legacyRanksBefore, 'Basic fields leave every legacy duplicate row exactly intact');
    $deactivatePayload = $input($id, ['isActive' => false]);
    $deactivatePreview = $management->preview(RosterStaffChange::fromArray($deactivatePayload), 1, $secret);
    $deactivated = $management->deactivate($id, $deactivatePayload['expectedVersion'], $deactivatePayload['expectedCatalogRevision'], $deactivatePreview['impactToken'], 1, $secret);
    $assert(!$deactivated['member']['isActive'] && $skills() === $legacyRanksBefore, 'Dedicated deactivation preserves all tied legacy skills');
    $save($input($id, ['isActive' => true]));
    $assert($skills() === $legacyRanksBefore, 'Reactivation also preserves duplicates exactly');
    $beforeRejected = $state();
    $changedTies = $input($id, ['preferences' => [['moduleKey' => 'Earth-Watch', 'rank' => 2], ['moduleKey' => 'Dynamische-Globe', 'rank' => 4]]]);
    $reject(fn () => $management->preview(RosterStaffChange::fromArray($changedTies), 1, $secret), 'DUPLICATE_STAFF_PREFERENCE_RANK');
    $reject(fn () => $management->save(RosterStaffChange::fromArray($changedTies), 1, $secret), 'DUPLICATE_STAFF_PREFERENCE_RANK');
    $assert($state() === $beforeRejected, 'Changed preferences rejected before all writes');
    $reject(fn () => $save($input($id, ['legacyPreferenceRanks' => [['moduleKey' => 'Invented-legacy', 'rank' => 5]]])), 'INVALID_LEGACY_SKILL');
    $corrected = $save($input($id, ['legacyPreferenceRanks' => [['moduleKey' => 'Legacy-rang', 'rank' => 255]]]));
    $assert(array_column($corrected['member']['preferences'], 'rank', 'moduleKey')['Legacy-rang'] === 255, 'Explicit unknown-module rank correction, max255');
    $save($input($id, ['removeLegacyModuleKeys' => ['Legacy-rang']]));
    $edited = $save($input($id, ['name' => 'Gewijzigde vrijwilliger', 'employmentType' => 'volunteer', 'canGuide' => false, 'canCook' => false]));
    $assert($edited['member']['employmentType'] === 'volunteer' && !$edited['member']['canGuide'] && !$edited['member']['canCook'], 'Independent employment/role edit');
    $save($input($id, ['isActive' => false])); $save($input($id, ['isActive' => true, 'canGuide' => true]));
    $assert($management->show($id)['member']['isActive'], 'Reactivate');
    $pdo->prepare('INSERT INTO roster_staff_module_preferences VALUES (?, ?, ?)')->execute([$id, 'Legacy & onbekend', 23]);
    $legacyRow = $pdo->query("SELECT * FROM roster_staff_module_preferences WHERE module_key = 'Legacy & onbekend'")->fetchAll();
    $save($input($id, ['name' => 'Naam met legacy']));
    $assert($pdo->query("SELECT * FROM roster_staff_module_preferences WHERE module_key = 'Legacy & onbekend'")->fetchAll() === $legacyRow, 'Ordinary update preserves legacy exact row/rank');
    $assert(count(array_filter($management->show($id)['member']['preferences'], static fn (array $p): bool => $p['isLegacy'])) === 1, 'Legacy marked in editor payload');
    $optimizer = new GeoFort\Services\Dashboard\Roster\RosterStaffOptimizer($services['staff']);
    $task = [['moduleKey' => 'Earth-Watch', 'startTime' => '10:15', 'endTime' => '11:00', 'minimumGeoFortStaff' => 1, 'schoolSupervisionAllowed' => false]];
    $assert(array_sum(array_column($optimizer->optimize($task, [$id])['staff'], 'workMinutes')) === 45, 'Optimizer uses catalog skill');
    $save($input($id, ['preferences' => [['moduleKey' => 'Dynamische-Globe', 'rank' => 2]]]));
    $pdo->prepare('INSERT INTO roster_staff_module_preferences VALUES (?, ?, ?)')->execute([$id, 'earth-watch', 19]);
    $caseBefore = $state();
    $reject(fn () => $save($input($id, ['preferences' => [['moduleKey' => 'Earth-Watch', 'rank' => 1]]])), 'LEGACY_MODULE_KEY_CONFLICT');
    $assert($state() === $caseBefore, 'Case-insensitive module collision cannot overwrite a legacy skill/rank or audit/member fields');
    $save($input($id, ['removeLegacyModuleKeys' => ['earth-watch']]));
    $assert(array_sum(array_column($optimizer->optimize($task, [$id])['staff'], 'workMinutes')) === 0, 'Optimizer immediately uses removed skill');
    $save($input($id, ['isActive' => false]));
    $assert($optimizer->optimize($task, [$id])['staff'] === [], 'Inactive personnel excluded from new automatic planning');
    $save($input($id, ['isActive' => true]));

    $plan = RosterLifecycleFixtures::validPlan($pdo, $services);
    $planId = $plan['id']; $assigned = $plan['planning']['sessions'][0]['staffAssignments'][0]['id'];
    $generator = new GeoFort\Services\Dashboard\Roster\RosterAutoGenerator($pdo, $services['bookings'], $services['plans'], $services['sessions'], $services['config'], $services['templates'], $services['staff'], $optimizer);
    $guideIds = $services['staff']->selectedIdsForPlan($planId);
    $rounds = $plan['planning']['generation']['defaultRounds'];
    $proposal = $generator->preview($planId, $rounds, $guideIds);
    $save($input(20, ['name' => 'Kok voorbereid op hernoemen']));
    $beforeGeneration = $state();
    $reject(fn () => $generator->apply($planId, $plan['revision'], $rounds, true, 1, $guideIds, 'with_staff', true, null, [], $proposal['catalogRevision']), 'STALE_CATALOG');
    $assert($state() === $beforeGeneration, 'Stale generator proposal cannot mutate plan or catalog');
    $payload = $input($assigned, ['isActive' => false]);
    $before = $state(); $preview = $management->preview(RosterStaffChange::fromArray($payload), 1, $secret);
    $assert($state() === $before, 'Impact preview changes no database fields');
    $assert(count($preview['impact']) === 1 && $preview['impact'][0]['planId'] === $planId && $preview['impact'][0]['visitDate'] === '2028-10-10', 'Server impact exposes concept ID/date/reason');
    $reject(fn () => $management->save(RosterStaffChange::fromArray($payload), 1, $secret), 'STALE_STAFF_IMPACT');
    $pdo->beginTransaction(); RosterStaffCatalogGuard::lock($pdo);
    $services['plans']->advanceRevision($planId, 1); $pdo->commit();
    $reject(fn () => $management->save(RosterStaffChange::fromArray([...$payload, 'impactToken' => $preview['impactToken']]), 1, $secret), 'STALE_STAFF_IMPACT');
    $save($input($assigned, ['isActive' => false]));
    $raw = $services['raw']->load($planId);
    $result = $services['validator']->validateData($raw, $raw['plan']['revision'])->toArray();
    $assert(!$result['valid'], 'Inactive assignment/selection blocks finalization');
    $assert(in_array($assigned, $services['staff']->selectedIdsForPlan($planId), true), 'Assignments/day selection are not silently removed');
    $selection = new RosterPlanStaffSelectionService($pdo, $services['plans'], $services['staff']);
    $reject(fn () => $selection->save($planId, $raw['plan']['revision'], RosterStaffCatalogGuard::revision($pdo), array_values(array_diff($services['staff']->selectedIdsForPlan($planId), [$assigned])), 1), 'STAFF_STILL_ASSIGNED');
    $save($input($assigned, ['isActive' => true]));
    $preview = $management->preview(RosterStaffChange::fromArray($input($assigned, ['canGuide' => false])), 1, $secret);
    $assert(count($preview['impact']) === 1, 'Guide role loss impact');
    $preview = $management->preview(RosterStaffChange::fromArray($input($assigned, ['preferences' => []])), 1, $secret);
    $assert(count($preview['impact']) === 1, 'Used skill removal impact');

    // A legacy skill currently referenced by a concept requires deliberate removal + confirmation.
    $pdo->prepare('INSERT INTO roster_staff_module_preferences VALUES (?, ?, ?)')->execute([$assigned, 'Legacy-planmodule', 11]);
    $sid = $plan['planning']['sessions'][0]['id'];
    $pdo->prepare('UPDATE roster_sessions SET module_key = ? WHERE id = ?')->execute(['Legacy-planmodule', $sid]);
    $payload = $input($assigned, ['removeLegacyModuleKeys' => ['Legacy-planmodule']]);
    $preview = $management->preview(RosterStaffChange::fromArray($payload), 1, $secret);
    $assert(count($preview['impact']) === 1 && str_contains(implode(' ', $preview['impact'][0]['reasons']), 'Legacy-planmodule'), 'Explicit legacy removal has used-module impact');
    $save($payload);
    $pdo->prepare('UPDATE roster_sessions SET module_key = ? WHERE id = ?')->execute([$plan['planning']['sessions'][0]['moduleKey'], $sid]);

    // Retained selected-only staff must be explicitly removed; historical references prevent physical delete.
    $services['staff']->addPlanSelection($planId, [$id]); $save($input($id, ['isActive' => false]));
    $revision = $services['plans']->findById($planId)['revision'];
    $reject(fn () => $services['lifecycle']->finalize($planId, $revision, 1), 'ROSTER_INVALID');
    $selection->save($planId, $revision, RosterStaffCatalogGuard::revision($pdo), array_values(array_diff($services['staff']->selectedIdsForPlan($planId), [$id])), 1);
    $revision = $services['plans']->findById($planId)['revision'];
    $final = $services['lifecycle']->finalize($planId, $revision, 1);
    $snapshotsBefore = $pdo->query('SELECT * FROM roster_plan_snapshots ORDER BY id')->fetchAll();
    $finalBefore = $services['service']->getPlan($planId);
    $save($input($assigned, ['name' => 'Historische begeleider hernoemd', 'isActive' => false, 'preferences' => []]));
    $assert($services['service']->getPlan($planId) === $finalBefore && $pdo->query('SELECT * FROM roster_plan_snapshots ORDER BY id')->fetchAll() === $snapshotsBefore, 'Final content/bytes unchanged despite name/status/skill changes');
    $preview = $management->preview(RosterStaffChange::fromArray($input($assigned, ['canGuide' => false])), 1, $secret);
    $assert($preview['impact'] === [], 'Published snapshot never participates in impact');

    $cookPlan = RosterLifecycleFixtures::validPlan($pdo, $services, 28, 2, 4, '2028-10-11');
    $cookPlanId = $cookPlan['id']; $services['staff']->savePlanSettings($cookPlanId, 'with_staff', true, 20);
    $cookFinal = $services['lifecycle']->finalize($cookPlanId, $cookPlan['revision'], 1);
    $pdo->exec(file_get_contents(dirname(__DIR__, 2) . '/database/sql/2026-10-03_create_roster_school_documents.sql'));
    $snapshot = $services['snapshots']->state($cookPlanId)['current'];
    $pdf = (new GeoFort\Services\Dashboard\Roster\RosterSchoolPdfWriter())->render(
        (new GeoFort\Services\Dashboard\Roster\RosterSchoolProjection())->fromSnapshot($snapshot['content']),
    );
    $documents = new GeoFort\Services\Sql\RosterSchoolDocumentSqlRepository($pdo);
    $documents->append(new GeoFort\Services\Dashboard\Roster\RosterSchoolDocument(
        $snapshot['id'], $snapshot['content_hash'], 'school-v1', 'GeoFort_Definitief_Lesrooster_Ronde-C-test.pdf', $pdf, hash('sha256', $pdf),
    ));
    $pdfBefore = $pdo->query('SELECT * FROM roster_school_documents')->fetchAll();
    $snapshotsBefore = $pdo->query('SELECT * FROM roster_plan_snapshots ORDER BY id')->fetchAll();
    $save($input(20, ['name' => 'Kok met een nieuwe naam']));
    $assert($services['staff']->settingsForPlan($cookPlanId)['cookStaffId'] === 20, 'Renamed cook retains immutable ID link');
    $assert($pdo->query('SELECT * FROM roster_school_documents')->fetchAll() === $pdfBefore
        && $documents->find($snapshot['id'])->bytes === $pdf, 'Renamed cook leaves stored PDF bytes/hash and all document fields immutable');
    $assert($services['service']->getPlan($cookPlanId) === $cookFinal && $pdo->query('SELECT * FROM roster_plan_snapshots ORDER BY id')->fetchAll() === $snapshotsBefore, 'Cook rename leaves final snapshots byte/content identical');
    $reopened = $services['lifecycle']->reopen($cookPlanId, $cookFinal['revision'], 1);
    $assert($services['lifecycle']->validate($cookPlanId, $reopened['revision'])['valid'], 'Cook role works after rename/reopen');
    $cookProposal = $generator->preview($cookPlanId, $reopened['planning']['generation']['defaultRounds'], $services['staff']->selectedIdsForPlan($cookPlanId), 'with_staff', true, 20);
    $assert($cookProposal['staffing']['cook']['id'] === 20 && $cookProposal['staffing']['cook']['name'] === 'Kok met een nieuwe naam', 'Generator/optimizer use renamed cook through the same immutable ID');
    $preview = $management->preview(RosterStaffChange::fromArray($input(20, ['canCook' => false])), 1, $secret);
    $assert(count($preview['impact']) === 1 && $preview['impact'][0]['planId'] === $cookPlanId, 'Cook role loss finds current ID based choice');

    foreach (['DELETE FROM roster_staff_members WHERE id = 20', 'UPDATE roster_staff_change_history SET actor_admin_id = 1 WHERE id = 1', 'DELETE FROM roster_staff_change_history WHERE id = 1'] as $sql) {
        try { $pdo->exec($sql); throw new RuntimeException('Immutable guard failed'); } catch (PDOException $e) { $assert($e->getCode() === '45000', 'Immutable trigger SQLSTATE'); }
    }
    $stale = $input(20); $save($input(1, ['name' => 'Catalogus gewijzigd']));
    $reject(fn () => $management->preview(RosterStaffChange::fromArray($stale), 1, $secret), 'STALE_CATALOG');
    $stale = $input(20, ['expectedVersion' => 1]);
    $reject(fn () => $management->preview(RosterStaffChange::fromArray($stale), 1, $secret), 'STALE_STAFF');
    echo "PASS create/edit/deactivate/reactivate/roles/skills/ties/legacy/impact/conflicts/day selection/audit/no-delete/immutable finals/cook rename\n";
} finally { $db->drop(); }

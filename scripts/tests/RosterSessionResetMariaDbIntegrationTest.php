<?php

declare(strict_types=1);
require __DIR__ . '/Support/RosterSessionResetFixtures.php';

use GeoFort\Services\Dashboard\Roster\{RosterSchoolDocumentService, RosterSchoolProjection, RosterSchoolPdfWriter, RosterStaffCatalogGuard};
use GeoFort\Services\Sql\RosterSchoolDocumentSqlRepository;

if (getenv('STATUS_TEST_DB_CONFIRM') !== 'YES_DISPOSABLE') { echo "SKIP disposable consent required\n"; exit(0); }
$db = DisposableBookingMariaDb::create('reset_sessions');
$pdo = RosterSessionResetFixtures::connection($db->database);
$assert = RosterLifecycleFixtures::assert(...);
$state = static fn (bool $preserved = false): array => RosterSessionResetFixtures::state($pdo, $preserved);
$reject = RosterSessionResetFixtures::reject(...);
try {
    RosterLifecycleFixtures::schema($pdo);
    $pdo->exec(file_get_contents(dirname(__DIR__, 2) . '/database/sql/2026-10-03_create_roster_school_documents.sql'));
    $pdo->exec("INSERT INTO admin_users(email,name,password_hash) VALUES ('reset-actor@example.test','Reset actor','test')");
    $s = RosterSessionResetFixtures::services($pdo);
    $plan = RosterLifecycleFixtures::validPlan($pdo, $s);
    $id = $plan['id'];
    $rounds = [
        ['type' => 'welcome', 'label' => 'Aankomst eigen tijd', 'startTime' => '10:05', 'endTime' => '10:20'],
        ['type' => 'round', 'label' => 'Eigen ronde 1', 'startTime' => '10:20', 'endTime' => '11:05'],
        ['type' => 'break', 'label' => 'Eigen pauze', 'startTime' => '11:05', 'endTime' => '11:20'],
        ['type' => 'round', 'label' => 'Eigen ronde 2', 'startTime' => '11:20', 'endTime' => '12:05'],
        ['type' => 'lunch', 'label' => 'Eigen lunch', 'startTime' => '12:05', 'endTime' => '12:35'],
        ['type' => 'departure', 'label' => 'Eigen vertrek', 'startTime' => '12:35', 'endTime' => '12:45'],
    ];
    $s['plans']->saveManualRounds($id, $rounds);
    $pdo->prepare("UPDATE roster_manual_plan_context SET day_start_time='10:05',day_end_time='12:45' WHERE roster_plan_id=?")->execute([$id]);
    $guides = $s['staff']->selectedIdsForPlan($id);
    $s['staff']->addPlanSelection($id, [20]);
    $s['staff']->savePlanSettings($id, 'with_staff', false, 20);
    $s['staff']->replacePlanModulePreferences($id, [['staffId' => $guides[0], 'moduleKeys' => ['Earth-Watch']]]);
    $pdo->prepare("UPDATE roster_plan_staff SET availability_start='09:45',availability_end='15:30' WHERE roster_plan_id=?")->execute([$id]);
    foreach ($plan['planning']['sessions'] as $index => $session) {
        $sid = $session['id']; $gids = $session['groupIds'];
        $s['sessions']->setGroupSupervision($sid, $gids[0], $index === 0 ? 'school' : 'open');
        $pdo->prepare("INSERT INTO roster_session_group_staff(roster_session_id,roster_group_id,staff_id,assignment_source) VALUES (?,?,?,'manual')")->execute([$sid, $gids[1], $session['staffAssignments'][0]['id']]);
        $pdo->prepare("UPDATE roster_sessions SET location_label='Sessielocatie die weg moet' WHERE id=?")->execute([$sid]);
    }
    $pdo->prepare("UPDATE roster_plans SET updated_at='2020-01-01 00:00:00',updated_by_admin_id=1 WHERE id=?")->execute([$id]);
    $preserved = $state(true);
    $presentation = $s['service']->getPlan($id);
    $count = count($presentation['planning']['sessions']);
    $result = $s['reset']->reset($id, $plan['revision'], 2);
    $assert($result === ['planId' => $id, 'revision' => $plan['revision'] + 1, 'deletedSessionCount' => $count, 'alreadyEmpty' => false], 'Real reset response/revision exactly once');
    $assert($state(true) === $preserved, 'All preserved tables/fields including groups/context/timeline/selection/settings/catalog/audit remain identical');
    foreach (['roster_sessions', 'roster_session_groups', 'roster_session_staff', 'roster_session_group_staff'] as $table) {
        $assert((int) $pdo->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn() === 0, 'All session dependencies removed: ' . $table);
    }
    $afterPlan = $s['plans']->findById($id);
    $assert((int) $pdo->query('SELECT updated_by_admin_id FROM roster_plans WHERE id=' . $id)->fetchColumn() === 2 && $afterPlan['updated_at'] !== '2020-01-01 00:00:00', 'Actor/time updated on real reset');
    $afterPresentation = $s['service']->getPlan($id);
    $assert($afterPresentation['planning']['rounds'] === $presentation['planning']['rounds']
        && $afterPresentation['planning']['modules'] === $presentation['planning']['modules']
        && $afterPresentation['planning']['selectedStaffIds'] === $presentation['planning']['selectedStaffIds']
        && $afterPresentation['planning']['staffingSettings'] === $presentation['planning']['staffingSettings'], 'Planning API retains rounds/modules/personnel/settings');

    // No-op: assert EVERY table value and the executed SQL, not only revision/count.
    $beforeNoOp = $state(); $pdo->statements = [];
    $noOp = $s['reset']->reset($id, $result['revision'], 1);
    $noOpWrites = $pdo->writes();
    $assert($noOp === ['planId' => $id, 'revision' => $result['revision'], 'deletedSessionCount' => 0, 'alreadyEmpty' => true], 'Already empty returns unchanged revision');
    $assert($noOpWrites === [] && $state() === $beforeNoOp && !$pdo->inTransaction(), 'No-op executes no write SQL and changes no database value/actor/timestamp');
    $reject(fn () => $s['reset']->reset($id, $plan['revision'], 1), 'ROSTER_REVISION_CONFLICT');
    $assert($state() === $beforeNoOp, 'Stale empty reset still returns conflict without writes');

    // Continue manually with preserved custom rounds, then generate again.
    $session = $s['sessionService']->saveActivity($id, null, $result['revision'], 'Earth-Watch', '10:20', '11:05', null, array_column($plan['groups'], 'id'), 1, [$guides[0]], null);
    $assert(count($s['service']->getPlan($id)['planning']['sessions']) === 1, 'Manual planning works after reset');
    $s['reset']->reset($id, $session['revision'], 1);
    $fresh = $s['service']->getPlan($id);
    $inputRounds = array_values(array_map(static fn (array $block): array => array_diff_key($block, ['type' => true]), array_filter($fresh['planning']['rounds'], static fn (array $block): bool => $block['type'] === 'round')));
    $generated = $s['generator']->apply($id, $fresh['revision'], $inputRounds, false, 1, $guides, 'with_staff', false, null, [], $fresh['planning']['catalogRevision']);
    $assert($generated['sessionCount'] > 0 && $s['service']->getPlan($id)['planning']['rounds'] === $rounds, 'Generator rebuilds from preserved rounds');

    // Simulate a failure after cascade deletion by rejecting the revision UPDATE.
    foreach ($s['service']->getPlan($id)['planning']['sessions'] as $index => $session) {
        $s['sessions']->setGroupSupervision($session['id'], $session['groupIds'][0], $index === 0 ? 'school' : 'open');
        $pdo->prepare("INSERT INTO roster_session_group_staff(roster_session_id,roster_group_id,staff_id,assignment_source) VALUES (?,?,?,'manual')")
            ->execute([$session['id'], $session['groupIds'][0], $guides[0]]);
    }
    $rollbackBefore = $state();
    $pdo->exec("CREATE TRIGGER reset_force_failure BEFORE UPDATE ON roster_plans FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Forced reset rollback'");
    try { $s['reset']->reset($id, $generated['revision'], 2); throw new RuntimeException('Expected forced failure'); }
    catch (RuntimeException $e) { $assert($e->getPrevious() instanceof PDOException, 'Forced revision failure reached after deleting'); }
    finally { $pdo->exec('DROP TRIGGER reset_force_failure'); }
    $assert($state() === $rollbackBefore && !$pdo->inTransaction(), 'Forced failure restores sessions, all cascading links, revision and metadata');

    // Unexpected types are never silently deleted; absence of activities is still a no-op.
    $pdo->prepare("UPDATE roster_sessions SET item_type='unexpected' WHERE roster_plan_id=? LIMIT 1")->execute([$id]);
    $unexpected = $state(); $pdo->statements = [];
    $reject(fn () => $s['reset']->reset($id, $generated['revision'], 2), 'UNSUPPORTED_SESSION_TYPE');
    $assert($pdo->writes() === [] && $state() === $unexpected, 'Unknown type fails before all writes');
    $pdo->prepare("UPDATE roster_sessions SET item_type='unexpected' WHERE roster_plan_id=?")->execute([$id]);
    $unexpected = $state(); $pdo->statements = [];
    $assert($s['reset']->reset($id, $generated['revision'], 2)['alreadyEmpty'], 'Zero activities is a no-op even with another item type');
    $assert($pdo->writes() === [] && $state() === $unexpected, 'Non-activity data preserved by no-op');

    // Booking-linked custom plan + a real rendered and stored school PDF.
    $booking = RosterSessionResetFixtures::booking($pdo, '2028-10-20');
    $historic = RosterLifecycleFixtures::validPlan($pdo, $s, 40, 2, 2, '2028-10-20', $booking);
    $final = $s['lifecycle']->finalize($historic['id'], $historic['revision'], 1);
    $documents = new RosterSchoolDocumentService(new RosterSchoolDocumentSqlRepository($pdo), $s['snapshots'], $s['bookings'], new RosterSchoolProjection(), new RosterSchoolPdfWriter());
    $document = $documents->ensurePrepared($booking, $final['finalization']['snapshotId']);
    $snapshotRows = $pdo->query('SELECT * FROM roster_plan_snapshots ORDER BY id')->fetchAll();
    $documentRows = $pdo->query('SELECT * FROM roster_school_documents ORDER BY id')->fetchAll();
    $finalBefore = $state();
    $reject(fn () => $s['reset']->reset($historic['id'], $final['revision'], 1), 'PLAN_NOT_EDITABLE');
    $assert($state() === $finalBefore, 'Published rejection changes nothing');
    $reopened = $s['lifecycle']->reopen($historic['id'], $final['revision'], 1);
    $keep = $state(true);
    $cleared = $s['reset']->reset($historic['id'], $reopened['revision'], 2);
    $assert($state(true) === $keep, 'Reopened reset preserves booking/program/snapshot/document/lifecycle data');
    $assert($pdo->query('SELECT published_snapshot_id FROM roster_plans WHERE id=' . $historic['id'])->fetchColumn() === null, 'Published snapshot pointer remains null');
    $assert($pdo->query('SELECT * FROM roster_plan_snapshots ORDER BY id')->fetchAll() === $snapshotRows
        && $pdo->query('SELECT * FROM roster_school_documents ORDER BY id')->fetchAll() === $documentRows, 'Final snapshot/document all fields and bytes immutable');
    $again = $s['service']->getPlan($historic['id']);
    $generated = $s['generator']->apply($historic['id'], $again['revision'], $again['planning']['generation']['defaultRounds'], false, 1, $again['planning']['selectedStaffIds'], 'with_staff', true, null, [], $again['planning']['catalogRevision']);
    $newFinal = $s['lifecycle']->finalize($historic['id'], $generated['revision'], 1);
    $assert($newFinal['finalization']['snapshotId'] !== $final['finalization']['snapshotId'], 'Rebuilt roster receives a new final snapshot');
    $assert((new RosterSchoolDocumentSqlRepository($pdo))->find($document->snapshotId)->bytes === $document->bytes, 'Old school PDF byte-identical after re-finalization');

    // Standard booking program and fingerprint stay unchanged as well.
    $standardBooking = RosterSessionResetFixtures::booking($pdo, '2028-11-20');
    $standard = $s['service']->createFromBooking($standardBooking, 1)['plan'];
    $sid = $s['sessions']->insertActivity($standard['id'], $standard['planning']['modules'][0]['key'], '10:15', '11:00', 'Test', 1);
    $s['sessions']->replaceGroups($sid, array_column($standard['groups'], 'id'));
    $keep = $state(true); $s['reset']->reset($standard['id'], $standard['revision'], 1);
    $assert($state(true) === $keep, 'Standard booking context and every preserved value unchanged');
    $pdo->prepare("UPDATE roster_plans SET status='checked' WHERE id=?")->execute([$standard['id']]);
    $before = $state(); $reject(fn () => $s['reset']->reset($standard['id'], $standard['revision'] + 1, 1), 'PLAN_NOT_EDITABLE');
    $assert($state() === $before, 'Legacy checked status is not editable');
    $reject(fn () => $s['reset']->reset(999999, 1, 1), 'PLAN_NOT_FOUND');

    $output = getenv('ROSTER_ROUND_D_OUTPUT');
    if ($output) file_put_contents($output . '/reset-proof.json', json_encode(['realResetCount' => $count, 'noOp' => $noOp, 'noOpWriteStatements' => $noOpWrites, 'fullNoOpDatabaseEqual' => true, 'preservedDataEqual' => true, 'rollbackEqual' => true, 'oldSnapshotId' => $document->snapshotId, 'oldPdfSha256' => hash('sha256', $document->bytes), 'newSnapshotId' => $newFinal['finalization']['snapshotId']], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    echo "PASS reset/cascades/preserved context+rounds+staff/no-op without write SQL/manual+generator/reopen/immutable snapshot+PDF/rollback/conflicts\n";
} finally { if ($pdo->inTransaction()) $pdo->rollBack(); $db->drop(); }

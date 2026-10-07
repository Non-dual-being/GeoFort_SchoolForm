<?php

declare(strict_types=1);
require __DIR__ . '/Support/RosterLifecycleFixtures.php';

use GeoFort\Services\Dashboard\Roster\RosterPlanException;
use GeoFort\Services\Dashboard\Roster\RosterFinalSnapshot;

$db = DisposableBookingMariaDb::create('roster_v1_lifecycle');
try {
    RosterLifecycleFixtures::schema($db->pdo); $s = RosterLifecycleFixtures::services($db->pdo); $assert = RosterLifecycleFixtures::assert(...);
    $p = RosterLifecycleFixtures::validPlan($db->pdo, $s); $id = $p['id'];
    $db->pdo->exec("INSERT INTO roster_staff_members(display_name,is_active,can_guide,can_cook) VALUES ('Geselecteerd maar vrij',1,1,0)");
    $idleId = (int) $db->pdo->lastInsertId(); $s['staff']->addPlanSelection($id, [$idleId]);
    $assert($s['lifecycle']->validate($id, 1)['valid'], 'Valid draft rejected.');
    $p = $s['lifecycle']->finalize($id, 1, 1);
    $assert($p['status'] === 'published' && $p['revision'] === 2 && $p['finalization']['trusted'], 'Final transition failed.');
    $assert(in_array($idleId, array_column($p['planning']['staffCatalog'], 'id'), true), 'Snapshot lost a selected teacher with free rounds.');
    $snapshot = $s['snapshots']->state($id)['current']; $bytes = $snapshot['content_json'];
    $assert(hash_equals($snapshot['content_hash'], RosterFinalSnapshot::hash($snapshot['content'])), 'Hash differs.');
    $beforeNames = $p['planning']['staffCatalog'];
    $db->pdo->exec("UPDATE roster_staff_members SET display_name = CONCAT('Changed ', id)");
    $assert($s['service']->getPlan($id)['planning']['staffCatalog'] === $beforeNames, 'Snapshot display changed after renaming staff.');
    foreach (["UPDATE roster_plan_snapshots SET content_hash = REPEAT('a',64) WHERE id = {$snapshot['id']}", "DELETE FROM roster_plan_snapshots WHERE id = {$snapshot['id']}"] as $sql) {
        try { $db->pdo->exec($sql); throw new RuntimeException('Snapshot mutation succeeded.'); } catch (PDOException $e) { $assert($e->getCode() === '45000', 'Unexpected snapshot guard failure.'); }
    }
    try { $s['lifecycle']->finalize($id, 1, 1); throw new RuntimeException('Duplicate click succeeded.'); } catch (RosterPlanException $e) { $assert($e->publicCode === 'STALE_REVISION', 'Wrong duplicate conflict.'); }
    $session = $p['planning']['sessions'][0];
    try { $s['sessionService']->deleteActivity($id, $session['id'], 2, 1); throw new RuntimeException('Published session mutation succeeded.'); } catch (RosterPlanException $e) { $assert($e->httpStatus === 409, 'Wrong readonly failure.'); }
    $p = $s['lifecycle']->reopen($id, 2, 1);
    $assert($p['status'] === 'concept' && $p['revision'] === 3 && !$p['finalization']['trusted'] && $p['finalization']['historyCount'] === 1, 'Reopen failed.');
    $assert($s['snapshots']->find($id, (int) $snapshot['id'])['content_json'] === $bytes, 'Reopen altered history.');
    try { $s['service']->deleteConcept($id); throw new RuntimeException('History deleted.'); } catch (RosterPlanException $e) { $assert($e->publicCode === 'FINAL_HISTORY_EXISTS', 'Wrong history guard.'); }
    $p = $s['lifecycle']->finalize($id, 3, 1);
    $assert($p['revision'] === 4 && $p['finalization']['historyCount'] === 2, 'New final did not append.');
    $assert((int) $db->pdo->query('SELECT COUNT(*) FROM roster_plan_lifecycle_events')->fetchColumn() === 3, 'Lifecycle audit missing.');
    $broken = RosterLifecycleFixtures::validPlan($db->pdo, $s, 28, 2, 2, '2028-10-11');
    $s['sessions']->delete($broken['planning']['sessions'][0]['id'], $broken['id']);
    try { $s['lifecycle']->finalize($broken['id'], 1, 1); throw new RuntimeException('Incomplete plan finalized.'); } catch (RosterPlanException $e) { $assert($e->publicCode === 'ROSTER_INVALID' && $e->validation['errors'] !== [], 'Missing validation details.'); }
    $assert($s['service']->getPlan($broken['id'])['revision'] === 1 && $s['snapshots']->state($broken['id'])['historyCount'] === 0, 'Failure wrote state.');
    $db->pdo->prepare("UPDATE roster_plans SET status = 'published' WHERE id = ?")->execute([$broken['id']]);
    $assert(!$s['service']->getPlan($broken['id'])['finalization']['trusted'], 'Legacy publication trusted.');
    $assert($s['lifecycle']->reopen($broken['id'], 1, 1)['status'] === 'concept', 'Legacy publication cannot reopen.');
    $rollback = RosterLifecycleFixtures::validPlan($db->pdo, $s, 250, 10, 5, '2028-10-12');
    $rollbackId = $rollback['id'];
    $db->pdo->exec("CREATE TRIGGER fail_roster_lifecycle_audit BEFORE INSERT ON roster_plan_lifecycle_events FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='forced lifecycle audit failure'");
    try { $s['lifecycle']->finalize($rollbackId, 1, 1); throw new RuntimeException('Audit failure committed.'); } catch (PDOException $e) { $assert($e->getCode() === '45000', 'Unexpected audit failure.'); }
    $assert(!$db->pdo->inTransaction() && $s['service']->getPlan($rollbackId)['status'] === 'concept' && $s['service']->getPlan($rollbackId)['revision'] === 1 && $s['snapshots']->state($rollbackId)['historyCount'] === 0, 'Failed audit did not roll back status/revision/snapshot.');
    $db->pdo->exec('DROP TRIGGER fail_roster_lifecycle_audit');
    $final = $s['lifecycle']->finalize($rollbackId, 1, 1);
    $assert($final['finalization']['trusted'] && count($final['groups']) === 10, '250 students / 10 groups did not finalize.');
    $db->pdo->exec("CREATE TRIGGER fail_roster_lifecycle_audit BEFORE INSERT ON roster_plan_lifecycle_events FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='forced lifecycle audit failure'");
    try { $s['lifecycle']->reopen($rollbackId, 2, 1); throw new RuntimeException('Reopen audit failure committed.'); } catch (PDOException $e) { $assert($e->getCode() === '45000', 'Unexpected reopen audit failure.'); }
    $assert($s['service']->getPlan($rollbackId)['revision'] === 2 && $s['snapshots']->state($rollbackId)['current'] !== null, 'Failed reopen lost current snapshot.');
    $db->pdo->exec('DROP TRIGGER fail_roster_lifecycle_audit');
    echo "OK: transactional lifecycle, append-only snapshots, stable names, stale requests, readonly and history guards.\n";
} finally { $db->drop(); }

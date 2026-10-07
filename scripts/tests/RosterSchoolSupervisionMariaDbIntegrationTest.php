<?php

declare(strict_types=1);
require __DIR__ . '/Support/RosterLifecycleFixtures.php';
use GeoFort\Services\Dashboard\Roster\RosterPlanException;

$db = DisposableBookingMariaDb::create('roster_v1_school');
try {
    RosterLifecycleFixtures::schema($db->pdo); $s = RosterLifecycleFixtures::services($db->pdo); $assert = RosterLifecycleFixtures::assert(...);
    $p = RosterLifecycleFixtures::validPlan($db->pdo, $s, 28, 2, 5); $id = $p['id'];
    $ke = array_values(array_filter($p['planning']['sessions'], static fn (array $session): bool => $session['moduleKey'] === 'Klimaat-Experience'))[0];
    $s['sessionService']->saveGroupStaffAssignment($id, $ke['id'], 1, $ke['groupIds'][0], [], 1, 'school');
    $p = $s['service']->getPlan($id); $stored = $s['raw']->load($id);
    $assert($s['validator']->validate($id, 2)->isValid(), 'Mixed school/staff shared session rejected.');
    $keView = array_values(array_filter($p['planning']['sessions'], static fn (array $session): bool => $session['id'] === $ke['id']))[0];
    $assert(array_column($keView['groupSupervision'], 'kind') === ['school', 'staff'], 'Group distinction lost.');
    $s['sessionService']->saveGroupStaffAssignment($id, $ke['id'], 2, $ke['groupIds'][1], [], 1, 'open');
    $assert(!$s['validator']->validate($id, 3)->isValid(), 'OPEN treated as School.');
    try { $s['lifecycle']->finalize($id, 3, 1); throw new RuntimeException('OPEN finalized.'); } catch (RosterPlanException $e) { $assert($e->publicCode === 'ROSTER_INVALID', 'Wrong OPEN failure.'); }
    $s['sessionService']->saveGroupStaffAssignment($id, $ke['id'], 3, $ke['groupIds'][1], [], 1, 'school');
    $assert($s['lifecycle']->finalize($id, 4, 1)['finalization']['trusted'], 'Explicit permitted School did not finalize.');
    $p = RosterLifecycleFixtures::validPlan($db->pdo, $s, 28, 2, 2, '2028-10-11');
    try { $s['sessionService']->saveGroupStaffAssignment($p['id'], $p['planning']['sessions'][0]['id'], 1, $p['groups'][0]['id'], [], 1, 'school'); throw new RuntimeException('Forbidden school accepted.'); } catch (RosterPlanException $e) { $assert($e->publicCode === 'SCHOOL_NOT_ALLOWED', 'Wrong forbidden school code.'); }
    $assert($s['service']->getPlan($p['id'])['revision'] === 1, 'Rejected supervision changed revision.');
    $p = RosterLifecycleFixtures::validPlan($db->pdo, $s, 28, 2, 5, '2028-10-12'); $id = $p['id'];
    $ke = array_values(array_filter($p['planning']['sessions'], static fn (array $session): bool => $session['moduleKey'] === 'Klimaat-Experience'))[0];
    $s['sessionService']->saveGroupStaffAssignment($id, $ke['id'], 1, $ke['groupIds'][0], [], 1, 'school');
    $s['sessionService']->saveActivity($id, $ke['id'], 2, $ke['moduleKey'], $ke['startTime'], $ke['endTime'], 'Gewijzigde locatie', $ke['groupIds'], 1, array_column($ke['staffAssignments'], 'id'), $p['planning']['staffingSettings']['cookStaffId']);
    $assert($s['sessions']->groupSupervisionForPlan($id)[$ke['id']][$ke['groupIds'][0]] === 'school', 'Location edit lost explicit school confirmation.');
    $s['sessionService']->saveActivity($id, $ke['id'], 3, $ke['moduleKey'], $ke['startTime'], $ke['endTime'], 'Gewijzigde locatie', [$ke['groupIds'][0]], 1, array_column($ke['staffAssignments'], 'id'), $p['planning']['staffingSettings']['cookStaffId']);
    $assert(($s['staff']->assignmentsForPlan($id)[$ke['id']] ?? []) === [], 'Removing staffed group left stale aggregate staff on the school group.');
    $assert($s['sessions']->groupSupervisionForPlan($id)[$ke['id']][$ke['groupIds'][0]] === 'school', 'Removing other group lost school confirmation.');
    echo "OK: explicit group school/staff/open, allowed configuration and atomic revision guards.\n";
} finally { $db->drop(); }

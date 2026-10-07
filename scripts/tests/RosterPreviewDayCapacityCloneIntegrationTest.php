<?php

declare(strict_types=1);

require __DIR__ . '/Support/RosterLifecycleFixtures.php';

use GeoFort\Services\Dashboard\Roster\{RosterAutoGenerator, RosterStaffOptimizer, RosterPlanException, RosterManualTimeline};

ini_set('zend.exception_ignore_args', '1');
if (getenv('ROSTER_CAPACITY_CLONE_CONFIRM') !== 'YES_LOCAL_CLONE') {
    echo "SKIP: requires explicit disposable clone confirmation; creates no database/schema.\n";
    exit(0);
}
$assert = RosterLifecycleFixtures::assert(...);
$connect = static function () use ($assert): PDO {
    $ini = parse_ini_file((string) getenv('ROSTER_CAPACITY_CLONE_INI'), true, INI_SCANNER_RAW);
    $client = $ini['client'] ?? $ini;
    $assert(($client['host'] ?? '') === '127.0.0.1' && (int) ($client['port'] ?? 0) === 58744, 'Clone configuration mismatch.');
    $pdo = new PDO('mysql:host=127.0.0.1;port=58744;dbname=onderwijsboeking_v2;charset=utf8mb4', $client['user'], $client['password'] ?? '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]);
    $proof = $pdo->query('SELECT VERSION() AS v, @@port AS p, DATABASE() AS d')->fetch();
    $assert($proof['v'] === '10.11.14-MariaDB' && (int) $proof['p'] === 58744 && $proof['d'] === 'onderwijsboeking_v2', 'Live clone mismatch.');
    return $pdo;
};
$pdo = $connect(); $s = RosterLifecycleFixtures::services($pdo);
$generator = new RosterAutoGenerator($pdo, $s['bookings'], $s['plans'], $s['sessions'], $s['config'], $s['templates'], $s['staff'], new RosterStaffOptimizer($s['staff']));
$original = ['aanvraag_onderwijs_selecties','aanvragen','admin_users','booking_change_history','booking_day_settings','booking_price_snapshots','booking_rule_overrides','booking_status_history','calendar_date_change_history','calendar_date_change_history_dates','capacity_targets','disabled_dates','form_submit_log','generated_disabled_date_release_overrides','legacy_booking_import_runs','login_attempts','roosters'];
$hashes = static function () use ($pdo, $original): array {
    $result = [];
    foreach ($original as $table) {
        $rows = array_map(serialize(...), $pdo->query("SELECT * FROM `{$table}`")->fetchAll(PDO::FETCH_NUM)); sort($rows, SORT_STRING);
        $result[$table] = ['count' => count($rows), 'sha256' => hash('sha256', serialize($rows))];
    }
    return $result;
};
$before = $hashes(); $dates = []; $checks = 0;
$date = new DateTimeImmutable('2042-01-06'); $date = $date->modify('next monday')->modify('+' . random_int(0, 5000) . ' weeks');
$nextDate = static function () use (&$date, &$dates, $pdo, $assert): string {
    do {
        $date = $date->modify('+7 days'); $value = $date->format('Y-m-d');
        $q = $pdo->prepare('SELECT COUNT(*) FROM roster_plans WHERE visit_date=?'); $q->execute([$value]);
    } while ((int) $q->fetchColumn() !== 0);
    $q = $pdo->prepare('SELECT COUNT(*) FROM booking_day_settings WHERE visit_date=?'); $q->execute([$value]);
    $assert((int) $q->fetchColumn() === 0, 'Original date row must not be touched.');
    $dates[] = $value; return $value;
};
$make = static function (string $visit, int $groups, array $modules, bool $optionalGuides = false) use ($connect, $pdo, $s): array {
    $connect();
    $plan = $s['service']->createManual('Hotfix capacity test', 'Synthetic', $visit, 'primairOnderwijs', 'dag', $groups * 20, $groups, 1)['plan'];
    $s['plans']->saveManualModuleKeys($plan['id'], $modules); $ids = [];
    foreach ($modules as $module) {
        if (!$optionalGuides && $s['config']->schoolSupervisionAllowed($module)) continue;
        for ($i = 0; $i < 2; $i++) {
            $pdo->prepare('INSERT INTO roster_staff_members(display_name,is_active,can_guide,can_cook) VALUES (?,1,1,0)')->execute(['Hotfix '.$plan['id'].' '.$module.' '.$i]);
            $id = (int) $pdo->lastInsertId(); $ids[] = $id;
            $pdo->prepare('INSERT INTO roster_staff_module_preferences(staff_id,module_key,preference_rank) VALUES (?,?,7)')->execute([$id,$module]);
        }
    }
    return ['id' => $plan['id'], 'revision' => $plan['revision'], 'staff' => $ids, 'rounds' => $s['templates']->defaultRounds('dag', count($modules))];
};
$capacityCodes = static fn (array $issues): array => array_values(array_column(array_filter($issues, static fn (array $issue): bool => $issue['code'] === 'MODULE_CAPACITY'), 'moduleKey'));
$exercise = static function (array &$plan, array $expected) use ($connect, $s, $generator, $assert, $capacityCodes, &$checks): array {
    $preview = $generator->preview($plan['id'], $plan['rounds'], $plan['staff'], 'with_staff');
    $codes = $capacityCodes($preview['hardIssues']); sort($codes); sort($expected);
    $assert($codes === $expected && $preview['canApply'] === true && $preview['canFinalize'] === ($expected === []), 'Incorrect preview decision: '.json_encode($preview['hardIssues']));
    $connect(); $saved = $generator->apply($plan['id'], $plan['revision'], $plan['rounds'], $plan['revision'] > 1, 1, $plan['staff'], 'with_staff'); $plan['revision'] = $saved['revision'];
    $validation = $s['validator']->validate($plan['id'], $plan['revision']); $storedCodes = $capacityCodes($validation->errors); sort($storedCodes);
    $assert($storedCodes === $codes && $validation->isValid() === ($expected === []), 'Preview/persisted decision differs: '.json_encode($validation->toArray()));
    if ($expected !== []) {
        $connect(); try { $s['lifecycle']->finalize($plan['id'], $plan['revision'], 1); throw new RuntimeException('Overcapacity finalized.'); }
        catch (RosterPlanException $e) { $assert($e->publicCode === 'ROSTER_INVALID', 'Unexpected finalize rejection.'); }
    }
    $checks++; return $preview;
};
$publish = static function (array $plan) use ($connect, $s): void { $connect(); $s['lifecycle']->finalize($plan['id'], $plan['revision'], 1); };
try {
    $modules = ['Klimaat-Experience', 'Klimparcours']; $day = $nextDate();
    $other = $make($day, 2, $modules); $exercise($other, []); $publish($other);
    $candidate = $make($day, 4, $modules); $exercise($candidate, ['Klimaat-Experience']); // KE total 3, VS total 3.
    $exercise($candidate, ['Klimaat-Experience']); // Replace/recompute must not count own sessions twice.
    $second = $make($day, 2, $modules); $exercise($second, []); $publish($second);
    $exercise($candidate, ['Klimaat-Experience', 'Klimparcours']); // KE total 4, VS total 4.

    // Changing the day wizard requires clearing lessons first; use the supported reset route.
    $oldSessions = $s['sessions']->findByPlanId($candidate['id']);
    $connect(); $reset = new \GeoFort\Services\Dashboard\Roster\RosterSessionResetService($pdo, $s['plans'], $s['sessions']);
    $cleared = $reset->reset($candidate['id'], $candidate['revision'], 1);
    $candidate['revision'] = $cleared['revision'];
    $candidate['rounds'] = [['label'=>'Later 1','startTime'=>'13:00','endTime'=>'13:45'],['label'=>'Later 2','startTime'=>'13:45','endTime'=>'14:30']];
    $connect(); $changed = $s['service']->saveManualProgram($candidate['id'], $candidate['revision'], null, 1, RosterManualTimeline::fromArray([
        ['type'=>'welcome','label'=>'Welcome','startTime'=>'12:45','endTime'=>'13:00'],
        ...array_map(static fn (array $round): array => ['type'=>'round', ...$round], $candidate['rounds']),
        ['type'=>'departure','label'=>'Departure','startTime'=>'14:30','endTime'=>'14:45'],
    ]));
    $candidate['revision'] = $changed['revision'];
    foreach ($oldSessions as $session) {
        $later = substr($session['start_time'], 0, 5) === '10:15' ? ['13:00','13:45'] : ['13:45','14:30'];
        $connect(); $saved = $s['sessionService']->saveActivity($candidate['id'], null, $candidate['revision'], $session['module_key'], ...[...$later, $session['location_label'], $session['group_ids'], 1]);
        $candidate['revision'] = $saved['revision'];
        foreach ($session['group_ids'] as $groupId) {
            $connect(); $assigned = $s['sessionService']->saveGroupStaffAssignment($candidate['id'], $saved['sessionId'], $candidate['revision'], $groupId, [], 1, 'school');
            $candidate['revision'] = $assigned['revision'];
        }
    }
    $corrected = $s['validator']->validate($candidate['id'], $candidate['revision']);
    $assert($corrected->isValid(), 'Manual correction must restore validity: '.json_encode($corrected->toArray()));
    $exercise($candidate, []); // Same date, no time overlap. Includes own stored sessions but does not double-count.
    $raw = $s['raw']->load($candidate['id']); $link = $raw['links'][0];
    $connect(); $changed = $s['sessionService']->saveGroupStaffAssignment($candidate['id'], $link['roster_session_id'], $candidate['revision'], $link['roster_group_id'], [], 1, 'open'); $candidate['revision'] = $changed['revision'];
    $assert(in_array('OPEN_SUPERVISION', array_column($s['validator']->validate($candidate['id'], $candidate['revision'])->errors, 'code'), true), 'OPEN must block finalization.');
    $connect(); $changed = $s['sessionService']->saveGroupStaffAssignment($candidate['id'], $link['roster_session_id'], $candidate['revision'], $link['roster_group_id'], [], 1, 'school'); $candidate['revision'] = $changed['revision']; $publish($candidate);
    $snapshot = $s['snapshots']->state($candidate['id'])['current'];
    foreach (['UPDATE roster_plan_snapshots SET content_hash=content_hash WHERE id=?', 'DELETE FROM roster_plan_snapshots WHERE id=?'] as $sql) {
        $connect(); try { $pdo->prepare($sql)->execute([$snapshot['id']]); throw new RuntimeException('Immutable snapshot mutation succeeded.'); }
        catch (PDOException $e) { $assert($e->getCode() === '45000', 'Wrong immutable guard failure.'); }
    }
    $connect(); $reopened = $s['lifecycle']->reopen($candidate['id'], $candidate['revision'] + 1, 1);
    $assert($s['snapshots']->find($candidate['id'], (int) $snapshot['id'])['content_json'] === $snapshot['content_json'], 'Reopen changed frozen snapshot.');
    $connect(); $cleared = $reset->reset($candidate['id'], $reopened['revision'], 1); $candidate['revision'] = $cleared['revision'];
    $exercise($candidate, []); $publish($candidate);

    $regularDay = $nextDate(); $regular = ['Earth-Watch', 'Stop-de-Klimaat-Klok'];
    $first = $make($regularDay, 2, $regular); $exercise($first, []); $publish($first);
    $conflict = $make($regularDay, 4, $regular); $exercise($conflict, $regular);
    foreach ([2=>4,3=>6,4=>8,5=>10] as $count=>$groups) {
        $keys = array_slice(['Klimaat-Experience','Klimparcours','Earth-Watch','Stop-de-Klimaat-Klok','Dynamische-Globe'], 0, $count);
        $free = $make($nextDate(), $groups, $keys); $preview = $exercise($free, []);
        foreach ($preview['sessions'] as $session) if ($s['config']->schoolSupervisionAllowed($session['moduleKey'])) $assert($session['schoolFallback'], 'No eligible staff must choose School.');
        $publish($free);
    }
    $guided = $make($nextDate(), 4, $modules, true); $preview = $exercise($guided, []);
    foreach ($preview['sessions'] as $session) $assert(!$session['schoolFallback'] && $session['assignedStaffIds'] !== [], 'Eligible GeoFort staff must precede School.'); $publish($guided);
    echo "PASS: {$checks} preview/save comparisons; concept overcapacity saved, finalization rejected, manual correction finalized, KE=2 VS=3, regular modules, own exclusion, OPEN, GeoFort/School and free 2/4 3/6 4/8 5/10.\n";
} finally {
    $connect(); $pdo->beginTransaction();
    try {
        foreach ($dates as $visit) {
            $q = $pdo->prepare('SELECT * FROM booking_day_settings WHERE visit_date=? FOR UPDATE'); $q->execute([$visit]);
            foreach ($q->fetchAll() as $row) foreach (['max_schools_override','max_students_override','override_reason','created_by_admin_id','updated_by_admin_id'] as $key) $assert($row[$key] === null, 'Unexpected populated test date.');
            $pdo->prepare('DELETE FROM booking_day_settings WHERE visit_date=?')->execute([$visit]);
        }
        $assert($hashes() === $before, 'Original 17 tables changed.'); $pdo->commit();
        echo "PASS: all 17 original table counts/content hashes unchanged; only synthetic calendar lock rows removed.\n";
    } catch (Throwable $e) { $pdo->rollBack(); throw $e; }
}

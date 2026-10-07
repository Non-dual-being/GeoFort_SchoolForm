<?php

declare(strict_types=1);
require __DIR__ . '/Support/RosterLifecycleFixtures.php';

$db = DisposableBookingMariaDb::create('roster_v1_validation');
try {
    RosterLifecycleFixtures::schema($db->pdo);
    $s = RosterLifecycleFixtures::services($db->pdo);
    $plan = RosterLifecycleFixtures::validPlan($db->pdo, $s);
    $data = $s['raw']->load($plan['id']);
    $assert = RosterLifecycleFixtures::assert(...);
    $check = static function (array $data, ?string $code = null) use ($s, $assert): void {
        $result = $s['validator']->validateData($data, (int) $data['plan']['revision']);
        $assert($code === null ? $result->isValid() : in_array($code, array_column($result->errors, 'code'), true), json_encode($result->toArray(), JSON_UNESCAPED_UNICODE));
    };
    $check($data); // Shared session, two groups, one skilled guide with preference rank 7.
    foreach ([1, 28, 39, 40, 160, 161, 175, 250, 1001] as $count) {
        $d = $data; $d['context']['student_count'] = $count; $check($d);
        $r = $s['validator']->validateData($d, 1);
        $assert(in_array('STUDENT_COUNT_ADVICE', array_column($r->warnings, 'code'), true) === ($count < 40 || $count > 160), 'Count warning boundary changed.');
    }
    foreach ([0, -1, 12.5, '28', 'invalid', null, true, [], new stdClass()] as $count) {
        $d = $data; $d['context']['student_count'] = $count; $check($d, 'INVALID_STUDENT_COUNT');
    }
    $d = $data; array_pop($d['sessions']); $check($d, 'MISSING_MODULE');
    $d = $data; $duplicate = $d['sessions'][0]; $duplicate['id'] = 99999; $d['sessions'][] = $duplicate;
    foreach ($data['links'] as $link) if ($link['roster_session_id'] === $data['sessions'][0]['id']) { $link['roster_session_id'] = 99999; $d['links'][] = $link; }
    $check($d, 'DUPLICATE_MODULE'); $check($d, 'MODULE_CAPACITY'); $check($d, 'GROUP_OVERLAP');
    $d = $data; $d['links'][0]['group_plan_id'] = 99999; $check($d, 'INVALID_GROUP_RELATION');
    $d = $data; $d['sessions'][0]['module_key'] = null; $check($d, 'INVALID_SESSION_MODULE');
    $d = $data; $d['sessions'][0]['start_time'] = '09:00:00'; $check($d, 'INVALID_SESSION_TIME');
    $d = $data; $d['sessionStaff'] = []; $d['settings']['staffing_mode'] = 'lesson_only'; $check($d, 'OPEN_SUPERVISION');
    foreach (['is_active', 'can_guide'] as $flag) {
        $d = $data; foreach ($d['staff'] as &$staff) $staff[$flag] = 0; unset($staff); $check($d, 'STAFF_NOT_QUALIFIED');
    }
    $d = $data; $d['skills'] = []; $check($d, 'STAFF_NOT_QUALIFIED');
    $d = $data; $d['staff'] = []; $check($d, 'STAFF_NOT_QUALIFIED');
    $d = $data; $d['plan']['visit_date'] = '2028-02-30'; $check($d, 'INVALID_VISIT_DATE');
    $d = $data; $d['context']['program_key'] = 'ochtend'; $d['context']['sector_key'] = 'voortgezetOnderbouw'; $check($d, 'INVALID_CONTEXT');
    $d = $data; $d['groupStaff'][] = ['roster_session_id' => $data['sessions'][0]['id'], 'roster_group_id' => 99999, 'staff_id' => $data['sessionStaff'][0]['staff_id']]; $check($d, 'INVALID_STAFF_RELATION');
    $d = $data; $d['sessions'][1]['start_time'] = $d['sessions'][0]['start_time']; $d['sessions'][1]['end_time'] = $d['sessions'][0]['end_time'];
    $d['sessionStaff'][1]['staff_id'] = $d['sessionStaff'][0]['staff_id']; $check($d, 'STAFF_OVERLAP');
    $d = $data; $d['links'][0]['supervision_kind'] = 'school'; $check($d, 'SCHOOL_NOT_ALLOWED');
    $assert(!$s['validator']->validateData($data, 999)->isValid(), 'Stale revision accepted.');
    foreach ([2 => 4, 3 => 6, 4 => 8, 5 => 10] as $modules => $limit) {
        $p = RosterLifecycleFixtures::validPlan($db->pdo, $s, 250, $limit, $modules);
        $d = $s['raw']->load($p['id']); $check($d);
        $extra = $d['groups'][0]; $extra['id'] = 99999; $extra['position'] = $limit + 1; $d['groups'][] = $extra; $check($d, 'GROUP_LIMIT');
    }
    $p = RosterLifecycleFixtures::validPlan($db->pdo, $s, 28, 2, 5);
    $full = $s['raw']->load($p['id']); $check($full);
    $d = $full; $d['context']['module_keys_json'] = json_encode(['Earth-Watch', 'Stop-de-Klimaat-Klok', 'Dynamische-Globe', 'Klimaat-Experience']);
    foreach ($d['links'] as &$link) foreach ($d['sessions'] as $session) if ($session['id'] === $link['roster_session_id'] && $session['module_key'] === 'Klimaat-Experience') $link['supervision_kind'] = 'school'; unset($link);
    $assert(!in_array('SCHOOL_NOT_ALLOWED', array_column($s['validator']->validateData($d, 1)->errors, 'code'), true), 'KE School is still forbidden at four modules.');
    $d = $full; $d['settings']['cook_staff_id'] = null; $check($d, 'COOK_REQUIRED');
    $d = $full; foreach ($d['staff'] as &$member) $member['can_cook'] = 0; unset($member); $check($d, 'COOK_REQUIRED');
    $d = $full; $oneGuide = $d['sessionStaff'][0]['staff_id']; foreach ($d['sessionStaff'] as &$row) $row['staff_id'] = $oneGuide; unset($row); $check($d, 'STAFF_MODULE_LIMIT'); $check($d, 'GUIDES_REQUIRED');
    $d = $full; foreach ($d['sessions'] as $session) if ($session['module_key'] === 'Voedsel-Innovatie') foreach ($d['sessionStaff'] as $assignment) if ($assignment['roster_session_id'] === $session['id']) $d['settings']['cook_staff_id'] = $assignment['staff_id']; $check($d, 'COOK_REQUIRED');
    echo "OK: raw relations, coverage, capacity, staffing, student counts and group bounds.\n";
} finally { $db->drop(); }

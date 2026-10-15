<?php

declare(strict_types=1);
require __DIR__ . '/Support/RosterLifecycleFixtures.php';

use GeoFort\Booking\FourModuleProgram;
use GeoFort\Services\Booking\Roster\RosterGroupCountResolver;
use GeoFort\Services\Dashboard\Roster\RosterPlanException;
use GeoFort\Services\Http\Api\Admin\DashboardRosterManualProgramSaveRequest;

$db = DisposableBookingMariaDb::create('custom_group_count');
try {
    RosterLifecycleFixtures::schema($db->pdo);
    $s = RosterLifecycleFixtures::services($db->pdo);
    $assert = RosterLifecycleFixtures::assert(...);
    $reject = static function (callable $action, string $code) use ($assert): void {
        try { $action(); } catch (RosterPlanException $e) {
            $assert($e->publicCode === $code, $e->publicCode . ' instead of ' . $code);
            return;
        }
        throw new RuntimeException('Expected ' . $code);
    };
    $resolver = new RosterGroupCountResolver();
    foreach ([40, 50] as $students) {
        $assert($resolver->resolve('primairOnderwijs', 'dag', $students) === 3, 'Legacy standard changed.');
        $assert($resolver->resolve('primairOnderwijs', 'dag', $students, FourModuleProgram::VERSION) === 3, 'Four-module standard changed.');
    }
    $modules = ['Earth-Watch', 'Klimaat-Experience', 'Voedsel-Innovatie', 'Dynamische-Globe'];
    foreach ([null, FourModuleProgram::VERSION] as $version) {
        $db->pdo->prepare("INSERT INTO aanvragen(status,schoolnaam,land,adres,postcode,plaats,school_telefoonnummer,contactpersoon_telefoonnummer,contactpersoon_voornaam,contactpersoon_achternaam,email,bezoekdatum,onderwijs_sector,programma,program_version,keuzemodule_key,aantal_leerlingen,aantal_begeleiders,voorwaarden_akkoord) VALUES ('Definitief','Groepen test','Nederland','Testweg 1','1234 AB','Testplaats','0100000000','0612345678','Test','Planner','test@example.test','2028-10-10','primairOnderwijs','dag',?,'Earth-Watch',40,4,1)")->execute([$version]);
        $bookingId = (int) $db->pdo->lastInsertId();
        $db->pdo->prepare("INSERT INTO aanvraag_onderwijs_selecties(aanvraag_id,sector_key,sector_label,level_key,level_label,level_position,group_key,group_label,group_position) VALUES (?,'primairOnderwijs','Primair onderwijs','regulier','Regulier',1,'groep8','Groep 8',1)")->execute([$bookingId]);
        $p = $s['service']->createManual('Groepen test', 'Testplaats', '2028-10-10', 'primairOnderwijs', 'dag', 40, 3, 1, $bookingId)['plan'];
        $request = DashboardRosterManualProgramSaveRequest::fromJson(json_encode([
            'planId' => $p['id'], 'expectedRevision' => $p['revision'], 'moduleKeys' => $modules, 'groupCount' => 2,
        ], JSON_THROW_ON_ERROR));
        $saved = $s['service']->saveManualProgram($request->planId, $request->expectedRevision, $request->moduleKeys, 1, $request->timeline, $request->groupCount);
        $assert(count($saved['groups']) === 2 && $saved['revision'] === 2, 'Custom group count not saved.');
        $assert($saved['bookingId'] === $bookingId && (int) $db->pdo->query('SELECT aantal_leerlingen FROM aanvragen WHERE id = ' . $bookingId)->fetchColumn() === 40, 'Source booking changed.');
        $raw = $s['raw']->load($p['id']);
        $assert($raw['context']['student_count'] === 40 && $raw['context']['program_version'] === $version, 'Context changed.');
        $reject(fn() => $s['service']->saveManualProgram($p['id'], 1, $modules, 1, groupCount: 3), 'REVISION_CONFLICT');
        $reject(fn() => $s['service']->saveManualProgram($p['id'], 2, $modules, 1, groupCount: 9), 'MANUAL_GROUP_CAPACITY_EXCEEDED');
        $reject(fn() => $s['service']->saveManualProgram($p['id'], 2, $modules, 1, groupCount: 0), 'INVALID_GROUP_COUNT');
        $again = $s['service']->saveManualProgram($p['id'], 2, $modules, 1, groupCount: 2);
        $assert($again['groups'] === $saved['groups'], 'Unchanged count replaced group identities.');
        $db->pdo->prepare("UPDATE roster_plans SET planning_mode = 'booking_locked' WHERE id = ?")->execute([$p['id']]);
        $reject(fn() => $s['service']->saveManualProgram($p['id'], 3, $modules, 1, groupCount: 3), 'MANUAL_PROGRAM_ONLY');
        $db->pdo->prepare("UPDATE roster_plans SET planning_mode = 'custom' WHERE id = ?")->execute([$p['id']]);
    }
    foreach ([0, 11, 2.5, '2', true, null] as $count) {
        $reject(fn() => DashboardRosterManualProgramSaveRequest::fromJson(json_encode(['planId'=>1,'expectedRevision'=>1,'moduleKeys'=>$modules,'groupCount'=>$count])), 'INVALID_GROUP_COUNT');
    }
    $valid = RosterLifecycleFixtures::validPlan($db->pdo, $s, 50, 2, 2, '2028-10-12');
    $raw = $s['raw']->load($valid['id']);
    foreach ([40, 49, 50, 51, 60] as $students) {
        $raw['context']['student_count'] = $students;
        $result = $s['validator']->validateData($raw, 1);
        $assert($result->isValid(), 'Group warning blocks validity.');
        $assert(in_array('GROUP_SIZE_ADVICE', array_column($result->warnings, 'code'), true) === ($students > 50), 'Wrong 25-pupil warning boundary.');
    }
    $reject(fn() => $s['service']->saveManualProgram($valid['id'], 1, ['Earth-Watch', 'Stop-de-Klimaat-Klok'], 1, groupCount: 3), 'MANUAL_PROGRAM_HAS_SESSIONS');
    $assert($s['raw']->load($valid['id'])['groups'] === $raw['groups'], 'Failed resize changed groups.');
    $db->pdo->prepare('UPDATE roster_manual_plan_context SET student_count = 60 WHERE roster_plan_id = ?')->execute([$valid['id']]);
    $final = $s['lifecycle']->finalize($valid['id'], 1, 1);
    $assert($final['status'] === 'published', 'Warning prevented finalization.');
    $reject(fn() => $s['service']->saveManualProgram($valid['id'], 2, ['Earth-Watch', 'Stop-de-Klimaat-Klok'], 1, groupCount: 3), 'MANUAL_PROGRAM_LOCKED');
    echo "OK: standard counts, custom resizing, revision/capacity/session guards, 25-pupil warnings and finalization.\n";
} finally {
    $db->drop();
}

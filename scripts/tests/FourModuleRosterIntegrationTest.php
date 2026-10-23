<?php
declare(strict_types=1);
require __DIR__ . '/Support/RosterLifecycleFixtures.php';

use GeoFort\Booking\FourModuleProgram;
use GeoFort\Services\Dashboard\Roster\{RosterAutoGenerator, RosterStaffOptimizer, RosterSchoolProjection, RosterSchoolPdfWriter, RosterXlsxWriter};

$db = DisposableBookingMariaDb::create('four_roster');
$checks = 0;
$assert = static function (bool $ok, string $message) use (&$checks): void { if (!$ok) throw new RuntimeException($message); $checks++; };
try {
    $pdo = $db->pdo;
    RosterLifecycleFixtures::schema($pdo);
    $s = RosterLifecycleFixtures::services($pdo);
    $generator = new RosterAutoGenerator($pdo, $s['bookings'], $s['plans'], $s['sessions'], $s['config'], $s['templates'], $s['staff'], new RosterStaffOptimizer($s['staff']));
    $staffIds = [];
    $modules = ['Dynamische-Globe','Voedsel-Innovatie','Klimparcours','Klimaat-Experience','Earth-Watch','Minecraft-Programmeren','Minecraft-Klimaatspeurtocht','Zandtafel','Rising-Risk','Expedition-Earth','Dynamische-Globe-Bios'];
    for ($i = 0; $i < 8; $i++) {
        $pdo->prepare('INSERT INTO roster_staff_members(display_name,is_active,can_guide,can_cook) VALUES (?,1,1,0)')->execute(['Testdocent ' . ($i+1)]);
        $staffIds[] = $sid = (int) $pdo->lastInsertId();
        foreach ($modules as $rank => $module) $pdo->prepare('INSERT INTO roster_staff_module_preferences(staff_id,module_key,preference_rank) VALUES (?,?,?)')->execute([$sid,$module,$rank+1]);
    }
    $pdo->exec("INSERT INTO roster_staff_members(display_name,is_active,can_guide,can_cook) VALUES ('Testkok',1,0,1)");
    $cook = (int) $pdo->lastInsertId();
    $views = [];
    foreach ([['primairOnderwijs','Klimaat-Experience',152,8],['voortgezetOnderbouw','Minecraft-Programmeren',76,4],['primairOnderwijs','Earth-Watch',160,8]] as $index => [$sector,$choice,$students,$expectedGroups]) {
        $date = '2027-02-' . (15+$index);
        $pdo->prepare("INSERT INTO aanvragen(status,schoolnaam,land,adres,postcode,plaats,school_telefoonnummer,contactpersoon_telefoonnummer,contactpersoon_voornaam,contactpersoon_achternaam,email,bezoekdatum,onderwijs_sector,programma,program_version,keuzemodule_key,aantal_leerlingen,aantal_begeleiders,voorwaarden_akkoord) VALUES ('Definitief',?,'Nederland','Testweg 1','1234 AB','Testplaats','0100000000','0612345678','Test','Planner','test@example.test',?,?,'dag',?,?,?,10,1)")->execute(['Vier modules school ' . ($index+1),$date,$sector,FourModuleProgram::VERSION,$choice,$students]);
        $bookingId = (int) $pdo->lastInsertId();
        [$level,$group] = $sector === 'primairOnderwijs' ? ['regulier','groep8'] : ['havo','havo1'];
        $pdo->prepare('INSERT INTO aanvraag_onderwijs_selecties(aanvraag_id,sector_key,sector_label,level_key,level_label,level_position,group_key,group_label,group_position) VALUES (?,?,?,?,?,1,?,?,1)')->execute([$bookingId,$sector,$sector,$level,$level,$group,$group]);
        $manual = $s['service']->createManual('Versiebehoud', 'Testplaats', $date, $sector, 'dag', $students, $expectedGroups, 1, $bookingId)['plan'];
        $assert($manual['education']['programVersion'] === FourModuleProgram::VERSION, 'Direct linked custom creation lost version');
        $manualContext = $s['plans']->manualContextForPlan($manual['id']);
        $assert(substr($manualContext['day_end_time'], 0, 5) === '14:00', 'Direct linked custom creation lost end time');
        $pdo->prepare('DELETE FROM roster_plans WHERE id=?')->execute([$manual['id']]);
        $plan = $s['service']->createFromBooking($bookingId,1)['plan'];
        $assert(count($plan['groups']) === $expectedGroups, 'Grouping must use 19 with the eight-group exception');
        $assert(count($plan['planning']['modules']) === 4, 'Wrong number of modules');
        $assert($plan['planning']['rounds'][3]['type'] === 'lunch' && end($plan['planning']['rounds'])['endTime'] === '14:00','Wrong timeline');
        $rounds = $plan['planning']['generation']['defaultRounds'];
        // Model a real catalog with qualified specialists rather than eight interchangeable all-rounders.
        $keys = array_column($plan['planning']['modules'], 'key');
        foreach ($staffIds as $position => $sid) {
            $pdo->prepare('DELETE FROM roster_staff_module_preferences WHERE staff_id=?')->execute([$sid]);
            $pdo->prepare('INSERT INTO roster_staff_module_preferences(staff_id,module_key,preference_rank) VALUES (?,?,1)')->execute([$sid,$keys[$position % 4]]);
        }
        echo "Checking {$sector}: {$expectedGroups} groups\n";
        $preview = $generator->preview($plan['id'],$rounds,$staffIds,'with_staff',true,$cook);
        $assert($preview['canApply'], 'Generation blocked: '.json_encode($preview['hardIssues'] ?? $preview));
        foreach ($preview['sessions'] as $session) {
            $assert(!$session['schoolFallback'], 'School fallback used');
            $assert(count($session['assignedStaffIds']) === $s['config']->requiredGeoFortGuidesForSession($session['moduleKey'], count($session['groupIds']), false, FourModuleProgram::VERSION), 'One guide per group missing');
        }
        $generator->apply($plan['id'],1,$rounds,false,1,$staffIds,'with_staff',true,$cook);
        $report = $s['validator']->validate($plan['id'],2)->toArray();
        $assert($report['valid'], 'Generated plan invalid: '.json_encode($report));
        // Ambiguous School/staff assignments and shared guides remain invalid.
        $data = $s['raw']->load($plan['id']);
        $bad = $data; $bad['links'][0]['supervision_kind'] = 'school';
        $assert(!$s['validator']->validateData($bad,2)->toArray()['valid'], 'School bypass accepted');
        $bad = $data; $bad['booking']['program_version'] = 'unknown-version';
        $assert(!$s['validator']->validateData($bad,2)->toArray()['valid'], 'Unknown program version accepted');
        $bySession = [];
        foreach ($data['groupStaff'] as $assignment) $bySession[$assignment['roster_session_id']][] = $assignment;
        foreach ($bySession as $sid => $assignments) if (count($assignments) === 2 && $s['config']->requiredGeoFortGuidesForSession(array_values(array_filter($data['sessions'], static fn($row) => $row['id'] === $sid))[0]['module_key'], 2, false, FourModuleProgram::VERSION) === 2) {
            $bad = $data;
            foreach ($bad['groupStaff'] as &$assignment) if ($assignment['roster_session_id'] === $sid) $assignment['staff_id'] = $assignments[0]['staff_id'];
            unset($assignment);
            $codes = array_column($s['validator']->validateData($bad,2)->toArray()['errors'],'code');
            $assert(in_array('GROUP_GUIDES_REQUIRED',$codes,true), 'One teacher on two four-module groups accepted');
            break;
        }
        $s['lifecycle']->finalize($plan['id'],2,1);
        $snapshot = $s['snapshots']->state($plan['id'])['current']['content'];
        $assert($snapshot['presentation']['education']['programVersion'] === FourModuleProgram::VERSION,'Final snapshot lost version');
        $projection = (new RosterSchoolProjection())->fromSnapshot($snapshot);
        $assert(str_contains(json_encode($projection),'vier modules'),'School document lost program label');
        $views[] = $snapshot['presentation'];
        $output = getenv('FOUR_MODULE_OUTPUT');
        if ($output) {
            if (!is_dir($output)) mkdir($output,0777,true);
            $fixture = $plan;
            $fixture['planning']['selectedStaffIds'] = $staffIds;
            $fixture['planning']['staffingSettings'] = ['staffingMode'=>'with_staff','preferGeoFortKe'=>true,'cookStaffId'=>$cook];
            file_put_contents($output . '/wizard-' . ($index+1) . '.json',json_encode(['plan'=>$fixture,'preview'=>$preview],JSON_THROW_ON_ERROR));
            file_put_contents($output . '/school-' . ($index+1) . '.pdf',(new RosterSchoolPdfWriter())->render($projection));
            (new RosterXlsxWriter())->write($output . '/school-' . ($index+1) . '.xlsx',$snapshot['presentation']);
        }
        $s['lifecycle']->reopen($plan['id'],3,1);
        $assert($s['service']->getPlan($plan['id'])['education']['programVersion'] === FourModuleProgram::VERSION,'Reopen lost version');
        // Existing policy requires rebuilding lessons when converting to custom.
        $pdo->prepare('DELETE FROM roster_sessions WHERE roster_plan_id=?')->execute([$plan['id']]);
        $custom = $s['service']->convertBookingPlanToCustom($plan['id'],4,1);
        $assert($custom['education']['programVersion'] === FourModuleProgram::VERSION,'Custom conversion lost version');
        $assert($custom['planning']['rounds'][3]['type'] === 'lunch','Custom conversion lost lunch');
        $generator->apply($plan['id'],5,$rounds,false,1,$staffIds,'with_staff',true,$cook);
        $assert($s['validator']->validate($plan['id'],6)->toArray()['valid'],'Custom regeneration changed valid coverage');
    }
    foreach ($staffIds as $sid) foreach ($modules as $rank => $module) {
        $pdo->prepare('INSERT IGNORE INTO roster_staff_module_preferences(staff_id,module_key,preference_rank) VALUES (?,?,?)')->execute([$sid,$module,$rank+1]);
    }
    $sharedData = $s['raw']->load((int) $pdo->query('SELECT id FROM roster_plans ORDER BY id LIMIT 1')->fetchColumn());
    foreach (['Klimaat-Experience','Klimparcours'] as $sharedModule) {
        $assert($s['config']->requiredGeoFortGuidesForSession($sharedModule, 2, false, FourModuleProgram::VERSION) === 1, 'Two groups must need one guide for '.$sharedModule);
        $candidate = $sharedData;
        $pairs = [];
        foreach ($candidate['sessions'] as $session) if ($session['module_key'] === $sharedModule) $pairs[$session['start_time']][] = $session['id'];
        $pair = array_values($pairs)[0];
        $keep = $pair[0];
        if (count($pair) === 2) {
            $remove = $pair[1];
            $candidate['sessions'] = array_values(array_filter($candidate['sessions'], static fn($row) => $row['id'] !== $remove));
            foreach ($candidate['links'] as &$link) if ($link['roster_session_id'] === $remove) $link['roster_session_id'] = $keep;
            unset($link);
            foreach ($candidate['groupStaff'] as &$link) if ($link['roster_session_id'] === $remove) $link['roster_session_id'] = $keep;
            unset($link);
            $candidate['sessionStaff'] = array_values(array_filter($candidate['sessionStaff'], static fn($row) => $row['roster_session_id'] !== $remove));
        }
        $guide = array_values(array_filter($candidate['sessionStaff'], static fn($row) => $row['roster_session_id'] === $keep))[0]['staff_id'];
        foreach ($candidate['groupStaff'] as &$link) if ($link['roster_session_id'] === $keep) $link['staff_id'] = $guide;
        unset($link);
        $candidate['sessionStaff'] = array_values(array_filter($candidate['sessionStaff'], static fn($row) => $row['roster_session_id'] !== $keep || $row['staff_id'] === $guide));
        $assert(count(array_filter($candidate['links'], static fn($row) => $row['roster_session_id'] === $keep)) === 2, 'Two-group scenario not exercised');
        $report = $s['validator']->validateData($candidate,6)->toArray();
        $assert($report['valid'] && $report['warnings'] === [], 'Shared guide should pass without warning: '.json_encode($report));
    }
    $testPlanId = (int) $pdo->query('SELECT id FROM roster_plans ORDER BY id LIMIT 1')->fetchColumn();
    $testData = $s['raw']->load($testPlanId);
    $revision = (int) $testData['plan']['revision'];
    $testedModules = [];
    foreach ($testData['sessions'] as $session) {
        $module = $session['module_key'];
        if (!in_array($module, ['Klimaat-Experience', 'Klimparcours'], true) || isset($testedModules[$module])) continue;
        $link = array_values(array_filter($testData['links'], static fn($l) => $l['roster_session_id'] === $session['id']))[0];
        $saved = $s['sessionService']->saveGroupStaffAssignment($testPlanId, (int) $session['id'], $revision, (int) $link['roster_group_id'], [], 1, 'school');
        $revision = $saved['revision'];
        $assert(in_array('SCHOOL_SUPERVISION_OVERRIDE', array_column($saved['warnings'], 'code'), true), 'Manual override warning missing');
        $testedModules[$module] = true;
    }
    $assert(count($testedModules) === 2, 'Both KE and Speurtuin overrides must be exercised');
    $report = $s['validator']->validate($testPlanId, $revision)->toArray();
    $assert($report['valid'], 'Explicit school override should be valid: '.json_encode($report));
    $assert(count(array_filter($report['warnings'], static fn($w) => $w['code'] === 'SCHOOL_SUPERVISION_OVERRIDE')) === 2, 'Validation must retain both warnings');
    $s['lifecycle']->finalize($testPlanId, $revision, 1);
    $assert($s['service']->getPlan($testPlanId)['status'] === 'published', 'School override cannot be finalized');
    $finalContent = $s['snapshots']->state($testPlanId)['current']['content'];
    $assert(count(array_filter($finalContent['acceptedWarnings'], static fn($w) => $w['code'] === 'SCHOOL_SUPERVISION_OVERRIDE')) === 2, 'Final snapshot lost school override warnings');
    foreach (['Zandtafel','Rising-Risk','Expedition-Earth','Dynamische-Globe','Voedsel-Innovatie'] as $module) {
        $assert(!$s['config']->schoolSupervisionAllowedForModuleCount($module,4,FourModuleProgram::VERSION), 'Mandatory supervision relaxed: '.$module);
    }
    foreach ($staffIds as $sid) foreach ($keys as $rank => $key) {
        $pdo->prepare('INSERT IGNORE INTO roster_staff_module_preferences(staff_id,module_key,preference_rank) VALUES (?,?,?)')->execute([$sid,$key,$rank+1]);
    }
    $started = microtime(true);
    $allRound = $generator->preview($plan['id'],$rounds,$staffIds,'with_staff',true,$cook);
    $assert($allRound['canApply'], 'Eight all-round teachers must still produce a valid proposal');
    echo 'All-round teacher search: ' . round(microtime(true)-$started,2) . " seconds\n";
    echo "PASS: {$checks} four-module generation, staffing, validation, publication and document checks\n";
} finally { $db->drop(); }

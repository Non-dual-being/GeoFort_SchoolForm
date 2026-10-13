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
    foreach ([['primairOnderwijs','Klimaat-Experience',38,2],['voortgezetOnderbouw','Minecraft-Programmeren',76,4],['primairOnderwijs','Earth-Watch',160,8]] as $index => [$sector,$choice,$students,$expectedGroups]) {
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
            $assert(!$session['schoolFallback'] && !$session['schoolSupervisionAllowed'], 'School fallback used');
            $assert(count($session['assignedStaffIds']) === count($session['groupIds']), 'One guide per group missing');
        }
        $generator->apply($plan['id'],1,$rounds,false,1,$staffIds,'with_staff',true,$cook);
        $report = $s['validator']->validate($plan['id'],2)->toArray();
        $assert($report['valid'], 'Generated plan invalid: '.json_encode($report));
        // A shared guide and School must be rejected even if the UI is bypassed.
        $data = $s['raw']->load($plan['id']);
        $bad = $data; $bad['links'][0]['supervision_kind'] = 'school';
        $assert(!$s['validator']->validateData($bad,2)->toArray()['valid'], 'School bypass accepted');
        $bad = $data; $bad['booking']['program_version'] = 'unknown-version';
        $assert(!$s['validator']->validateData($bad,2)->toArray()['valid'], 'Unknown program version accepted');
        $bySession = [];
        foreach ($data['groupStaff'] as $assignment) $bySession[$assignment['roster_session_id']][] = $assignment;
        foreach ($bySession as $sid => $assignments) if (count($assignments) === 2) {
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
    foreach ($staffIds as $sid) foreach ($keys as $rank => $key) {
        $pdo->prepare('INSERT IGNORE INTO roster_staff_module_preferences(staff_id,module_key,preference_rank) VALUES (?,?,?)')->execute([$sid,$key,$rank+1]);
    }
    $started = microtime(true);
    $allRound = $generator->preview($plan['id'],$rounds,$staffIds,'with_staff',true,$cook);
    $assert($allRound['canApply'], 'Eight all-round teachers must still produce a valid proposal');
    echo 'All-round teacher search: ' . round(microtime(true)-$started,2) . " seconds\n";
    echo "PASS: {$checks} four-module generation, staffing, validation, publication and document checks\n";
} finally { $db->drop(); }

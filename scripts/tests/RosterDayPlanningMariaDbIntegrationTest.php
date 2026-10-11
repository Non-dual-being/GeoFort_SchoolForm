<?php

declare(strict_types=1);

require __DIR__ . '/Support/RosterLifecycleFixtures.php';

use GeoFort\Services\Dashboard\Roster\RosterAutoGenerator;
use GeoFort\Services\Dashboard\Roster\RosterDayPlanningService;
use GeoFort\Services\Dashboard\Roster\RosterPlanException;
use GeoFort\Services\Http\Api\Admin\DashboardRosterDayPreviewRequest;

$assert = RosterLifecycleFixtures::assert(...);
foreach ([['planIds'=>'1,2','revisions'=>'1,3'], ['planIds'=>'4','revisions'=>'2']] as $query) {
    $assert(count(DashboardRosterDayPreviewRequest::fromQuery($query)->expectedRevisions) === substr_count($query['planIds'], ',') + 1, 'Valid query rejected');
}
foreach ([
    ['planIds'=>'1,1','revisions'=>'1,1'], ['planIds'=>'1,2','revisions'=>'1'],
    ['planIds'=>'0,2','revisions'=>'1,1'], ['planIds'=>'01,2','revisions'=>'1,1'],
    ['planIds'=>['1','2'],'revisions'=>'1,1'], ['planIds'=>'1,2,3','revisions'=>'1,1,1'],
    ['planIds'=>'1,2','revisions'=>'1,-2'], ['planIds'=>'999999999999999999999999','revisions'=>'1'],
] as $query) {
    try { DashboardRosterDayPreviewRequest::fromQuery($query); throw new LogicException('Invalid day request accepted'); }
    catch (RosterPlanException $e) { $assert($e->publicCode === 'INVALID_DAY_PLANS', 'Wrong request error'); }
}

$db = DisposableBookingMariaDb::create('roster_day_wizard');
try {
    RosterLifecycleFixtures::schema($db->pdo);
    $s = RosterLifecycleFixtures::services($db->pdo);
    $generator = new RosterAutoGenerator($db->pdo, $s['bookings'], $s['plans'], $s['sessions'], $s['config'], $s['templates']);
    $day = new RosterDayPlanningService($db->pdo, $s['raw'], $s['snapshots'], $s['validator'], $generator, $s['config']);
    $make = function (string $name, int $groups, string $date='2028-10-10') use ($s): array {
        $p = $s['service']->createManual($name, 'Testplaats', $date, 'primairOnderwijs', 'dag', 40, $groups, 1)['plan'];
        $s['plans']->saveManualModuleKeys($p['id'], ['Earth-Watch', 'Stop-de-Klimaat-Klok']);
        $s['plans']->saveManualRounds($p['id'], $s['templates']->defaultTimeline('dag', 2));
        return $s['service']->getPlan($p['id']);
    };
    $a = $make('School 1 · drie groepen', 3);
    $b = $make('School 2 · één groep', 1);
    $ids = [$a['id']=>1, $b['id']=>1];
    $hash = function () use ($db): string {
        $all = [];
        foreach ($db->pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table) {
            $rows = array_map('serialize', $db->pdo->query('SELECT * FROM `'.$table.'`')->fetchAll(PDO::FETCH_NUM));
            sort($rows, SORT_STRING); $all[$table] = $rows;
        }
        return hash('sha256', serialize($all));
    };
    $before = $hash();
    $result = $day->preview($ids);
    $assert($result['lessons']['status'] === 'candidate_found', 'Joint lesson search must find the reversed second-school module order');
    $assert($result['lessons']['checkedCombinations'] > 1, 'Search must go beyond the conflicting initial combination');
    $assert(!$result['savedControl']['canFinalize'], 'Lesson feasibility must not imply staffing or saved coverage is ready');
    $assert(count($result['lessons']['schools']) === 2 && $result['lessons']['schools'][1]['isProposal'], 'Preview must identify proposals and both schools');
    $assert($hash() === $before && !$db->pdo->inTransaction(), 'Preview changed persisted data or left transaction open');

    $other = $make('Andere datum', 1, '2028-10-11');
    foreach ([[$a['id']=>1,$other['id']=>1], [$a['id']=>2,$b['id']=>1]] as $bad) {
        try { $day->preview($bad); throw new LogicException('Mismatched day/revision accepted'); }
        catch (RosterPlanException $e) { $assert(in_array($e->publicCode,['DAY_DATE_MISMATCH','STALE_REVISION'],true), 'Wrong stale/date rejection'); }
        $assert(!$db->pdo->inTransaction(), 'Rejected request left transaction open');
    }
    $final = RosterLifecycleFixtures::validPlan($db->pdo, $s, 40, 1, 2);
    $s['lifecycle']->finalize($final['id'],1,1);
    $frozen = $s['snapshots']->state($final['id'])['current']['content_json'];
    $before = $hash();
    $blocked = $day->preview($ids);
    $assert($blocked['lessons']['status'] === 'no_candidate_found', 'Final school occupancy must bind both proposals');
    $assert(in_array('MODULE_CAPACITY',array_column($blocked['lessons']['conflicts'],'code'),true), 'Missing actionable joint capacity conflict');
    $assert($hash() === $before, 'Rejected search changed persisted data');
    $db->pdo->prepare('DELETE FROM roster_sessions WHERE roster_plan_id=?')->execute([$final['id']]);
    $assert($day->preview($ids)['lessons']['status'] === 'no_candidate_found', 'Final occupancy must use the immutable snapshot');
    $assert($s['snapshots']->state($final['id'])['current']['content_json'] === $frozen, 'Final snapshot changed');

    $broken = $make('Onvolledige lesindeling', 1, '2028-10-12');
    $round = $s['templates']->defaultRounds('dag',2)[0];
    $sid = $s['sessions']->insertActivity($broken['id'],'Earth-Watch',$round['startTime'],$round['endTime'],null,1);
    $s['sessions']->replaceGroups($sid,[$broken['groups'][0]['id']]);
    $brokenResult = $day->preview([$broken['id']=>1]);
    $assert($brokenResult['lessons']['status'] === 'saved_lessons_invalid' && in_array('MISSING_MODULE',array_column($brokenResult['lessons']['errors'],'code'),true), 'Saved incomplete lessons must not become a green preview');

    $vi = $make('VI-programma', 2, '2028-10-13');
    $s['plans']->saveManualModuleKeys($vi['id'], ['Earth-Watch','Voedsel-Innovatie']);
    $viPreview = $day->preview([$vi['id']=>1]);
    $assert($viPreview['lessons']['status'] === 'candidate_found' && !$viPreview['savedControl']['canFinalize'], 'Lesson-only VI proposal must work without inventing a cook or guides');
    $viSecond = $make('School 2 VI later', 2, '2028-10-13');
    $s['plans']->saveManualModuleKeys($viSecond['id'], ['Earth-Watch','Voedsel-Innovatie']);
    $before = $hash();
    try { $day->applyLessons([$vi['id']=>1,$viSecond['id']=>1],1); throw new LogicException('Empty first school accepted'); }
    catch (RosterPlanException $e) { $assert($e->publicCode === 'FIRST_SCHOOL_REQUIRED', 'Wrong first school gate'); }
    $assert($hash() === $before, 'Rejected first-school gate mutated data');
    $day->applyLessons([$vi['id']=>1],1);
    $fixedFirst = serialize($s['raw']->load($vi['id']));
    $late = $day->preview([$vi['id']=>2,$viSecond['id']=>1]);
    $assert($late['lessons']['status'] === 'candidate_found', 'Second school must fit around fixed first school with later VI');
    $viSlots = array_values(array_filter($late['lessons']['schools'][1]['sessions'], fn(array $session): bool => $session['moduleKey'] === 'Voedsel-Innovatie'));
    $assert($viSlots[0]['startTime'] === $s['templates']->defaultRounds('dag',2)[1]['startTime'], 'VI early preference incorrectly treated as hard rule');
    $day->applyLessons([$vi['id']=>2,$viSecond['id']=>1],1);
    $assert(serialize($s['raw']->load($vi['id'])) === $fixedFirst, 'Saving second school changed first school');
    $assert($day->preview([$vi['id']=>2,$viSecond['id']=>2])['lessons']['status'] === 'candidate_found', 'Saved joint lessons violate hard rules');
    $before = $hash();
    foreach ([[$vi['id']=>1,$viSecond['id']=>2],[$vi['id']=>2,$viSecond['id']=>2]] as $reapply) {
        try { $day->applyLessons($reapply,1); throw new LogicException('Stale revision or existing lessons overwritten'); }
        catch (RosterPlanException $e) { $assert(in_array($e->publicCode,['STALE_REVISION','LESSONS_ALREADY_EXIST'],true), 'Wrong apply guard'); }
    }
    $assert($hash() === $before, 'Rejected apply changed data');
    $tooBig = $make('First has all capacity',4,'2028-10-17');
    $over = $make('No remaining capacity',1,'2028-10-17');
    $day->applyLessons([$tooBig['id']=>1],1);
    $before = $hash();
    try { $day->applyLessons([$tooBig['id']=>2,$over['id']=>1],1); throw new LogicException('Hard capacity violation accepted'); }
    catch (RosterPlanException $e) { $assert($e->publicCode === 'DAY_LESSONS_CONFLICT', 'Wrong capacity gate'); }
    $assert($hash() === $before, 'Failed capacity gate persisted changes');

    // Active-day eligibility belongs in SQL, before search or pagination.
    $insert = $db->pdo->prepare("INSERT INTO aanvragen(status,schoolnaam,land,adres,postcode,plaats,school_telefoonnummer,contactpersoon_telefoonnummer,contactpersoon_voornaam,contactpersoon_achternaam,email,bezoekdatum,onderwijs_sector,programma,aantal_leerlingen) VALUES (?,?,'NL','Test','1234AB','Testplaats','0612345678','0612345678','Test','Contact','test@example.test',?,'primairOnderwijs','dag',40)");
    $bookingIds = [];
    foreach ([['In optie','Pair A','2028-11-01'],['Definitief','Pair B','2028-11-01'],['Afgewezen','Rejected pair','2028-11-01'],['In optie','Single','2028-11-02'],['Afgewezen','Rejected single','2028-11-02'],['In optie','Triple A','2028-11-03'],['In optie','Triple B','2028-11-03'],['Definitief','Triple C','2028-11-03']] as [$status,$name,$date]) {
        $insert->execute([$status,$name,$date]); $bookingIds[] = (int) $db->pdo->lastInsertId();
    }
    for ($i=0; $i<5; $i++) $insert->execute(['In optie','Too many '.$i,'2028-11-04']);
    $parser = new \GeoFort\Services\Dashboard\Booking\DashboardBookingFilterParser();
    $sql = new \GeoFort\Services\Sql\DashboardBookingSqlService($db->pdo);
    $filter = $parser->parse(['twoSchoolDaysOnly'=>'1']);
    $assert($sql->countBookings($filter) === 5, 'Only dates with two to four active bookings should be eligible');
    $rows = $sql->findBookings($filter,1,0);
    $assert(count($rows) === 1 && in_array($rows[0]['bezoekdatum'], ['2028-11-01','2028-11-03'], true), 'Pair filter must precede pagination');
    $assert($sql->countBookings($parser->parse(['twoSchoolDaysOnly'=>'1','search'=>'Pair A'])) === 1, 'Search must not change eligibility');
    $linked = $s['service']->createManual('Existing single school','Testplaats','2028-11-01','primairOnderwijs','dag',40,2,1,$bookingIds[0])['plan'];
    $reused = $s['service']->createFromBooking($bookingIds[0],1);
    $assert(!$reused['created'] && $reused['plan']['id'] === $linked['id'], 'Two-school selection duplicated existing single-school roster');
    $db->pdo->prepare('UPDATE aanvragen SET aantal_leerlingen=NULL WHERE id=?')->execute([$bookingIds[0]]);
    $assert($s['service']->createFromBooking($bookingIds[0],1)['plan']['id'] === $linked['id'], 'Existing custom roster reuse must not depend on booking creation advice');
    $assert($sql->countBookings($filter) === 5, 'Existing rosters must remain eligible for two-school selection');
    // The real wizard generator must respect either saved member, not only school 1.
    foreach ([false, true] as $reverse) {
        foreach ([false, true] as $withVi) {
            $date = '2029-01-' . str_pad((string)(8 + (int)$reverse * 2 + (int)$withVi), 2, '0', STR_PAD_LEFT);
            $one = $make('Pair first member', $withVi ? 2 : 3, $date);
            $two = $make('Pair second member', $withVi ? 2 : 1, $date);
            if ($withVi) foreach ([$one, $two] as $member) $s['plans']->saveManualModuleKeys($member['id'], ['Earth-Watch', 'Voedsel-Innovatie']);
            $db->pdo->beginTransaction();
            (new \GeoFort\Services\Sql\RosterPairSqlRepository($db->pdo))->create([$one['id'],$two['id']], $date, 'lessons_first', 1);
            $db->pdo->commit();
            [$first, $next] = $reverse ? [$two, $one] : [$one, $two];
            $rounds = $s['templates']->defaultRounds('dag', 2);
            $generator->apply($first['id'], 1, $rounds, false, 1, [], 'lesson_only');
            $fixed = $s['raw']->load($first['id']);
            $proposal = $generator->preview($next['id'], $rounds, [], 'lesson_only');
            $assert(!in_array('MODULE_CAPACITY', array_column($proposal['hardIssues'],'code'), true), 'Wizard failed to fit around saved companion');
            if ($withVi) $assert(in_array('VI_LATER_FOR_PAIRED_SCHOOL',array_column($proposal['warnings'],'code'),true), 'Later VI needs a visible warning');
            $generator->apply($next['id'], 1, $rounds, false, 1, [], 'lesson_only');
            $assert($fixed === $s['raw']->load($first['id']), 'Wizard changed the fixed companion');
            $report = $s['validator']->validatePlans([$one['id']=>2,$two['id']=>2]);
            $assert(!in_array('MODULE_CAPACITY',array_column($report['day']['errors'],'code'),true), 'Saved pair violates lesson capacity');
        }
    }
    // Five-module stress matrix: both starts, exact ten-group boundary and partial time overlap.
    $fiveModules = ['Klimaat-Experience','Voedsel-Innovatie','Dynamische-Globe','Earth-Watch','Stop-de-Klimaat-Klok'];
    $caseNumber = 0;
    foreach ([[3,3,0],[5,5,0],[6,4,0],[6,4,10],[1,1,0]] as [$ga,$gb,$shift]) {
        foreach ([false,true] as $reverse) {
            $date = (new DateTimeImmutable('2029-02-05'))->modify('+'.($caseNumber++ * 7).' days')->format('Y-m-d');
            $members = [];
            foreach ([$ga,$gb] as $index=>$groups) {
                $member = $s['service']->createManual('Five rounds school '.($index+1),'Testplaats',$date,'primairOnderwijs','dag',16*$groups,$groups,1)['plan'];
                $s['plans']->saveManualModuleKeys($member['id'],$fiveModules);
                $s['plans']->saveManualRounds($member['id'],$s['templates']->defaultTimeline('dag',5));
                $members[] = $s['service']->getPlan($member['id']);
            }
            $db->pdo->beginTransaction();
            (new \GeoFort\Services\Sql\RosterPairSqlRepository($db->pdo))->create(array_column($members,'id'),$date,'lessons_first',1);
            $db->pdo->commit();
            [$first,$next] = $reverse ? array_reverse($members) : $members;
            $rounds = $s['templates']->defaultRounds('dag',5);
            $assert(count($rounds)===5,'Stress test must have five real lesson rounds');
            $generator->apply($first['id'],1,$rounds,false,1,[],'lesson_only');
            $fixed = $s['raw']->load($first['id']);
            $nextRounds = array_map(static function(array $round) use($shift):array {
                foreach(['startTime','endTime'] as $key) $round[$key]=(new DateTimeImmutable($round[$key]))->modify('+'.$shift.' minutes')->format('H:i');
                return $round;
            },$rounds);
            $beforePreview = $hash();
            $proposal = $generator->preview($next['id'],$nextRounds,[],'lesson_only');
            $assert($hash()===$beforePreview,'Five-round preview mutated data');
            $assert(count($proposal['rounds'])===5,'Generator reduced five-round case');
            foreach($next['groups'] as $group) {
                $coverage=[];
                foreach($proposal['sessions'] as $session) if(in_array($group['id'],$session['groupIds'],true)) $coverage[]=$session['moduleKey'];
                sort($coverage);$expected=$fiveModules;sort($expected);
                $assert($coverage===$expected,'Five-round proposal omitted or duplicated a requested group/module');
            }
            $capacityConflict=in_array('MODULE_CAPACITY',array_column($proposal['hardIssues'],'code'),true);
            if($shift===0) {
                $assert(!$capacityConflict,'Aligned five-round pair must fit, including 5+5 and 6+4');
                $jointPreview=$day->preview([$first['id']=>2,$next['id']=>1]);
                $assert($jointPreview['lessons']['status']==='candidate_found','Day control disagrees with wizard on five-round fit');
            } else {
                $assert($capacityConflict,'Partial-overlap stress fixture must exercise the conflict fallback');
                foreach(array_filter($proposal['hardIssues'],static fn(array $e):bool=>$e['code']==='MODULE_CAPACITY') as $issue) {
                    $assert($issue['startTime']!==null && $issue['endTime']!==null && $issue['groupLabels']!==[], 'Conflict must identify actual times and affected groups');
                }
            }
            if($capacityConflict) $assert(!$proposal['canFinalize'],'Conflicting five-round proposal falsely marked finalizable');
            // Saving as draft is allowed by the current generator; validate the actual saved joint occupancy independently.
            if ($capacityConflict) {
                foreach ([null, str_repeat('0',64)] as $acceptance) {
                    try { $generator->apply($next['id'],1,$nextRounds,false,1,[],'lesson_only',true,null,[],null,$acceptance); throw new RuntimeException('Missing/stale acceptance allowed'); }
                    catch (RosterPlanException $e) { $assert($e->publicCode==='LESSON_CONFLICT_ACCEPTANCE_REQUIRED','Wrong acceptance error'); }
                }
                $assert($hash()===$beforePreview,'Rejected acceptance changed data');
            }
            $generator->apply($next['id'],1,$nextRounds,false,1,[],'lesson_only',true,null,[],null,$proposal['lessonConflictSignature']);
            $assert($fixed===$s['raw']->load($first['id']),'Five-round generation changed the first school');
            $report=$s['validator']->validatePlans([$first['id']=>2,$next['id']=>2]);
            $savedConflict=in_array('MODULE_CAPACITY',array_column($report['day']['errors'],'code'),true);
            $assert($capacityConflict===$savedConflict,'Five-round preview hid or invented a saved capacity conflict');
            if($savedConflict) $assert(!$report['canFinalize'],'Conflicting saved pair became finalizable');
            echo 'FIVE_ROUNDS '.$ga.'+'.$gb.' start='.($reverse?'school2':'school1').' shift='.$shift.' result='.($capacityConflict?'CONFLICT_VISIBLE':'CAPACITY_OK')."\n";
        }
    }
    echo "PASS: wizard request validation, joint alternative lesson order, separate staffing state, frozen final capacity, incomplete lessons, VI and read-only rollback\n";
} finally { $db->drop(); }

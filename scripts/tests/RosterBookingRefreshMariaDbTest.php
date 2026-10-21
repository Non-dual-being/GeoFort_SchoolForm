<?php
declare(strict_types=1);
require __DIR__.'/Support/RosterLifecycleFixtures.php';

use GeoFort\Services\Dashboard\Roster\{RosterBookingRefreshService,RosterPlanException,RosterSourceFingerprint};
use GeoFort\Services\Sql\{RosterPairSqlRepository,RosterSessionCookSqlRepository,BookingChangeHistorySqlRepository};
use GeoFort\Booking\ProgramConfiguration\{BookingProgramConfigurationCommand,BookingProgramConfigurationSnapshot};
use GeoFort\Services\Booking\ProgramConfiguration\BookingProgramConfigurationServiceFactory;

$db=DisposableBookingMariaDb::create('booking_refresh');$pdo=$db->pdo;$assert=RosterLifecycleFixtures::assert(...);
try {
    RosterLifecycleFixtures::schema($pdo);
    $pdo->exec(file_get_contents(dirname(__DIR__,2).'/database/sql/2026-10-21_create_roster_booking_refresh_history.sql'));
    $s=RosterLifecycleFixtures::services($pdo);
    $refresh=new RosterBookingRefreshService($pdo,$s['service'],$s['plans'],$s['bookings'],$s['config']);
    $pdo->exec("INSERT INTO roster_staff_members(display_name,is_active,can_guide,can_cook) VALUES('Guide',1,1,0),('Cook',1,0,1)");
    $staff=array_map('intval',$pdo->query('SELECT id FROM roster_staff_members ORDER BY id')->fetchAll(PDO::FETCH_COLUMN));
    $guide=$staff[0];$cook=$staff[1];
    $make=static function(int $students=80,?string $version=null,string $program='dag') use($pdo,$s):array {
        $pdo->prepare("INSERT INTO aanvragen(status,schoolnaam,land,adres,postcode,plaats,school_telefoonnummer,contactpersoon_telefoonnummer,contactpersoon_voornaam,contactpersoon_achternaam,email,bezoekdatum,cjpPasGebruik,onderwijs_sector,programma,keuzemodule_key,aantal_leerlingen,aantal_begeleiders,voorwaarden_akkoord,program_version)
            VALUES('In optie','Refresh fixture','Nederland','Testweg 1','1234 AB','Testplaats','0100000000','0600000000','Test','Planner','fixture@example.test','2028-11-01','nee','primairOnderwijs',?,?,?,5,1,?)")
            ->execute([$program,$program==='ochtend'?null:($version===null?'Earth-Watch':'Klimaat-Experience'),$students,$version]);
        $id=(int)$pdo->lastInsertId();
        $pdo->prepare("INSERT INTO aanvraag_onderwijs_selecties(aanvraag_id,sector_key,sector_label,level_key,level_label,level_position,group_key,group_label,group_position) VALUES(?,'primairOnderwijs','Primair onderwijs','regulier','Regulier',1,'groep7','Groep 7',1)")->execute([$id]);
        return $s['service']->createFromBooking($id,1)['plan'];
    };
    $seed=static function(array $p) use($s,$guide,$cook,$pdo):array {
        foreach ($p['groups'] as $g) {
            $id=$s['sessions']->insertActivity($p['id'],'Dynamische-Globe','10:15','11:00',null,1);
            $s['sessions']->replaceGroups($id,[$g['id']]);$s['sessions']->setGroupSupervision($id,$g['id'],'staff');
            $s['staff']->replaceSessionAssignments($id,[$guide],'manual');
            $s['staff']->replaceSessionGroupAssignments($id,[$g['id']=>[$guide]],'manual');
        }
        $id=$s['sessions']->insertActivity($p['id'],'Voedsel-Innovatie','11:15','12:00',null,1);
        $s['sessions']->replaceGroups($id,[$p['groups'][0]['id']]);$s['staff']->replaceSessionAssignments($id,[$guide],'manual');
        (new RosterSessionCookSqlRepository($pdo))->save($id,$cook);
        $s['staff']->replacePlanSelection($p['id'],[$guide,$cook]);$s['staff']->savePlanSettings($p['id'],'with_staff',true,$cook);
        $s['staff']->replacePlanModulePreferences($p['id'],[['staffId'=>$guide,'moduleKeys'=>['Dynamische-Globe','Earth-Watch']]]);
        return $s['service']->getPlan($p['id']);
    };
    $state=static function(int $id) use($s):string {
        $raw=$s['raw']->load($id);unset($raw['booking'],$raw['educationSelections'],$raw['staff'],$raw['skills']);return serialize($raw);
    };
    $change=static function(array $p,array $fields) use($pdo):void {
        $q=$pdo->prepare('SELECT * FROM aanvragen WHERE id=?');$q->execute([$p['bookingId']]);$before=$q->fetch(PDO::FETCH_ASSOC);$changes=[];
        foreach($fields as $field=>$after) {
            if(!in_array($field,['programma','program_version','keuzemodule_key','aantal_leerlingen','aantal_begeleiders','bezoekdatum'],true))throw new RuntimeException('Invalid test field');
            $old=$before[$field];if(in_array($field,['aantal_leerlingen','aantal_begeleiders'],true))$old=(int)$old;
            if($old!==$after)$changes[$field]=['before'=>$old,'after'=>$after];
        }
        $pdo->beginTransaction();
        foreach($fields as $field=>$value)$pdo->prepare('UPDATE aanvragen SET '.$field.'=? WHERE id=?')->execute([$value,$p['bookingId']]);
        (new BookingChangeHistorySqlRepository($pdo))->insertProgramConfigurationChange($p['bookingId'],$changes,1);$pdo->commit();
    };
    $err=static function(callable $fn,string $code)use($assert):void {
        try{$fn();throw new RuntimeException('Expected '.$code);}catch(RosterPlanException $e){$assert($e->publicCode===$code,$e->publicCode.' != '.$code);}
    };
    $fixture=static function(string $name,array $p,array $v)use($s):void {
        $directory=getenv('ROSTER_REFRESH_FIXTURE_DIR');
        if(!$directory)return;
        if(!is_dir($directory))throw new RuntimeException('Fixture directory missing');
        file_put_contents($directory.'/'.$name.'.json',json_encode(['plan'=>$s['service']->getPlan($p['id']),'preview'=>$v],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));
    };
    $apply=static fn(array $p,array $v):array=>$refresh->apply($p['id'],$p['revision'],$v['signature'],true,1);
    $noStaff=static function(array $p)use($s,$assert,$guide,$cook):void {
        $raw=$s['raw']->load($p['id']);
        $assert($raw['sessionStaff']===[]&&$raw['groupStaff']===[],'Assignments remain');
        $assert($p['planning']['staffingSettings']['cookStaffId']===null,'Fallback cook remains');
        foreach($p['planning']['sessions'] as $lesson)$assert($lesson['cookStaffId']===null,'Session cook remains');
        $assert($s['staff']->selectedIdsForPlan($p['id'])===[$guide,$cook],'Staff selection was cleared');
        $assert($s['staff']->plannerModulePreferencesForPlan($p['id'])!==[],'Staff preferences were cleared');
    };

    $p=$seed($make(120));$before=$state($p['id']);$ids=array_column($p['planning']['sessions'],'id');
    $change($p,['aantal_leerlingen'=>80]);$assert($state($p['id'])===$before,'Booking edit changed roster');
    $v=$refresh->preview($p['id'],$p['revision']);
    $fixture('groups',$p,$v);
    $assert($v['changeType']==='groups'&&!$v['resetLessons']&&$v['clearStaffing']&&$v['afterGroupCount']===5,'Group change classification');
    $assert($state($p['id'])===$before,'Preview changed roster');
    $err(fn()=>$refresh->apply($p['id'],$p['revision'],$v['signature'],false,1),'REFRESH_CONFIRMATION_REQUIRED');
    $change($p,['aantal_leerlingen'=>79]);$err(fn()=>$apply($p,$v),'REFRESH_PREVIEW_CHANGED');$change($p,['aantal_leerlingen'=>80]);
    $pdo->exec("CREATE TRIGGER refresh_rollback_test BEFORE UPDATE ON roster_plans FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Intentional transaction test'");
    try { $failed=false;try{$apply($p,$v);}catch(Throwable){$failed=true;}$assert($failed&&$state($p['id'])===$before,'Failed transaction damaged roster'); }
    finally{$pdo->exec('DROP TRIGGER refresh_rollback_test');}
    $assert((int)$pdo->query('SELECT COUNT(*) FROM roster_booking_refresh_history')->fetchColumn()===0,'Rolled back history remains');
    $after=$apply($p,$v);$noStaff($after);
    $assert(array_column($after['groups'],'id')===array_column(array_slice($p['groups'],0,5),'id'),'Retained group IDs changed');
    $assert(array_column($after['planning']['sessions'],'id')===[...array_slice($ids,0,5),$ids[7]],'Retained lessons changed');
    $assert($after['planning']['sessions'][0]['startTime']==='10:15'&&!$after['needsReview'],'Times/source status wrong');
    $err(fn()=>$apply($p,$v),'REVISION_CONFLICT');
    $s['service']->deleteConcept($p['id']);$assert($s['service']->getPlan($p['id'])===null,'Explicit concept delete blocked');
    echo "PASS groups: booking/preview/no consent unchanged, stale preview, rollback, trim lessons, clear guides and both cooks, retain staff/preferences\n";

    foreach([null,'day-four-2027'] as $version){
        $p=$seed($make(80,$version));$before=$state($p['id']);$change($p,['aantal_leerlingen'=>79]);$v=$refresh->preview($p['id'],$p['revision']);
        $assert(!$v['resetLessons']&&!$v['clearStaffing']&&$v['changeType']==='details','Same group count must preserve staffing');
        $after=$apply($p,$v);$assert(array_column($after['planning']['sessions'],'id')===array_column($p['planning']['sessions'],'id'),'Same-count lessons changed');
        $assert($after['planning']['staffingSettings']===$p['planning']['staffingSettings'],'Same-count staff changed');
        $assert($after['planning']['sessions']===$p['planning']['sessions'],'Same-count lesson or assignment details changed');
        $change($after,['aantal_leerlingen'=>100]);$v=$refresh->preview($after['id'],$after['revision']);$after=$apply($after,$v);$noStaff($after);
        $assert(count($after['groups'])===6,'Additional group not added');
        $assert(count($after['planning']['sessions'])===count($p['planning']['sessions']),'Adding groups removed lessons');
    }
    echo "PASS four/five variants: same group count preserves lessons/staff; extra group keeps lessons and resets staffing\n";

    $p=$seed($make(120));$g1=$p['groups'][0]['id'];$g7=$p['groups'][6]['id'];
    $shared=$s['sessions']->insertActivity($p['id'],'Klimaat-Experience','13:15','14:00',null,1);
    $s['sessions']->replaceGroups($shared,[$g1,$g7]);$s['sessions']->setGroupSupervision($shared,$g1,'school');$s['sessions']->setGroupSupervision($shared,$g7,'staff');
    $s['staff']->replaceSessionGroupAssignments($shared,[$g7=>[$guide]],'manual');$s['staff']->replaceSessionAssignments($shared,[$guide],'manual');
    $change($p,['aantal_leerlingen'=>80]);$v=$refresh->preview($p['id'],$p['revision']);$after=$apply($p,$v);$noStaff($after);
    $sharedAfter=array_values(array_filter($after['planning']['sessions'],static fn(array $lesson):bool=>$lesson['id']===$shared))[0];
    $assert($sharedAfter['groupIds']===[$g1]&&$sharedAfter['groupSupervision'][0]['kind']==='school'&&$sharedAfter['startTime']==='13:15','Shared lesson or school supervision lost');
    echo "PASS partial shared lesson retained, removed group trimmed, school supervision retained\n";

    // Exercise the same application service used by the dashboard, including its real audit.
    $p=$seed($make(80));$before=$state($p['id']);$stored=$s['bookings']->findById($p['bookingId']);
    $expected=BookingProgramConfigurationSnapshot::fromBooking($stored);
    $result=(new BookingProgramConfigurationServiceFactory($pdo))->create()->change(new BookingProgramConfigurationCommand($stored->id,$expected,new BookingProgramConfigurationSnapshot($stored->status,$stored->visitDate,'dag',80,$stored->educationSelection,'Minecraft-Programmeren'),1));
    $assert($result->success,'Dashboard module change failed: '.$result->code->value);
    $assert($state($p['id'])===$before,'Dashboard saved choice changed roster before confirmation');
    $v=$refresh->preview($p['id'],$p['revision']);
    $fixture('choice',$p,$v);
    $assert($v['changeType']==='choice_module'&&$v['resetLessons']&&count($v['affectedLessons'])===count($p['planning']['sessions']),'Module change did not reset ALL lessons');
    $after=$apply($p,$v);$assert($after['planning']['sessions']===[],'Old common modules survived choice reset');$noStaff($after);
    echo "PASS real dashboard choice edit: saved roster untouched, all lessons/cooks/guides reset only after confirmation\n";
    // A combined group+module change follows the stricter full-reset rule.
    $p=$seed($make(120));$change($p,['aantal_leerlingen'=>80,'keuzemodule_key'=>'Minecraft-Programmeren']);$v=$refresh->preview($p['id'],$p['revision']);
    $assert($v['resetLessons']&&$v['afterGroupCount']===5,'Combined change did not prioritize full reset');$after=$apply($p,$v);$noStaff($after);
    $assert($after['planning']['sessions']===[]&&count($after['groups'])===5,'Combined change not applied atomically');


    foreach([['programma'=>'ochtend','keuzemodule_key'=>null],['program_version'=>'day-four-2027','keuzemodule_key'=>'Klimaat-Experience']] as $fields){
        $p=$seed($make(80));$change($p,$fields);$v=$refresh->preview($p['id'],$p['revision']);
        $fixture('program',$p,$v);
        $assert($v['changeType']==='program'&&$v['resetLessons'],'Program/variant classification');
        $after=$apply($p,$v);$assert($after['planning']['sessions']===[]&&!$after['needsReview'],'Program reset incomplete');$noStaff($after);
        $assert(count($after['planning']['generation']['defaultRounds'])===(isset($fields['program_version'])?4:5),'New programme timeline not selected');
    }
    $p=$make(80,null,'ochtend');$change($p,['aantal_leerlingen'=>60]);$v=$refresh->preview($p['id'],$p['revision']);$assert(!$v['resetLessons'],'Morning group change reset lessons');$apply($p,$v);
    echo "PASS day -> morning, five -> four, morning groups and new default timeline\n";

    $p=$seed($make(80));$before=$state($p['id']);$pdo->prepare("UPDATE aanvragen SET email='changed@example.test',schoolnaam='New contact name' WHERE id=?")->execute([$p['bookingId']]);
    $v=$refresh->preview($p['id'],$p['revision']);$assert(!$v['canApply']&&!$v['clearStaffing']&&!$v['resetLessons']&&$state($p['id'])===$before,'Contact edit affected roster');
    // Unknown history must not be treated as a harmless group change.
    $pdo->prepare("UPDATE aanvragen SET keuzemodule_key='Minecraft-Programmeren' WHERE id=?")->execute([$p['bookingId']]);
    $v=$refresh->preview($p['id'],$p['revision']);$assert($v['changeType']==='unresolved'&&!$v['canApply'],'Unverified source inferred');$err(fn()=>$apply($p,$v),'REFRESH_BLOCKED');
    $assert($state($p['id'])===$before,'Unverified source damaged roster');
    echo "PASS contact-only changes preserve everything; incomplete historical evidence blocks destructive changes\n";

    $p=$seed($make(80));$other=$seed($make(80));$pdo->beginTransaction();(new RosterPairSqlRepository($pdo))->create([$p['id'],$other['id']],$p['visitDate'],'lessons_first',1);$pdo->commit();$otherBefore=$state($other['id']);
    $change($p,['aantal_leerlingen'=>100]);$v=$refresh->preview($p['id'],$p['revision']);$assert(!$v['canApply'],'Pair group limit ignored');
    $change($p,['aantal_leerlingen'=>60]);$v=$refresh->preview($p['id'],$p['revision']);$p=$apply($p,$v);$noStaff($p);$assert($state($other['id'])===$otherBefore,'Other school changed');
    $change($p,['keuzemodule_key'=>'Minecraft-Programmeren']);$v=$refresh->preview($p['id'],$p['revision']);$p=$apply($p,$v);$assert($state($other['id'])===$otherBefore,'Other school cleared by programme reset');
    $change($p,['bezoekdatum'=>'2028-11-02']);$assert(!$refresh->preview($p['id'],$p['revision'])['canApply'],'Paired visit date bypass');
    $custom=$s['service']->createManual('Custom','City','2028-11-01','primairOnderwijs','dag',80,7,1)['plan'];$err(fn()=>$refresh->preview($custom['id'],$custom['revision']),'REFRESH_NOT_ALLOWED');
    $pdo->prepare("UPDATE roster_plans SET status='published' WHERE id=?")->execute([$p['id']]);$err(fn()=>$refresh->preview($p['id'],$p['revision']),'REFRESH_NOT_ALLOWED');
    echo "PASS paired school untouched, joint constraints, custom and published protected\n";
} finally {$db->drop();}

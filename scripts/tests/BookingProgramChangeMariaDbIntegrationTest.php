<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/vendor/autoload.php';
require __DIR__.'/Support/DisposableBookingMariaDb.php';

use GeoFort\Booking\Program\{BookingProgramChangeCode,BookingProgramChangeCommand};
use GeoFort\Booking\Rules\BookingRuleOverrideRequest;
use GeoFort\Services\Booking\Program\BookingProgramChangeServiceFactory;
use GeoFort\Services\Sql\BookingProgramSqlRepository;

$disposable=DisposableBookingMariaDb::create('program_change');$pdo=$disposable->pdo;
$ids=[];$triggers=[];$assert=static function(bool $condition,string $message):void{if(!$condition)throw new RuntimeException($message);};
$insert=$pdo->prepare("INSERT INTO aanvragen(status,schoolnaam,land,adres,postcode,plaats,school_telefoonnummer,contactpersoon_telefoonnummer,contactpersoon_voornaam,contactpersoon_achternaam,email,bezoekdatum,cjpPasGebruik,onderwijs_sector,programma,keuzemodule_key,aantal_leerlingen,aantal_begeleiders,voorwaarden_akkoord) VALUES(:status,'Programmatest','Nederland','Dijk 1','1234 AB','Plaats','1','2','Jan','Jansen','program-test@example.test',:date,'nee','primairOnderwijs','dag',NULL,:students,5,1)");
$selection=$pdo->prepare("INSERT INTO aanvraag_onderwijs_selecties(aanvraag_id,sector_key,sector_label,level_key,level_label,level_position,group_key,group_label,group_position) VALUES(:id,'primairOnderwijs','Primair onderwijs','regulier','Regulier basisonderwijs',0,'groep7','Groep 7',0)");
$make=static function(string $status,string $date='2027-09-15',int $students=40)use($insert,$selection,$pdo,&$ids):int{$insert->execute([':status'=>$status,':date'=>$date,':students'=>$students]);$id=(int)$pdo->lastInsertId();$ids[]=$id;$selection->execute([':id'=>$id]);$stored=(new GeoFort\Services\Sql\StoredBookingSqlRepository($pdo,new GeoFort\Booking\Stored\StoredBookingAssembler()))->findById($id)??throw new RuntimeException('Fixture ontbreekt.');$snapshots=(new GeoFort\Services\Booking\Pricing\BookingPriceSnapshotServiceFactory($pdo))->create();$pdo->beginTransaction();$snapshots->appendUsingActiveVersion($id,GeoFort\Services\Booking\Pricing\BookingPricingInput::fromStoredBooking($stored),GeoFort\Services\Booking\Pricing\BookingPriceSnapshot::REASON_SUBMISSION);$pdo->commit();return $id;};
try{
    $service=(new BookingProgramChangeServiceFactory($pdo))->create();
    foreach(['In optie','Definitief','Afgewezen'] as $status){
        $id=$make($status);$before=$pdo->query("SELECT * FROM aanvragen WHERE id={$id}")->fetch();$result=$service->change(new BookingProgramChangeCommand($id,'dag','ochtend',1));$after=$pdo->query("SELECT * FROM aanvragen WHERE id={$id}")->fetch();$audit=$pdo->query("SELECT change_type,changed_fields_json FROM booking_change_history WHERE booking_id={$id}")->fetch();$fields=json_decode((string)$audit['changed_fields_json'],true,512,JSON_THROW_ON_ERROR);$before['programma']=$after['programma'];
        $assert($result->code===BookingProgramChangeCode::Success&&$after['status']===$status&&$after['programma']==='ochtend'&&$before===$after,'Succes wijzigt meer dan programma of bewaart status niet.');
        $assert($audit['change_type']==='program_changed'&&($fields['programma']['before']??null)==='dag'&&($fields['programma']['after']??null)==='ochtend','Programma-audit klopt niet.');
        $priceHistory=$pdo->query("SELECT sequence_number,snapshot_reason,pricing_version FROM booking_price_snapshots WHERE booking_id={$id} ORDER BY sequence_number")->fetchAll();
        $assert(count($priceHistory)===2&&(int)$priceHistory[1]['sequence_number']===2&&$priceHistory[1]['snapshot_reason']==='planner_update'&&$priceHistory[1]['pricing_version']===$priceHistory[0]['pricing_version'],'Programmawijziging hergebruikt historische prijsversie/sequence niet.');
    }
    $noopId=$make('In optie');$noop=$service->change(new BookingProgramChangeCommand($noopId,'dag','dag',1));$assert($noop->code===BookingProgramChangeCode::NoProgramChange&&(int)$pdo->query("SELECT COUNT(*) FROM booking_change_history WHERE booking_id={$noopId}")->fetchColumn()===0,'No-op schrijft audit.');
    $conflict=$service->change(new BookingProgramChangeCommand($noopId,'ochtend','dag',1));$assert($conflict->code===BookingProgramChangeCode::ProgramConflict,'Expected/stored conflict ontbreekt.');
    $unknown=$service->change(new BookingProgramChangeCommand($noopId,'dag','onbekend',1));$assert($unknown->code===BookingProgramChangeCode::InvalidProgramSelection,'Onbekend programma wordt geaccepteerd.');
    $secondPdo=$disposable->connect();
    $guardedId=$make('In optie','2027-10-06');
    $assert((new BookingProgramSqlRepository($secondPdo))->guardedUpdate($guardedId,'dag','ochtend'),'Guarded update op de tweede PDO-verbinding faalt.');
    $lostUpdate=$service->change(new BookingProgramChangeCommand($guardedId,'dag','ochtend',1));
    $assert($lostUpdate->code===BookingProgramChangeCode::ProgramConflict&&(string)$pdo->query("SELECT programma FROM aanvragen WHERE id={$guardedId}")->fetchColumn()==='ochtend','Twee PDO-verbindingen voorkomen geen lost update.');
    $wrongDay=$make('In optie','2027-09-16');$invalid=$service->change(new BookingProgramChangeCommand($wrongDay,'dag','ochtend',1));$assert($invalid->code===BookingProgramChangeCode::InvalidStoredBooking&&in_array('CURRENT_CONFIGURATION_MISMATCH',array_map(static fn($issue)=>$issue->code,$invalid->validationIssues),true),'Programma/weekdagconflict ontbreekt.');
    $wrongSector=$make('In optie','2027-10-13');$pdo->exec("UPDATE aanvragen SET onderwijs_sector='voortgezetOnderbouw' WHERE id={$wrongSector}");$pdo->exec("UPDATE aanvraag_onderwijs_selecties SET sector_key='voortgezetOnderbouw',level_key='havo',group_key='havo1' WHERE aanvraag_id={$wrongSector}");
    $sectorResult=$service->change(new BookingProgramChangeCommand($wrongSector,'dag','ochtend',1));
    $assert($sectorResult->code===BookingProgramChangeCode::InvalidStoredBooking,'Schoolsector/programmacompatibiliteit blokkeert niet.');
    $moduleId=$make('In optie','2027-10-20');$pdo->exec("UPDATE aanvragen SET keuzemodule_key='Earth-Watch' WHERE id={$moduleId}");
    $moduleResult=$service->change(new BookingProgramChangeCommand($moduleId,'dag','ochtend',1));
    $assert($moduleResult->code===BookingProgramChangeCode::InvalidStoredBooking&&in_array('keuzemodule',array_map(static fn($issue)=>$issue->field,$moduleResult->validationIssues),true),'Programma-afhankelijke keuzemodulefout is niet gericht.');
    $oldEducationId=$make('In optie','2027-10-27');$pdo->exec("UPDATE aanvraag_onderwijs_selecties SET level_key='onbekend' WHERE aanvraag_id={$oldEducationId}");
    $oldEducationResult=$service->change(new BookingProgramChangeCommand($oldEducationId,'dag','ochtend',1));
    $assert($oldEducationResult->code===BookingProgramChangeCode::Success,'Een oud programma-onafhankelijk onderwijsprobleem blokkeert de draftmutatie.');
    $limit=$make('Definitief','2027-09-22',81);$limited=$service->change(new BookingProgramChangeCommand($limit,'dag','ochtend',1));$assert($limited->code===BookingProgramChangeCode::OverrideRequired&&in_array('PROGRAM_STUDENT_LIMIT_EXCEEDED',array_map(static fn($issue)=>$issue->code,$limited->validationIssues),true),'Definitieve programmalimiet/override ontbreekt.');
    $hardOverride=$service->change(new BookingProgramChangeCommand($wrongDay,'dag','ochtend',1,[new BookingRuleOverrideRequest('CURRENT_CONFIGURATION_MISMATCH','Deze harde regel mag nooit worden overschreven.')]));
    $assert(in_array($hardOverride->code,[BookingProgramChangeCode::InvalidStoredBooking,BookingProgramChangeCode::OverrideNotAllowed],true),'Een harde programma/weekdagregel is overschrijfbaar.');
    $capacityDate='2027-11-03';$other=$make('Definitief',$capacityDate,120);$capacityId=$make('Definitief',$capacityDate,40);
    $capacitySuccess=$service->change(new BookingProgramChangeCommand($capacityId,'dag','ochtend',1));
    $assert($capacitySuccess->code===BookingProgramChangeCode::Success,'Huidige booking is niet uitgesloten of proposed booking wordt dubbel geteld op de capaciteitsgrens.');
    $overDate='2027-11-10';$otherOver=$make('Definitief',$overDate,121);$overId=$make('Definitief',$overDate,40);
    $overResult=$service->change(new BookingProgramChangeCommand($overId,'dag','ochtend',1));
    $assert($overResult->code===BookingProgramChangeCode::OverrideRequired&&in_array('STUDENT_LIMIT_EXCEEDED',array_map(static fn($issue)=>$issue->code,$overResult->validationIssues),true),'Totale capaciteit op de bestaande bezoekdatum ontbreekt.');
    $overridden=$service->change(new BookingProgramChangeCommand($overId,'dag','ochtend',1,[new BookingRuleOverrideRequest('STUDENT_LIMIT_EXCEEDED','Planner accepteert bewust de overschrijding voor deze aanvraag.')]));
    $overrideRow=$pdo->query("SELECT status_history_id,booking_change_history_id FROM booking_rule_overrides WHERE booking_id={$overId}")->fetch();
    $assert($overridden->code===BookingProgramChangeCode::Success&&$overrideRow['status_history_id']===null&&(int)$overrideRow['booking_change_history_id']===$overridden->changeHistoryId,'Geldige override of change-history-XOR-koppeling faalt.');
    $draftLockId=$make('In optie','2027-11-17');$beforeDayRows=(int)$pdo->query("SELECT COUNT(*) FROM booking_day_settings WHERE visit_date='2027-11-17'")->fetchColumn();$draftLockResult=$service->change(new BookingProgramChangeCommand($draftLockId,'dag','ochtend',1));$afterDayRows=(int)$pdo->query("SELECT COUNT(*) FROM booking_day_settings WHERE visit_date='2027-11-17'")->fetchColumn();
    $assert($draftLockResult->code===BookingProgramChangeCode::Success&&$beforeDayRows===$afterDayRows,'Draft gebruikt onnodig een datumlock of capaciteitscontext.');
    $auditFailureId=$make('In optie','2027-11-24');$pdo->exec("CREATE TRIGGER fail_program_change_history BEFORE INSERT ON booking_change_history FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='forced audit failure'");$triggers[]='fail_program_change_history';
    $auditFailure=$service->change(new BookingProgramChangeCommand($auditFailureId,'dag','ochtend',1));$pdo->exec('DROP TRIGGER fail_program_change_history');array_pop($triggers);
    $assert($auditFailure->code===BookingProgramChangeCode::DatabaseError&&(string)$pdo->query("SELECT programma FROM aanvragen WHERE id={$auditFailureId}")->fetchColumn()==='dag'&&(int)$pdo->query("SELECT COUNT(*) FROM booking_change_history WHERE booking_id={$auditFailureId}")->fetchColumn()===0,'Auditfout rolt programma-update niet volledig terug.');
    $snapshotFailureId=$make('In optie','2027-12-08');$pdo->exec("CREATE TRIGGER fail_program_price_snapshot BEFORE INSERT ON booking_price_snapshots FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='forced snapshot failure'");$triggers[]='fail_program_price_snapshot';
    $snapshotFailure=$service->change(new BookingProgramChangeCommand($snapshotFailureId,'dag','ochtend',1));$pdo->exec('DROP TRIGGER fail_program_price_snapshot');array_pop($triggers);
    $assert($snapshotFailure->code===BookingProgramChangeCode::DatabaseError&&(string)$pdo->query("SELECT programma FROM aanvragen WHERE id={$snapshotFailureId}")->fetchColumn()==='dag'&&(int)$pdo->query("SELECT COUNT(*) FROM booking_price_snapshots WHERE booking_id={$snapshotFailureId}")->fetchColumn()===1,'Snapshotfout rolt programma en snapshot niet terug.');
    $overrideFailureId=$make('Definitief','2027-12-01',81);$pdo->exec("CREATE TRIGGER fail_program_override_audit BEFORE INSERT ON booking_rule_overrides FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='forced override failure'");$triggers[]='fail_program_override_audit';
    $overrideFailure=$service->change(new BookingProgramChangeCommand($overrideFailureId,'dag','ochtend',1,[new BookingRuleOverrideRequest('PROGRAM_STUDENT_LIMIT_EXCEEDED','Planner accepteert bewust deze programmalimiet.')]));
    $pdo->exec('DROP TRIGGER fail_program_override_audit');array_pop($triggers);
    $assert($overrideFailure->code===BookingProgramChangeCode::DatabaseError&&(string)$pdo->query("SELECT programma FROM aanvragen WHERE id={$overrideFailureId}")->fetchColumn()==='dag'&&(int)$pdo->query("SELECT COUNT(*) FROM booking_change_history WHERE booking_id={$overrideFailureId}")->fetchColumn()===0,'Override-auditfout rolt update en change-history niet terug.');
    $missing=$service->change(new BookingProgramChangeCommand(999999,'dag','ochtend',1));$assert($missing->code===BookingProgramChangeCode::BookingNotFound,'Booking-not-found ontbreekt.');
    $idList=implode(',',array_map('intval',$ids));$assert((int)$pdo->query("SELECT COUNT(*) FROM booking_status_history WHERE booking_id IN ({$idList})")->fetchColumn()===0,'Programmawijziging schrijft statushistorie.');
    fwrite(STDOUT,"OK: programma MariaDB-integratie geslaagd.\n");
}finally{
    foreach($triggers as $trigger){$pdo->exec("DROP TRIGGER IF EXISTS {$trigger}");}
    $disposable->drop();
}

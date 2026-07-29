<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/vendor/autoload.php';

use GeoFort\Booking\VisitDate\{BookingVisitDateChangeCode,BookingVisitDateChangeCommand};
use GeoFort\Booking\Rules\BookingRuleOverrideRequest;
use GeoFort\Services\Booking\VisitDate\BookingVisitDateChangeServiceFactory;

if(($_ENV['STATUS_TEST_DB_CONFIRM']??getenv('STATUS_TEST_DB_CONFIRM'))!=='YES_DISPOSABLE')throw new RuntimeException('Disposable databasebevestiging ontbreekt.');
$env=static fn(string $key,string $default=''):string=>(string)($_ENV[$key]??getenv($key)?:$default);
$pdo=new \PDO('mysql:host='.$env('STATUS_TEST_DB_HOST','127.0.0.1').';port='.$env('STATUS_TEST_DB_PORT','3306').';dbname='.$env('STATUS_TEST_DB_NAME').';charset=utf8mb4',$env('STATUS_TEST_DB_USER','root'),$env('STATUS_TEST_DB_PASSWORD'),[\PDO::ATTR_ERRMODE=>\PDO::ERRMODE_EXCEPTION,\PDO::ATTR_DEFAULT_FETCH_MODE=>\PDO::FETCH_ASSOC]);
$tables=['booking_rule_overrides','booking_change_history','booking_status_history','booking_day_settings','aanvraag_onderwijs_selecties','disabled_dates','aanvragen','admin_users'];
foreach($tables as $table)$pdo->exec("DROP TABLE IF EXISTS {$table}");
$pdo->exec("CREATE TABLE admin_users(id INT UNSIGNED PRIMARY KEY,name VARCHAR(100),email VARCHAR(255),password_hash VARCHAR(255),role VARCHAR(30),is_active TINYINT DEFAULT 1) ENGINE=InnoDB");
$pdo->exec("INSERT INTO admin_users(id,name,email,password_hash,role,is_active) VALUES(1,'Admin','admin@example.test','x','admin',1)");
$pdo->exec("CREATE TABLE aanvragen(id INT AUTO_INCREMENT PRIMARY KEY,status ENUM('In optie','Definitief','Afgewezen') NOT NULL,schoolnaam VARCHAR(255) NOT NULL,land VARCHAR(32) NOT NULL,adres VARCHAR(255) NOT NULL,postcode VARCHAR(16) NOT NULL,plaats VARCHAR(120) NOT NULL,school_telefoonnummer VARCHAR(25) NOT NULL,contactpersoon_telefoonnummer VARCHAR(25) NOT NULL,contactpersoon_voornaam VARCHAR(255) NOT NULL,contactpersoon_achternaam VARCHAR(255) NOT NULL,email VARCHAR(255) NOT NULL,bezoekdatum DATE NOT NULL,hoe_kent_u_geofort VARCHAR(120),opmerkingen TEXT,cjpPasGebruik VARCHAR(3),cjpContactpersoonNaam VARCHAR(80),cjpPasnummer VARCHAR(9),onderwijs_sector VARCHAR(40),programma VARCHAR(20),keuzemodule_key VARCHAR(120),aantal_leerlingen INT,aantal_begeleiders INT,remise_break INT DEFAULT 0,kazerne_break INT DEFAULT 0,fortgracht_break INT DEFAULT 0,glas_limonade INT DEFAULT 0,waterijsje INT DEFAULT 0,remise_lunch INT DEFAULT 0,eigen_picknick TINYINT DEFAULT 1,voorwaarden_akkoord TINYINT DEFAULT 1,voorwaarden_akkoord_op DATETIME NULL,source_system VARCHAR(80),source_record_id INT,source_record_checksum VARCHAR(64),source_import_run_id VARCHAR(64),KEY idx_date_status(bezoekdatum,status)) ENGINE=InnoDB");
$pdo->exec("CREATE TABLE aanvraag_onderwijs_selecties(id INT AUTO_INCREMENT PRIMARY KEY,aanvraag_id INT NOT NULL,sector_key VARCHAR(40),sector_label VARCHAR(120),level_key VARCHAR(80),level_label VARCHAR(160),level_position INT,group_key VARCHAR(80),group_label VARCHAR(160),group_position INT) ENGINE=InnoDB");
$pdo->exec("CREATE TABLE disabled_dates(datum DATE PRIMARY KEY,type VARCHAR(30),reden VARCHAR(255)) ENGINE=InnoDB");
$sqlRoot=dirname(__DIR__,2).'/database/sql/';
foreach(['2026-07-17_create_booking_day_settings.sql','2026-07-18_create_booking_status_history.sql','2026-07-21_create_booking_rule_overrides.sql','2026-07-22_create_booking_change_history.sql','2026-07-22_link_overrides_to_change_history.sql'] as $migration)$pdo->exec((string)file_get_contents($sqlRoot.$migration));
$insert=$pdo->prepare("INSERT INTO aanvragen(status,schoolnaam,land,adres,postcode,plaats,school_telefoonnummer,contactpersoon_telefoonnummer,contactpersoon_voornaam,contactpersoon_achternaam,email,bezoekdatum,cjpPasGebruik,onderwijs_sector,programma,keuzemodule_key,aantal_leerlingen,aantal_begeleiders) VALUES(:status,'School','Nederland','Dijk 1','1234 AB','Plaats','1','2','Jan','Jansen','jan@example.test',:date,'nee','primairOnderwijs','dag','Earth-Watch',40,4)");
$make=static function(string $status,string $date)use($insert,$pdo):int{$insert->execute([':status'=>$status,':date'=>$date]);return(int)$pdo->lastInsertId();};
$service=(new BookingVisitDateChangeServiceFactory($pdo))->create();
$assert=static function(bool $condition,string $message):void{if(!$condition)throw new RuntimeException($message);};
foreach([['In optie','2026-09-14','2026-09-21'],['Definitief','2026-09-15','2026-09-22'],['Afgewezen','2026-09-16','2026-09-23']] as [$status,$before,$after]){
    $id=$make($status,$before);$result=$service->change(new BookingVisitDateChangeCommand($id,$before,$after,1));
    $row=$pdo->query("SELECT status,bezoekdatum FROM aanvragen WHERE id={$id}")->fetch();
    $audit=$pdo->query("SELECT change_type,changed_fields_json FROM booking_change_history WHERE booking_id={$id}")->fetch();
    $fields=json_decode((string)$audit['changed_fields_json'],true,512,JSON_THROW_ON_ERROR);
    $assert($result->code===BookingVisitDateChangeCode::Success&&$row['status']===$status&&$row['bezoekdatum']===$after&&$audit['change_type']==='visit_date_changed'&&$fields['bezoekdatum']['before']===$before&&$fields['bezoekdatum']['after']===$after,"Succes/audit/status faalt voor {$status}: {$result->code->value} ".json_encode([$row,$audit,$fields]));
}
$id=$make('In optie','2026-10-01');$noop=$service->change(new BookingVisitDateChangeCommand($id,'2026-10-01','2026-10-01',1));$assert($noop->code===BookingVisitDateChangeCode::NoVisitDateChange&&(int)$pdo->query("SELECT COUNT(*) FROM booking_change_history WHERE booking_id={$id}")->fetchColumn()===0,'No-op schrijft audit.');
$pdo->exec("INSERT INTO disabled_dates(datum,type,reden) VALUES('2026-10-08','blocked','No-op test')");
$confirmedNoop=$make('Definitief','2026-10-08');$confirmedNoopResult=$service->change(new BookingVisitDateChangeCommand($confirmedNoop,'2026-10-08','2026-10-08',1));
$assert($confirmedNoopResult->code===BookingVisitDateChangeCode::NoVisitDateChange&&(int)$pdo->query("SELECT COUNT(*) FROM booking_change_history WHERE booking_id={$confirmedNoop}")->fetchColumn()===0,'Definitieve no-op wordt ten onrechte gevalideerd of geaudit.');
$conflict=$service->change(new BookingVisitDateChangeCommand($id,'2026-10-02','2026-10-03',1));$assert($conflict->code===BookingVisitDateChangeCode::VisitDateConflict,'Optimistic conflict ontbreekt.');
$missing=$service->change(new BookingVisitDateChangeCommand(999999,'2026-10-01','2026-10-02',1));$assert($missing->code===BookingVisitDateChangeCode::BookingNotFound,'Booking-not-found ontbreekt.');
$pdo->exec("INSERT INTO disabled_dates(datum,type,reden) VALUES('2026-10-05','blocked','Test')");
$confirmed=$make('Definitief','2026-10-06');$disabled=$service->change(new BookingVisitDateChangeCommand($confirmed,'2026-10-06','2026-10-05',1));$assert($disabled->code===BookingVisitDateChangeCode::OverrideRequired&&array_map(static fn($i)=>$i->code,$disabled->validationIssues)===['DISABLED_VISIT_DATE'],'Disabled-date overrideflow ontbreekt.');
$override=$service->change(new BookingVisitDateChangeCommand($confirmed,'2026-10-06','2026-10-05',1,[new BookingRuleOverrideRequest('DISABLED_VISIT_DATE','Planner accepteert bewust deze geblokkeerde bezoekdatum.')]));
$link=$pdo->query("SELECT status_history_id,booking_change_history_id FROM booking_rule_overrides WHERE booking_id={$confirmed}")->fetch();
$assert($override->code===BookingVisitDateChangeCode::Success&&$link['status_history_id']===null&&(int)$link['booking_change_history_id']===$override->changeHistoryId,'Mutatieoverride is niet uitsluitend aan change-history gekoppeld.');

$pdo->exec("INSERT INTO disabled_dates(datum,type,reden) VALUES('2026-07-20','blocked','Historische test')");
$draftHistorical=$make('In optie','2027-01-04');$draftHistoricalResult=$service->change(new BookingVisitDateChangeCommand($draftHistorical,'2027-01-04','2026-07-20',1,[]));
$assert($draftHistoricalResult->code===BookingVisitDateChangeCode::Success&&(int)$pdo->query("SELECT COUNT(*) FROM booking_day_settings WHERE visit_date='2026-07-20'")->fetchColumn()===1,'Draft verplaatst niet naar historische datum of gebruikt de centrale kalenderdatumlock niet.');
$confirmedHistorical=$make('Definitief','2027-01-05');$confirmedHistoricalResult=$service->change(new BookingVisitDateChangeCommand($confirmedHistorical,'2027-01-05','2026-07-20',1,[new BookingRuleOverrideRequest('DISABLED_VISIT_DATE','Planner wil alleen de disabled waarschuwing overschrijven.')]),new \DateTimeImmutable('2026-07-25'));
$assert($confirmedHistoricalResult->code===BookingVisitDateChangeCode::InvalidStoredBooking&&in_array('HISTORICAL_VISIT_DATE',array_map(static fn($i)=>$i->code,$confirmedHistoricalResult->validationIssues),true),'Historische datum is niet hard of wordt door override omzeild.');

$targetSchool='2027-09-06';$make('Definitief',$targetSchool);$make('Definitief',$targetSchool);$schoolMove=$make('Definitief','2027-09-07');
$schoolLimit=$service->change(new BookingVisitDateChangeCommand($schoolMove,'2027-09-07',$targetSchool,1));
$assert($schoolLimit->code===BookingVisitDateChangeCode::OverrideRequired&&in_array('SCHOOL_LIMIT_EXCEEDED',array_map(static fn($i)=>$i->code,$schoolLimit->validationIssues),true),'Schoollimiet wordt niet op de doeldatum berekend.');

$targetStudents='2027-09-13';$studentExisting=$make('Definitief',$targetStudents);$pdo->exec("UPDATE aanvragen SET aantal_leerlingen=130 WHERE id={$studentExisting}");$studentMove=$make('Definitief','2027-09-14');
$studentLimit=$service->change(new BookingVisitDateChangeCommand($studentMove,'2027-09-14',$targetStudents,1));
$assert($studentLimit->code===BookingVisitDateChangeCode::OverrideRequired&&in_array('STUDENT_LIMIT_EXCEEDED',array_map(static fn($i)=>$i->code,$studentLimit->validationIssues),true),'Totale leerlinglimiet gebruikt niet de voorgestelde doeldatum.');

$programMove=$make('Definitief','2027-09-14');$pdo->exec("UPDATE aanvragen SET programma='ochtend',aantal_leerlingen=81 WHERE id={$programMove}");
$programLimit=$service->change(new BookingVisitDateChangeCommand($programMove,'2027-09-14','2027-09-15',1));
$programCodes=array_map(static fn($i)=>$i->code,$programLimit->validationIssues);
$assert($programLimit->code===BookingVisitDateChangeCode::OverrideRequired&&in_array('PROGRAM_STUDENT_LIMIT_EXCEEDED',$programCodes,true)&&!in_array('STUDENT_LIMIT_EXCEEDED',$programCodes,true),'Programmalimiet is niet onafhankelijk op de proposed bookingstate gevalideerd.');

$sourceExclusion=$make('Definitief','2027-09-20');$targetExisting=$make('Definitief','2027-09-21');$pdo->exec("UPDATE aanvragen SET aantal_leerlingen=120 WHERE id={$targetExisting}");
$sourceExclusionResult=$service->change(new BookingVisitDateChangeCommand($sourceExclusion,'2027-09-20','2027-09-21',1));
$assert($sourceExclusionResult->code===BookingVisitDateChangeCode::Success,'Oude datum/current booking wordt onterecht bij doelcapaciteit opgeteld.');

$auditRollback=$make('In optie','2027-10-04');$pdo->exec("CREATE TRIGGER fail_visit_date_history BEFORE INSERT ON booking_change_history FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='forced history failure'");
$auditFailure=$service->change(new BookingVisitDateChangeCommand($auditRollback,'2027-10-04','2027-10-05',1));$pdo->exec("DROP TRIGGER fail_visit_date_history");
$assert($auditFailure->code===BookingVisitDateChangeCode::DatabaseError&&$pdo->query("SELECT bezoekdatum FROM aanvragen WHERE id={$auditRollback}")->fetchColumn()==='2027-10-04','Auditfout rolt de datumupdate niet volledig terug.');

$pdo->exec("INSERT INTO disabled_dates(datum,type,reden) VALUES('2027-10-18','blocked','Override rollback')");
$overrideRollback=$make('Definitief','2027-10-19');$pdo->exec("CREATE TRIGGER fail_visit_date_override BEFORE INSERT ON booking_rule_overrides FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='forced override failure'");
$overrideFailure=$service->change(new BookingVisitDateChangeCommand($overrideRollback,'2027-10-19','2027-10-18',1,[new BookingRuleOverrideRequest('DISABLED_VISIT_DATE','Planner accepteert deze datum voor de rollbacktest.')]));$pdo->exec("DROP TRIGGER fail_visit_date_override");
$assert($overrideFailure->code===BookingVisitDateChangeCode::DatabaseError&&$pdo->query("SELECT bezoekdatum FROM aanvragen WHERE id={$overrideRollback}")->fetchColumn()==='2027-10-19'&&(int)$pdo->query("SELECT COUNT(*) FROM booking_change_history WHERE booking_id={$overrideRollback}")->fetchColumn()===0,'Override-auditfout rolt update en change-history niet terug.');

$unrelatedInvalid=$make('Definitief','2027-10-25');$pdo->exec("UPDATE aanvragen SET cjpPasGebruik='ja',cjpContactpersoonNaam=NULL,cjpPasnummer=NULL,remise_lunch=50,eigen_picknick=1,voorwaarden_akkoord=0 WHERE id={$unrelatedInvalid}");
$unrelatedResult=$service->change(new BookingVisitDateChangeCommand($unrelatedInvalid,'2027-10-25','2027-10-26',1));
$assert($unrelatedResult->code===BookingVisitDateChangeCode::Success,'Oude CJP-, catering- of voorwaardenproblemen blokkeren de afgebakende datumwijziging.');

$twoConnectionBooking=$make('In optie','2027-11-01');
$pdo2=new \PDO('mysql:host='.$env('STATUS_TEST_DB_HOST','127.0.0.1').';port='.$env('STATUS_TEST_DB_PORT','3306').';dbname='.$env('STATUS_TEST_DB_NAME').';charset=utf8mb4',$env('STATUS_TEST_DB_USER','root'),$env('STATUS_TEST_DB_PASSWORD'),[\PDO::ATTR_ERRMODE=>\PDO::ERRMODE_EXCEPTION]);
$service2=(new BookingVisitDateChangeServiceFactory($pdo2))->create();
$firstWriter=$service->change(new BookingVisitDateChangeCommand($twoConnectionBooking,'2027-11-01','2027-11-02',1));
$staleWriter=$service2->change(new BookingVisitDateChangeCommand($twoConnectionBooking,'2027-11-01','2027-11-03',1));
$assert($firstWriter->code===BookingVisitDateChangeCode::Success&&$staleWriter->code===BookingVisitDateChangeCode::VisitDateConflict&&$pdo->query("SELECT bezoekdatum FROM aanvragen WHERE id={$twoConnectionBooking}")->fetchColumn()==='2027-11-02','Twee verbindingen veroorzaken een lost update.');

$onlyDate=$make('Afgewezen','2027-10-11');$beforeRow=$pdo->query("SELECT * FROM aanvragen WHERE id={$onlyDate}")->fetch();$onlyDateResult=$service->change(new BookingVisitDateChangeCommand($onlyDate,'2027-10-11','2027-10-12',1));$afterRow=$pdo->query("SELECT * FROM aanvragen WHERE id={$onlyDate}")->fetch();$beforeRow['bezoekdatum']=$afterRow['bezoekdatum'];
$assert($onlyDateResult->code===BookingVisitDateChangeCode::Success&&$beforeRow===$afterRow,'De datumflow wijzigt meer dan uitsluitend aanvragen.bezoekdatum.');
$assert((int)$pdo->query("SELECT COUNT(*) FROM booking_status_history")->fetchColumn()===0,'Datumwijziging schrijft statushistorie.');
fwrite(STDOUT,"OK: visit-date MariaDB-integratie geslaagd.\n");

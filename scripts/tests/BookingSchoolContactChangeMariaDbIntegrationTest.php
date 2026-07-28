<?php

declare(strict_types=1);

require dirname(__DIR__,2).'/vendor/autoload.php';

use GeoFort\Booking\SchoolContact\{BookingSchoolContactChangeCode,BookingSchoolContactChangeCommand,BookingSchoolContactDetails};
use GeoFort\Booking\Stored\StoredBookingAssembler;
use GeoFort\Services\Booking\SchoolContact\BookingSchoolContactChangeServiceFactory;

$env=[];foreach(['HOST','PORT','NAME','USER'] as $key){$value=getenv('STATUS_TEST_DB_'.$key);if($value===false){fwrite(STDERR,"FAIL: STATUS_TEST_DB_{$key} ontbreekt.\n");exit(2);}$env[$key]=(string)$value;}
if(getenv('STATUS_TEST_DB_CONFIRM')!=='YES_DISPOSABLE'||preg_match('/(test|tmp|scratch|disposable)/i',$env['NAME'])!==1||strtolower((string)getenv('APP_ENV'))==='production'){fwrite(STDERR,"FAIL: integratietest weigert niet-aantoonbaar wegwerpbare database.\n");exit(2);}
$pdo=new PDO(sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',$env['HOST'],$env['PORT'],$env['NAME']),$env['USER'],(string)(getenv('STATUS_TEST_DB_PASSWORD')?:''),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
$tables=['booking_change_history','aanvraag_onderwijs_selecties','aanvragen','admin_users'];$cleanup=static function()use($pdo,$tables):void{$pdo->exec('SET FOREIGN_KEY_CHECKS=0');try{foreach($tables as $table)$pdo->exec("DROP TABLE IF EXISTS {$table}");}finally{$pdo->exec('SET FOREIGN_KEY_CHECKS=1');}};$cleanup();$exit=0;
try{
$pdo->exec("CREATE TABLE aanvragen(id INT AUTO_INCREMENT PRIMARY KEY,status VARCHAR(20) NOT NULL,schoolnaam VARCHAR(255) NOT NULL,land VARCHAR(32) NOT NULL,adres VARCHAR(255) NOT NULL,postcode VARCHAR(16) NOT NULL,plaats VARCHAR(120) NOT NULL,school_telefoonnummer VARCHAR(25) NOT NULL,contactpersoon_telefoonnummer VARCHAR(25) NOT NULL,contactpersoon_voornaam VARCHAR(255) NOT NULL,contactpersoon_achternaam VARCHAR(255) NOT NULL,email VARCHAR(255) NOT NULL,bezoekdatum DATE NOT NULL,hoe_kent_u_geofort VARCHAR(120),opmerkingen TEXT,cjpPasGebruik VARCHAR(3) NOT NULL,cjpContactpersoonNaam VARCHAR(80),cjpPasnummer VARCHAR(9),onderwijs_sector VARCHAR(40) NOT NULL,programma VARCHAR(20) NOT NULL,keuzemodule_key VARCHAR(120),aantal_leerlingen INT,aantal_begeleiders INT,remise_break INT DEFAULT 0,kazerne_break INT DEFAULT 0,fortgracht_break INT DEFAULT 0,glas_limonade INT DEFAULT 0,waterijsje INT DEFAULT 0,remise_lunch INT DEFAULT 0,eigen_picknick TINYINT DEFAULT 1,voorwaarden_akkoord TINYINT DEFAULT 1,voorwaarden_akkoord_op DATETIME,source_system VARCHAR(80),source_record_id BIGINT,source_record_checksum CHAR(64),source_import_run_id BIGINT) ENGINE=InnoDB");
$pdo->exec("CREATE TABLE admin_users(id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,email VARCHAR(190),name VARCHAR(120),role VARCHAR(50),password_hash VARCHAR(255)) ENGINE=InnoDB");
$pdo->exec("CREATE TABLE aanvraag_onderwijs_selecties(id INT AUTO_INCREMENT PRIMARY KEY,aanvraag_id INT NOT NULL,sector_key VARCHAR(40),level_key VARCHAR(80),group_key VARCHAR(80),level_position INT,group_position INT) ENGINE=InnoDB");
$pdo->exec((string)file_get_contents(dirname(__DIR__,2).'/database/sql/2026-07-22_create_booking_change_history.sql'));
$pdo->exec("INSERT INTO admin_users(email,name,role,password_hash)VALUES('school@example.test','Planner','admin','x')");$admin=(int)$pdo->lastInsertId();
$insert=static function()use($pdo):int{$pdo->exec("INSERT INTO aanvragen(status,schoolnaam,land,adres,postcode,plaats,school_telefoonnummer,contactpersoon_telefoonnummer,contactpersoon_voornaam,contactpersoon_achternaam,email,bezoekdatum,cjpPasGebruik,onderwijs_sector,programma,keuzemodule_key,aantal_leerlingen,aantal_begeleiders)VALUES('Definitief','School','Nederland','Dijk 1','1234 AB','Plaats','010 123 4567','06 12345678','Jan','Jansen','jan@example.nl','2026-09-01','nee','primairOnderwijs','dag','Earth-Watch',40,5)");return(int)$pdo->lastInsertId();};
$old=new BookingSchoolContactDetails('School','Nederland','Dijk 1','1234 AB','Plaats','010 123 4567','Jan','Jansen','jan@example.nl','06 12345678');
$new=new BookingSchoolContactDetails('Nieuwe School','Nederland','Dijk 2','1234ab','Nieuweplaats','010 765 4321','José','de Vries','jose@example.nl','06 87654321');
$change=static fn(int $id,BookingSchoolContactDetails $expected,BookingSchoolContactDetails $proposed)=>(new BookingSchoolContactChangeServiceFactory($pdo))->create()->change(new BookingSchoolContactChangeCommand($id,$expected,$proposed,$admin));
$assert=static function(bool $condition,string $message):void{if(!$condition)throw new RuntimeException($message);};
$id=$insert();$result=$change($id,$old,$new);$row=$pdo->query("SELECT * FROM aanvragen WHERE id={$id}")->fetch();$assert($result->code===BookingSchoolContactChangeCode::Success&&$row['postcode']==='1234 AB'&&$row['status']==='Definitief'&&$row['programma']==='dag','Volledige update/normalisatie of mutationgrens faalt.');
$audit=$pdo->query("SELECT * FROM booking_change_history WHERE booking_id={$id}")->fetch();$fields=json_decode($audit['changed_fields_json'],true,512,JSON_THROW_ON_ERROR);$assert($audit['change_type']==='school_contact_details_changed'&&array_keys($fields)===['schoolnaam','adres','plaats','school_telefoonnummer','contactpersoon_voornaam','contactpersoon_achternaam','email','contactpersoon_telefoonnummer'],'Auditdiff is onjuist.');
$noop=$change($id,$result->current,$result->current);$assert($noop->code===BookingSchoolContactChangeCode::NoChange&&(int)$pdo->query("SELECT COUNT(*) FROM booking_change_history WHERE booking_id={$id}")->fetchColumn()===1,'No-op schrijft.');
$conflict=$change($id,$old,$new);$assert($conflict->code===BookingSchoolContactChangeCode::Conflict&&(int)$pdo->query("SELECT COUNT(*) FROM booking_change_history WHERE booking_id={$id}")->fetchColumn()===1,'Conflict schrijft.');
$invalid=new BookingSchoolContactDetails('','Nederland','Dijk 2','1234 AB','Plaats','010 123 4567','Jan','Jansen','jan@example.nl','06 12345678');$assert($change($id,$result->current,$invalid)->code===BookingSchoolContactChangeCode::InvalidDetails,'Validatiefout muteert.');
$pdo->exec('DROP TABLE booking_change_history');$rollbackId=$insert();$assert($change($rollbackId,$old,$new)->code===BookingSchoolContactChangeCode::DatabaseError&&$pdo->query("SELECT schoolnaam FROM aanvragen WHERE id={$rollbackId}")->fetchColumn()==='School','Auditfout rolt update niet terug.');
echo "BookingSchoolContactChangeMariaDbIntegrationTest OK\n";
}catch(Throwable $exception){fwrite(STDERR,'FAIL: '.$exception->getMessage()."\n");$exit=1;}finally{$cleanup();}exit($exit);

<?php
declare(strict_types=1);

$env=[];foreach(['HOST','PORT','NAME','USER'] as $key){$value=getenv('STATUS_TEST_DB_'.$key);if($value===false){fwrite(STDERR,"FAIL: STATUS_TEST_DB_{$key} ontbreekt.\n");exit(2);}$env[$key]=(string)$value;}
if(getenv('STATUS_TEST_DB_CONFIRM')!=='YES_DISPOSABLE'||preg_match('/(test|tmp|scratch|disposable)/i',$env['NAME'])!==1||strtolower((string)getenv('APP_ENV'))==='production'){fwrite(STDERR,"FAIL: migratietest weigert niet-aantoonbaar disposable database.\n");exit(2);}
$password=getenv('STATUS_TEST_DB_PASSWORD');$pdo=new PDO(sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',$env['HOST'],$env['PORT'],$env['NAME']),$env['USER'],$password===false?'':$password,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$tables=['booking_rule_overrides','booking_change_history','booking_status_history','booking_day_settings','aanvraag_onderwijs_selecties','disabled_dates','aanvragen','admin_users'];
$cleanup=static function()use($pdo,$tables):void{$pdo->exec('SET FOREIGN_KEY_CHECKS=0');try{foreach($tables as $table)$pdo->exec("DROP TABLE IF EXISTS {$table}");}finally{$pdo->exec('SET FOREIGN_KEY_CHECKS=1');}};
$cleanup();$exit=0;
try{
 $root=dirname(__DIR__,2).'/database/sql/';
 $pdo->exec('CREATE TABLE aanvragen(id INT NOT NULL AUTO_INCREMENT,PRIMARY KEY(id)) ENGINE=InnoDB');
 $pdo->exec('CREATE TABLE admin_users(id INT UNSIGNED NOT NULL AUTO_INCREMENT,PRIMARY KEY(id)) ENGINE=InnoDB');
 $pdo->exec("CREATE TABLE booking_status_history(id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,booking_id INT NOT NULL,previous_status ENUM('In optie','Definitief','Afgewezen') NOT NULL,new_status ENUM('In optie','Definitief','Afgewezen') NOT NULL,mail_mode ENUM('none','send') NOT NULL DEFAULT 'none',mail_sent TINYINT(1) NOT NULL DEFAULT 0,admin_user_id INT UNSIGNED NOT NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,PRIMARY KEY(id),FOREIGN KEY(booking_id) REFERENCES aanvragen(id),FOREIGN KEY(admin_user_id) REFERENCES admin_users(id)) ENGINE=InnoDB");
 $pdo->exec((string)file_get_contents($root.'2026-07-21_create_booking_rule_overrides.sql'));
 $pdo->exec('INSERT INTO aanvragen(id) VALUES(1)');$pdo->exec('INSERT INTO admin_users(id) VALUES(1)');
 $pdo->exec("INSERT INTO booking_status_history(id,booking_id,previous_status,new_status,admin_user_id) VALUES(1,1,'In optie','Definitief',1)");
 $pdo->exec("INSERT INTO booking_rule_overrides(id,booking_id,status_history_id,rule_code,reason,context_fingerprint,approved_by_admin_id) VALUES(1,1,1,'STUDENT_LIMIT_EXCEEDED','Bestaande statusoverride blijft behouden.',REPEAT('a',64),1)");
 $pdo->exec((string)file_get_contents($root.'2026-07-22_create_booking_change_history.sql'));
 $pdo->exec((string)file_get_contents($root.'2026-07-22_link_overrides_to_change_history.sql'));
 $status=$pdo->query('SELECT status_history_id,booking_change_history_id,reason FROM booking_rule_overrides WHERE id=1')->fetch();
 if((int)$status['status_history_id']!==1||$status['booking_change_history_id']!==null||$status['reason']!=='Bestaande statusoverride blijft behouden.')throw new RuntimeException('Bestaande statusoverride is niet intact of verkeerd gekoppeld.');
 $pdo->exec("INSERT INTO booking_change_history(id,booking_id,change_type,changed_fields_json,changed_by_admin_id) VALUES(1,1,'attendance_changed','{\"aantal_leerlingen\":{\"before\":80,\"after\":95}}',1)");
 $pdo->exec("INSERT INTO booking_rule_overrides(id,booking_id,status_history_id,booking_change_history_id,rule_code,reason,context_fingerprint,approved_by_admin_id) VALUES(2,1,NULL,1,'STUDENT_LIMIT_EXCEEDED','Attendanceoverride met geldige reden.',REPEAT('b',64),1)");
 $attendance=$pdo->query('SELECT status_history_id,booking_change_history_id FROM booking_rule_overrides WHERE id=2')->fetch();
 if($attendance['status_history_id']!==null||(int)$attendance['booking_change_history_id']!==1)throw new RuntimeException('Attendanceoverride is verkeerd gekoppeld.');
 foreach([[1,1],[null,null]] as [$statusId,$changeId]){try{$statement=$pdo->prepare("INSERT INTO booking_rule_overrides(booking_id,status_history_id,booking_change_history_id,rule_code,reason,context_fingerprint,approved_by_admin_id) VALUES(1,:statusId,:changeId,'X','Voldoende lange ongeldige reden.',REPEAT('c',64),1)");$statement->execute([':statusId'=>$statusId,':changeId'=>$changeId]);throw new RuntimeException('XOR-CHECK accepteerde ongeldige koppeling.');}catch(PDOException $exception){if($exception->getCode()==='00000')throw $exception;}}
 $pdo->exec((string)file_get_contents($root.'2026-07-22_rollback_link_overrides_to_change_history.sql'));
 $columnCount=(int)$pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='booking_rule_overrides' AND COLUMN_NAME='booking_change_history_id'")->fetchColumn();
 if($columnCount!==0)throw new RuntimeException('Rollback verwijderde overridekoppeling niet eerst.');
 $pdo->exec((string)file_get_contents($root.'2026-07-22_rollback_booking_change_history.sql'));
 $pdo->exec((string)file_get_contents($root.'2026-07-22_create_booking_change_history.sql'));
 $pdo->exec((string)file_get_contents($root.'2026-07-22_link_overrides_to_change_history.sql'));
 if((int)$pdo->query("SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME='booking_rule_overrides' AND CONSTRAINT_TYPE='CHECK'")->fetchColumn()<1)throw new RuntimeException('Recreate mist XOR-CHECK.');
 $cleanup();
 $remaining=(int)$pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('".implode("','",$tables)."')")->fetchColumn();
 if($remaining!==0)throw new RuntimeException('Migratietest laat tabellen of testrecords achter.');
 fwrite(STDOUT,"OK: migraties, status-/attendancekoppelingen, XOR-afwijzingen, rollback, recreate en cleanup geslaagd.\n");
}catch(Throwable $e){fwrite(STDERR,'FAIL: migratietestfout: '.$e->getMessage()."\n");$exit=1;}finally{$cleanup();}
exit($exit);

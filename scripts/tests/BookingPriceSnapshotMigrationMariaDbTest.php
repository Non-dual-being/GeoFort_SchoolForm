<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/vendor/autoload.php';
Dotenv\Dotenv::createImmutable(dirname(__DIR__,2))->safeLoad();
$env=static fn(string $key,string $default=''):string=>(string)($_ENV[$key]??$_SERVER[$key]??getenv($key)?:$default);
if(strtolower($env('APP_ENV'))==='production'){fwrite(STDERR,"SKIP: productiomgeving wordt nooit gebruikt voor migratietests.\n");exit(2);}
$host=$env('DB_HOST');$port=$env('DB_PORT','3306');$user=$env('DB_USER');
if($host===''||$user===''){fwrite(STDERR,"SKIP: databaseverbinding ontbreekt.\n");exit(2);}
$database='geofort_price_snapshot_disposable_test_'.bin2hex(random_bytes(4));
$pdo=new PDO("mysql:host={$host};port={$port};charset=utf8mb4",$user,$env('DB_PASSWORD'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
$quoted='`'.str_replace('`','``',$database).'`';
try{
    $pdo->exec("CREATE DATABASE {$quoted} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo->exec("USE {$quoted}");
    $pdo->exec('CREATE TABLE admin_users(id INT UNSIGNED NOT NULL PRIMARY KEY) ENGINE=InnoDB');
    $pdo->exec('INSERT INTO admin_users(id) VALUES(1)');
    $pdo->exec("CREATE TABLE aanvragen(
      id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,status ENUM('In optie','Definitief','Afgewezen') NOT NULL DEFAULT 'In optie',schoolnaam VARCHAR(255) NOT NULL,
      land VARCHAR(32) NOT NULL,adres VARCHAR(255) NOT NULL,postcode VARCHAR(16) NOT NULL,plaats VARCHAR(120) NOT NULL,school_telefoonnummer VARCHAR(25) NOT NULL,
      contactpersoon_telefoonnummer VARCHAR(25) NOT NULL,contactpersoon_voornaam VARCHAR(255) NOT NULL,contactpersoon_achternaam VARCHAR(255) NOT NULL,email VARCHAR(255) NOT NULL,
      bezoekdatum DATE NOT NULL,hoe_kent_u_geofort VARCHAR(120),opmerkingen TEXT,cjpPasGebruik ENUM('ja','nee') NOT NULL DEFAULT 'nee',cjpContactpersoonNaam VARCHAR(80),cjpPasnummer VARCHAR(9),
      onderwijs_sector VARCHAR(40) NOT NULL,programma VARCHAR(20) NOT NULL,keuzemodule_key VARCHAR(120),aantal_leerlingen INT UNSIGNED,aantal_begeleiders INT UNSIGNED,
      remise_break INT UNSIGNED NOT NULL DEFAULT 0,kazerne_break INT UNSIGNED NOT NULL DEFAULT 0,fortgracht_break INT UNSIGNED NOT NULL DEFAULT 0,glas_limonade INT UNSIGNED NOT NULL DEFAULT 0,
      waterijsje INT UNSIGNED NOT NULL DEFAULT 0,remise_lunch INT UNSIGNED NOT NULL DEFAULT 0,eigen_picknick TINYINT(1) NOT NULL DEFAULT 0,voorwaarden_akkoord TINYINT(1) NOT NULL DEFAULT 0,
      voorwaarden_akkoord_op DATETIME,source_system VARCHAR(80),source_record_id BIGINT UNSIGNED,source_record_checksum CHAR(64),source_import_run_id BIGINT UNSIGNED
    ) ENGINE=InnoDB");
    $pdo->exec('CREATE TABLE aanvraag_onderwijs_selecties(id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,aanvraag_id INT NOT NULL,sector_key VARCHAR(40),level_key VARCHAR(80),group_key VARCHAR(80),level_position INT,group_position INT,FOREIGN KEY(aanvraag_id) REFERENCES aanvragen(id) ON DELETE CASCADE) ENGINE=InnoDB');
    $insertBooking=$pdo->prepare("INSERT INTO aanvragen(status,schoolnaam,land,adres,postcode,plaats,school_telefoonnummer,contactpersoon_telefoonnummer,contactpersoon_voornaam,contactpersoon_achternaam,email,bezoekdatum,cjpPasGebruik,onderwijs_sector,programma,aantal_leerlingen,aantal_begeleiders,eigen_picknick,voorwaarden_akkoord,source_system) VALUES(:status,'Bestaande boeking','Nederland','Dijk 1','1234 AB','Plaats','1','2','Voor','Naam','test@example.test','2026-09-01','nee','primairOnderwijs','dag',10,2,1,1,:source)");
    foreach([['In optie',null],['In optie','legacy_geoform'],['Definitief','legacy_geoform']] as [$status,$source]){$insertBooking->execute([':status'=>$status,':source'=>$source]);$bookingId=(int)$pdo->lastInsertId();$pdo->exec("INSERT INTO aanvraag_onderwijs_selecties(aanvraag_id,sector_key,level_key,group_key,level_position,group_position) VALUES({$bookingId},'primairOnderwijs','regulier','groep5',1,1)");}
    $migration=file_get_contents(dirname(__DIR__,2).'/database/sql/2026-08-03_create_booking_price_snapshots.sql');
    if($migration===false)throw new RuntimeException('Migration ontbreekt.');
    $pdo->exec($migration);
    $pdo2=new PDO("mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4",$user,$env('DB_PASSWORD'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
    $version=(string)$pdo->query('SELECT VERSION()')->fetchColumn();
    if(!str_contains(strtolower($version),'mariadb'))throw new RuntimeException("Test vereist MariaDB; gevonden: {$version}");
    $columns=$pdo->query('SHOW COLUMNS FROM booking_price_snapshots')->fetchAll(PDO::FETCH_ASSOC);
    $types=array_column($columns,'Type','Field');
    foreach(['visit_amount_incl_vat_cents','catering_amount_incl_vat_cents','total_amount_incl_vat_cents','total_amount_excl_vat_cents','vat_amount_cents'] as $column)if(!str_starts_with(strtolower((string)($types[$column]??'')),'bigint'))throw new RuntimeException("{$column} is geen integerkolom.");
    if((int)$pdo->query('SELECT COUNT(*) FROM booking_price_snapshots')->fetchColumn()!==0)throw new RuntimeException('Bestaande boeking is automatisch gebackfilld.');
    $repository=new GeoFort\Services\Sql\BookingPriceSnapshotSqlRepository($pdo);
    $service=new GeoFort\Services\Booking\Pricing\BookingPriceSnapshotService($repository,new GeoFort\Services\Booking\Pricing\BookingPriceCalculator());
    $food=GeoFort\Services\Booking\Data\FoodAndDrinkSelectionData::fromStoredValues(0,0,0,0,0,0,true);
    $input=new GeoFort\Services\Booking\Pricing\BookingPricingInput('primairOnderwijs','dag',10,2,$food);
    $pdo->beginTransaction();
    $first=$service->appendUsingActiveVersion(1,$input,GeoFort\Services\Booking\Pricing\BookingPriceSnapshot::REASON_SUBMISSION);
    $second=$service->appendUsingExistingVersion(1,$input,GeoFort\Services\Booking\Pricing\BookingPriceSnapshot::REASON_CONFIRMATION);
    $pdo->commit();
    if($first->sequenceNumber!==1||$second->sequenceNumber!==2||$repository->latest(1)?->sequenceNumber!==2||count($repository->history(1))!==2)throw new RuntimeException('Append-only sequence/latest/history is onjuist.');
    $expectDatabaseRejection=static function(PDO $connection,string $sql,string $message):void{
        try{$connection->exec($sql);throw new RuntimeException($message);}catch(PDOException){}
    };
    $expectDatabaseRejection($pdo,"INSERT INTO booking_price_snapshots(booking_id,sequence_number,snapshot_reason,calculation_state,canonical_input_json,calculation_details_json,input_checksum,checksum_format_version) VALUES(1,2,'legacy_unavailable_at_confirmation','historical_price_unavailable','{}','{}','".str_repeat('a',64)."',1)",'Dubbel sequence-nummer geaccepteerd.');
    $expectDatabaseRejection($pdo,"INSERT INTO booking_price_snapshots(booking_id,sequence_number,snapshot_reason,calculation_state,canonical_input_json,calculation_details_json,input_checksum,checksum_format_version) VALUES(1,51,'submission','onbekend','{}','{}','".str_repeat('a',64)."',1)",'Ongeldige calculation state geaccepteerd.');
    $expectDatabaseRejection($pdo,"INSERT INTO booking_price_snapshots(booking_id,sequence_number,snapshot_reason,calculation_state,pricing_version,currency_code,vat_meaning,vat_basis_points,canonical_input_json,calculation_details_json,input_checksum,checksum_format_version) VALUES(1,52,'submission','complete','catalog-v1','EUR','inclusive',900,'{}','{}','".str_repeat('a',64)."',1)",'Complete snapshot zonder bedragen geaccepteerd.');
    $expectDatabaseRejection($pdo,"INSERT INTO booking_price_snapshots(booking_id,sequence_number,snapshot_reason,calculation_state,pricing_version,currency_code,vat_meaning,vat_basis_points,visit_amount_incl_vat_cents,catering_amount_incl_vat_cents,total_amount_incl_vat_cents,total_amount_excl_vat_cents,vat_amount_cents,canonical_input_json,calculation_details_json,input_checksum,checksum_format_version) VALUES(1,53,'submission','complete','catalog-v1','EUR','inclusive',900,100,50,149,137,12,'{}','{}','".str_repeat('a',64)."',1)",'Ongeldige bezoek/catering-totaalinvariant geaccepteerd.');
    $expectDatabaseRejection($pdo,"INSERT INTO booking_price_snapshots(booking_id,sequence_number,snapshot_reason,calculation_state,canonical_input_json,calculation_details_json,input_checksum,checksum_format_version) VALUES(1,54,'legacy_unavailable_at_confirmation','historical_price_unavailable','{','{}','".str_repeat('a',64)."',1)",'Ongeldige JSON geaccepteerd.');
    $expectDatabaseRejection($pdo,"INSERT INTO booking_price_snapshots(booking_id,sequence_number,snapshot_reason,calculation_state,canonical_input_json,calculation_details_json,input_checksum,checksum_format_version) VALUES(999999,1,'legacy_unavailable_at_confirmation','historical_price_unavailable','{}','{}','".str_repeat('a',64)."',1)",'Onbekende booking-id geaccepteerd.');
    $expectDatabaseRejection($pdo,'DELETE FROM aanvragen WHERE id=1','Foreign-key RESTRICT liet financiële historie verwijderen.');

    // Twee sessies bewijzen dat de bookingrow-lock een concurrerende schrijver blokkeert.
    $pdo->beginTransaction();
    $pdo->query('SELECT id FROM aanvragen WHERE id=1 FOR UPDATE')->fetchColumn();
    $pdo2->exec('SET SESSION innodb_lock_wait_timeout=1');
    $pdo2->beginTransaction();
    try{$pdo2->query('SELECT id FROM aanvragen WHERE id=1 FOR UPDATE');throw new RuntimeException('Tweede verbinding verkreeg dezelfde bookingrow-lock.');}catch(PDOException $exception){if((int)($exception->errorInfo[1]??0)!==1205)throw $exception;}finally{if($pdo2->inTransaction())$pdo2->rollBack();if($pdo->inTransaction())$pdo->rollBack();}

    // Een stale tweede schrijver krijgt via de repository een veilige conflictcode.
    $factory=new GeoFort\Services\Booking\Pricing\BookingPriceSnapshotFactory();
    $quote=(new GeoFort\Services\Booking\Pricing\BookingPriceCalculator())->calculateInput($input,GeoFort\Services\Booking\Pricing\BookingPriceCatalogRegistry::INITIAL_2026_VERSION);
    $latest=$repository->latest(1)??throw new RuntimeException('Laatste snapshot ontbreekt.');
    $candidate=$factory->complete(1,3,$latest->id,GeoFort\Services\Booking\Pricing\BookingPriceSnapshot::REASON_PLANNER_UPDATE,$input,$quote,null);
    $pdo->beginTransaction();$repository->append($candidate);$pdo->commit();
    $repository2=new GeoFort\Services\Sql\BookingPriceSnapshotSqlRepository($pdo2);
    $pdo2->beginTransaction();
    try{$repository2->append($candidate);throw new RuntimeException('Stale schrijver werd niet geweigerd.');}catch(RuntimeException $exception){if($exception->getMessage()!=='PRICE_SNAPSHOT_CONFLICT')throw $exception;}finally{if($pdo2->inTransaction())$pdo2->rollBack();}
    if($repository->latest(1)?->sequenceNumber!==3||count($repository->history(1))!==3)throw new RuntimeException('Conflict heeft de append-only historie beschadigd.');

    $legacyService=new GeoFort\Services\Booking\Pricing\LegacyBookingPriceAcceptanceService($pdo,new GeoFort\Services\Sql\StoredBookingSqlRepository($pdo,new GeoFort\Booking\Stored\StoredBookingAssembler()),$service);
    try{$legacyService->accept(2,1,false);throw new RuntimeException('Legacyacceptatie zonder expliciete toestemming geaccepteerd.');}catch(RuntimeException $exception){if($exception->getMessage()!=='LEGACY_PRICE_ACCEPTANCE_REQUIRED')throw $exception;}
    if($repository->latest(2)!==null)throw new RuntimeException('Geannuleerde legacyacceptatie schreef een snapshot.');
    $accepted=$legacyService->accept(2,1,true);
    if($accepted->reason!==GeoFort\Services\Booking\Pricing\BookingPriceSnapshot::REASON_LEGACY_ACCEPTANCE||$accepted->pricingVersion!==GeoFort\Services\Booking\Pricing\BookingPriceCatalogRegistry::INITIAL_2026_VERSION)throw new RuntimeException('Legacyacceptatie gebruikte niet de actieve versie/reason.');
    try{$legacyService->accept(2,1,true);throw new RuntimeException('Dubbele legacyacceptatie geaccepteerd.');}catch(RuntimeException $exception){if($exception->getMessage()!=='PRICE_SNAPSHOT_CONFLICT')throw $exception;}
    if(count($repository->history(2))!==1)throw new RuntimeException('Dubbele legacyacceptatie maakte conflicterende snapshots.');
    try{$legacyService->accept(3,1,true);throw new RuntimeException('Reeds definitieve legacyboeking automatisch geprijsd.');}catch(RuntimeException $exception){if($exception->getMessage()!=='LEGACY_PRICE_ACCEPTANCE_NOT_ALLOWED')throw $exception;}
    if($repository->latest(3)!==null)throw new RuntimeException('Definitieve legacyboeking kreeg een automatische snapshot.');

    // Dezelfde transactionele primitives als submission: booking en eerste snapshot committen of rollen samen terug.
    $pdo->beginTransaction();$insertBooking->execute([':status'=>'In optie',':source'=>null]);$submittedId=(int)$pdo->lastInsertId();$service->appendUsingActiveVersion($submittedId,$input,GeoFort\Services\Booking\Pricing\BookingPriceSnapshot::REASON_SUBMISSION);$pdo->commit();
    if((int)$pdo->query("SELECT COUNT(*) FROM aanvragen WHERE id={$submittedId}")->fetchColumn()!==1||$repository->latest($submittedId)?->reason!==GeoFort\Services\Booking\Pricing\BookingPriceSnapshot::REASON_SUBMISSION)throw new RuntimeException('Booking/submission-snapshot zijn niet samen opgeslagen.');
    $pdo->exec("CREATE TRIGGER fail_submission_snapshot BEFORE INSERT ON booking_price_snapshots FOR EACH ROW IF NEW.snapshot_reason='submission' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='test submission snapshot failure'; END IF");
    $submissionFailureObserved=false;
    try{$pdo->beginTransaction();$insertBooking->execute([':status'=>'In optie',':source'=>null]);$failedSubmissionId=(int)$pdo->lastInsertId();$service->appendUsingActiveVersion($failedSubmissionId,$input,GeoFort\Services\Booking\Pricing\BookingPriceSnapshot::REASON_SUBMISSION);$pdo->commit();}catch(RuntimeException){$submissionFailureObserved=true;if($pdo->inTransaction())$pdo->rollBack();}finally{$pdo->exec('DROP TRIGGER fail_submission_snapshot');}
    if(!$submissionFailureObserved)throw new RuntimeException('Geforceerde submission-snapshotfout bleef uit.');
    if((int)$pdo->query("SELECT COUNT(*) FROM aanvragen WHERE id={$failedSubmissionId}")->fetchColumn()!==0||$repository->latest($failedSubmissionId)!==null)throw new RuntimeException('Snapshotfout liet een halve publieke submission staan.');

    // Plannerupdate en snapshot delen een transactie; een snapshotfout herstelt ook de bookinginput.
    $pdo->exec("CREATE TRIGGER fail_planner_snapshot BEFORE INSERT ON booking_price_snapshots FOR EACH ROW IF NEW.snapshot_reason='planner_update' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='test planner snapshot failure'; END IF");
    $plannerFailureObserved=false;
    try{$pdo->beginTransaction();$pdo->query('SELECT id FROM aanvragen WHERE id=1 FOR UPDATE')->fetchColumn();$pdo->exec('UPDATE aanvragen SET aantal_leerlingen=11 WHERE id=1');$changedInput=new GeoFort\Services\Booking\Pricing\BookingPricingInput('primairOnderwijs','dag',11,2,$food);$service->appendUsingExistingVersion(1,$changedInput,GeoFort\Services\Booking\Pricing\BookingPriceSnapshot::REASON_PLANNER_UPDATE,1);$pdo->commit();}catch(RuntimeException){$plannerFailureObserved=true;if($pdo->inTransaction())$pdo->rollBack();}finally{$pdo->exec('DROP TRIGGER fail_planner_snapshot');}
    if(!$plannerFailureObserved)throw new RuntimeException('Geforceerde planner-snapshotfout bleef uit.');
    if((int)$pdo->query('SELECT aantal_leerlingen FROM aanvragen WHERE id=1')->fetchColumn()!==10||count($repository->history(1))!==3)throw new RuntimeException('Planner-snapshotfout liet booking of snapshot half gewijzigd achter.');
    $rollback=file_get_contents(dirname(__DIR__,2).'/database/sql/2026-08-03_rollback_booking_price_snapshots.sql');
    if($rollback===false)throw new RuntimeException('Rollback ontbreekt.');
    $pdo->exec($rollback);
    if((int)$pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='booking_price_snapshots'")->fetchColumn()!==0)throw new RuntimeException('Rollback verwijderde tabel niet.');
    echo "Booking price snapshot migration MariaDB test passed.\n";
}finally{
    $pdo->exec('USE information_schema');
    $pdo->exec("DROP DATABASE IF EXISTS {$quoted}");
}

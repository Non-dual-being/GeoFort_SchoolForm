<?php
declare(strict_types=1);

use GeoFort\Booking\Attendance\BookingAttendanceChangeCode;
use GeoFort\Booking\Attendance\BookingAttendanceChangeCommand;
use GeoFort\Booking\BookingPolicy;
use GeoFort\Booking\BookingProgramConfig;
use GeoFort\Booking\Rules\BookingRuleOverrideRequest;
use GeoFort\Services\Booking\Attendance\BookingAttendanceChangeService;
use GeoFort\Services\Booking\Attendance\BookingAttendanceChangeServiceFactory;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$env = [];
foreach (['HOST', 'PORT', 'NAME', 'USER'] as $key) {
    $value = getenv('STATUS_TEST_DB_' . $key);
    if ($value === false) {
        fwrite(STDERR, "FAIL: STATUS_TEST_DB_{$key} ontbreekt.\n");
        exit(2);
    }
    $env[$key] = (string) $value;
}
if (getenv('STATUS_TEST_DB_CONFIRM') !== 'YES_DISPOSABLE' || preg_match('/(test|tmp|scratch|disposable)/i', $env['NAME']) !== 1 || strtolower((string) getenv('APP_ENV')) === 'production') {
    fwrite(STDERR, "FAIL: integratietest weigert niet-aantoonbaar wegwerpbare database.\n");
    exit(2);
}
$connect = static function () use ($env): PDO {
    $password = getenv('STATUS_TEST_DB_PASSWORD');
    return new PDO(sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $env['HOST'], $env['PORT'], $env['NAME']), $env['USER'], $password === false ? '' : $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
};
$pdo = $connect();
$tables = ['booking_rule_overrides', 'booking_change_history', 'booking_status_history', 'booking_day_settings', 'aanvraag_onderwijs_selecties', 'disabled_dates', 'aanvragen', 'admin_users'];
$cleanup = static function () use ($pdo, $tables): void {
    $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
    try {
        foreach ($tables as $table) $pdo->exec("DROP TABLE IF EXISTS {$table}");
    } finally {
        $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
    }
};
$cleanup();
$exitCode = 0;
try {
    $pdo->exec("CREATE TABLE aanvragen (
      id INT NOT NULL AUTO_INCREMENT, status ENUM('In optie','Definitief','Afgewezen') NOT NULL,
      schoolnaam VARCHAR(255) NOT NULL, land VARCHAR(32) NOT NULL, adres VARCHAR(255) NOT NULL, postcode VARCHAR(16) NOT NULL, plaats VARCHAR(120) NOT NULL,
      school_telefoonnummer VARCHAR(25) NOT NULL, contactpersoon_telefoonnummer VARCHAR(25) NOT NULL, contactpersoon_voornaam VARCHAR(255) NOT NULL,
      contactpersoon_achternaam VARCHAR(255) NOT NULL, email VARCHAR(255) NOT NULL, bezoekdatum DATE NOT NULL, hoe_kent_u_geofort VARCHAR(120), opmerkingen TEXT,
      cjpPasGebruik ENUM('ja','nee') NOT NULL DEFAULT 'nee', cjpContactpersoonNaam VARCHAR(80), cjpPasnummer VARCHAR(9),
      onderwijs_sector VARCHAR(40) NOT NULL, programma VARCHAR(20) NOT NULL, keuzemodule_key VARCHAR(120), aantal_leerlingen INT UNSIGNED, aantal_begeleiders INT UNSIGNED,
      remise_break INT UNSIGNED NOT NULL DEFAULT 0, kazerne_break INT UNSIGNED NOT NULL DEFAULT 0, fortgracht_break INT UNSIGNED NOT NULL DEFAULT 0,
      glas_limonade INT UNSIGNED NOT NULL DEFAULT 0, waterijsje INT UNSIGNED NOT NULL DEFAULT 0, remise_lunch INT UNSIGNED NOT NULL DEFAULT 0,
      eigen_picknick TINYINT(1) NOT NULL DEFAULT 0, voorwaarden_akkoord TINYINT(1) NOT NULL DEFAULT 1, voorwaarden_akkoord_op DATETIME,
      source_system VARCHAR(80), source_record_id BIGINT UNSIGNED, source_record_checksum CHAR(64), source_import_run_id BIGINT UNSIGNED,
      PRIMARY KEY(id), KEY idx_date_status(bezoekdatum,status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci");
    $pdo->exec("CREATE TABLE admin_users (id INT UNSIGNED NOT NULL AUTO_INCREMENT,email VARCHAR(190) NOT NULL,name VARCHAR(120) NOT NULL,role VARCHAR(50) NOT NULL DEFAULT 'admin',password_hash VARCHAR(255) NOT NULL,is_active TINYINT(1) NOT NULL DEFAULT 1,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,PRIMARY KEY(id),UNIQUE KEY(email)) ENGINE=InnoDB");
    $pdo->exec('CREATE TABLE aanvraag_onderwijs_selecties (id INT NOT NULL AUTO_INCREMENT,aanvraag_id INT NOT NULL,sector_key VARCHAR(40),level_key VARCHAR(80),group_key VARCHAR(80),level_position INT,group_position INT,PRIMARY KEY(id),FOREIGN KEY(aanvraag_id) REFERENCES aanvragen(id) ON DELETE CASCADE) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE disabled_dates (datum DATE NOT NULL PRIMARY KEY,type VARCHAR(30),reden VARCHAR(255)) ENGINE=InnoDB');
    $sqlRoot = dirname(__DIR__, 2) . '/database/sql/';
    foreach (['2026-07-17_create_booking_day_settings.sql', '2026-07-18_create_booking_status_history.sql', '2026-07-21_create_booking_rule_overrides.sql', '2026-07-22_create_booking_change_history.sql', '2026-07-22_link_overrides_to_change_history.sql'] as $migration) $pdo->exec((string) file_get_contents($sqlRoot . $migration));
    $pdo->exec("INSERT INTO admin_users(email,name,password_hash) VALUES ('attendance@example.test','Attendance Admin','test')");
    $adminId = (int) $pdo->lastInsertId();
    $sector = 'primairOnderwijs';
    $program = BookingPolicy::PROGRAM_DAY;
    $minimum = BookingProgramConfig::getMinStudentsForSelection($sector, $program);
    $safeStudents = max(20, $minimum);
    $insert = static function (string $status, string $date, ?int $students = null, ?int $supervisors = null, bool $normalized = true) use ($pdo, $sector, $program, $safeStudents): int {
        $students ??= $safeStudents;
        $supervisors ??= BookingPolicy::getMinimumSupervisorCount($students);
        $statement = $pdo->prepare("INSERT INTO aanvragen(status,schoolnaam,land,adres,postcode,plaats,school_telefoonnummer,contactpersoon_telefoonnummer,contactpersoon_voornaam,contactpersoon_achternaam,email,bezoekdatum,cjpPasGebruik,onderwijs_sector,programma,keuzemodule_key,aantal_leerlingen,aantal_begeleiders,eigen_picknick,voorwaarden_akkoord,voorwaarden_akkoord_op) VALUES (:status,'Testschool','Nederland','Dijk 1','1234 AB','Teststad','0123456789','0612345678','Jan','Jansen','jan@example.test',:date,'nee',:sector,:program,'Earth-Watch',:students,:supervisors,1,1,'2026-07-20 10:00:00')");
        $statement->execute([':status'=>$status, ':date'=>$date, ':sector'=>$sector, ':program'=>$program, ':students'=>$students, ':supervisors'=>$supervisors]);
        $id = (int) $pdo->lastInsertId();
        if ($normalized) $pdo->prepare("INSERT INTO aanvraag_onderwijs_selecties(aanvraag_id,sector_key,level_key,group_key,level_position,group_position) VALUES (:id,'primairOnderwijs','regulier','groep5',1,1)")->execute([':id'=>$id]);
        return $id;
    };
    $service = static fn(PDO $connection): BookingAttendanceChangeService => (new BookingAttendanceChangeServiceFactory($connection))->create();
    $change = static function (int $id, int $expectedStudents, int $expectedSupervisors, int $students, int $supervisors, array $overrides = [], ?PDO $connection = null) use ($service, $pdo, $adminId) {
        return $service($connection ?? $pdo)->change(new BookingAttendanceChangeCommand($id, $expectedStudents, $expectedSupervisors, $students, $supervisors, $adminId, $overrides), new DateTimeImmutable('2026-07-22'));
    };
    $row = static fn(int $id): array => $pdo->query("SELECT status,aantal_leerlingen,aantal_begeleiders FROM aanvragen WHERE id={$id}")->fetch();
    $auditCount = static fn(int $id): int => (int) $pdo->query("SELECT COUNT(*) FROM booking_change_history WHERE booking_id={$id}")->fetchColumn();
    $overrideCount = static fn(int $id): int => (int) $pdo->query("SELECT COUNT(*) FROM booking_rule_overrides WHERE booking_id={$id}")->fetchColumn();
    $failures = [];
    $assert = static function (bool $condition, string $message) use (&$failures): void { if (!$condition) $failures[] = $message; };

    $optionStudents = $insert(BookingPolicy::STATUS_OPTION, '2026-09-01');
    $result = $change($optionStudents, $safeStudents, BookingPolicy::getMinimumSupervisorCount($safeStudents), $safeStudents + 1, BookingPolicy::getMinimumSupervisorCount($safeStudents));
    $assert($result->code === BookingAttendanceChangeCode::Success && $row($optionStudents)['status'] === BookingPolicy::STATUS_OPTION, 'In optie: alleen leerlingen/status faalt.');
    $optionSupervisors = $insert(BookingPolicy::STATUS_OPTION, '2026-09-02');
    $oldSupervisor = BookingPolicy::getMinimumSupervisorCount($safeStudents);
    $assert($change($optionSupervisors, $safeStudents, $oldSupervisor, $safeStudents, $oldSupervisor + 1)->code === BookingAttendanceChangeCode::Success, 'In optie: alleen begeleiders faalt.');
    $optionBoth = $insert(BookingPolicy::STATUS_OPTION, '2026-09-03');
    $assert($change($optionBoth, $safeStudents, $oldSupervisor, $safeStudents + 2, $oldSupervisor + 1)->code === BookingAttendanceChangeCode::Success, 'In optie: beide aantallen faalt.');
    $rejected = $insert(BookingPolicy::STATUS_REJECTED, '2026-09-04');
    $assert($change($rejected, $safeStudents, $oldSupervisor, $safeStudents + 3, 0)->code === BookingAttendanceChangeCode::Success && $row($rejected)['status'] === BookingPolicy::STATUS_REJECTED && $overrideCount($rejected) === 0, 'Afgewezen wijziging/warning/status/overrideaudit faalt.');
    $noChanges = $insert(BookingPolicy::STATUS_OPTION, '2026-09-05');
    $assert($change($noChanges, $safeStudents, $oldSupervisor, $safeStudents, $oldSupervisor)->code === BookingAttendanceChangeCode::NoChanges && $auditCount($noChanges) === 0, 'NO_CHANGES schrijft of retourneert verkeerd.');
    foreach ([[0,$oldSupervisor],[-1,$oldSupervisor],[$safeStudents,-1]] as $index => [$students,$supervisors]) {
        $id=$insert(BookingPolicy::STATUS_OPTION, '2026-09-'.(string)(6+$index));
        $assert($change($id,$safeStudents,$oldSupervisor,$students,$supervisors)->code===BookingAttendanceChangeCode::InvalidRequest && $auditCount($id)===0, 'Negatieve/nul technische validatie faalt.');
    }
    $conflictStudents=$insert(BookingPolicy::STATUS_OPTION,'2026-09-10');
    $assert($change($conflictStudents,$safeStudents+1,$oldSupervisor,$safeStudents+2,$oldSupervisor)->code===BookingAttendanceChangeCode::AttendanceConflict,'Expected student conflict faalt.');
    $conflictSupervisors=$insert(BookingPolicy::STATUS_OPTION,'2026-09-11');
    $assert($change($conflictSupervisors,$safeStudents,$oldSupervisor+1,$safeStudents+2,$oldSupervisor)->code===BookingAttendanceChangeCode::AttendanceConflict,'Expected supervisor conflict faalt.');
    $confirmed=$insert(BookingPolicy::STATUS_CONFIRMED,'2026-10-01');
    $newConfirmedStudents=$safeStudents+1;$newConfirmedSupervisors=BookingPolicy::getMinimumSupervisorCount($newConfirmedStudents);
    $confirmedResult=$change($confirmed,$safeStudents,$oldSupervisor,$newConfirmedStudents,$newConfirmedSupervisors);
    $assert($confirmedResult->code===BookingAttendanceChangeCode::Success && $row($confirmed)['status']===BookingPolicy::STATUS_CONFIRMED,'Definitieve veilige wijziging/status faalt: '.$confirmedResult->code->value.' issues='.implode(',',array_map(static fn($i)=>$i->code.':'.$i->field,$confirmedResult->validationIssues)));
    $lowSupervisors=$insert(BookingPolicy::STATUS_CONFIRMED,'2026-10-02',64,4);
    $required=$change($lowSupervisors,64,4,65,3);
    $assert($required->code===BookingAttendanceChangeCode::OverrideRequired && $auditCount($lowSupervisors)===0,'Minimum begeleiders vereist geen override of muteert: '.$required->code->value.' issues='.implode(',',array_map(static fn($i)=>$i->code.':'.$i->field,$required->validationIssues)));
    $supervisorOverride=new BookingRuleOverrideRequest('MINIMUM_SUPERVISORS_NOT_MET','Planner accepteert bewust minder begeleiders.');
    $supervisorOverrideResult=$change($lowSupervisors,64,4,65,3,[$supervisorOverride]);
    $assert($supervisorOverrideResult->code===BookingAttendanceChangeCode::Success,'Begeleidersoverride slaagt niet: '.$supervisorOverrideResult->code->value);
    $capacityDate='2026-10-05';
    $other=$insert(BookingPolicy::STATUS_CONFIRMED,$capacityDate,70,BookingPolicy::getMinimumSupervisorCount(70));
    $capacityBooking=$insert(BookingPolicy::STATUS_CONFIRMED,$capacityDate,80,BookingPolicy::getMinimumSupervisorCount(80));
    $capacityRequired=$change($capacityBooking,80,5,95,6);
    $capacityIssue=array_values(array_filter($capacityRequired->validationIssues,static fn($i):bool=>$i->code==='STUDENT_LIMIT_EXCEEDED'))[0]??null;
    $assert($capacityRequired->code===BookingAttendanceChangeCode::OverrideRequired && $capacityRequired->capacity?->confirmedStudentsExcludingBooking===70 && $capacityRequired->capacity?->projectedStudents===165,'Capaciteit/exclusie/projectie faalt.');
    $assert(($capacityIssue?->metadata['previousBookingStudents']??null)===80 && ($capacityIssue?->metadata['proposedBookingStudents']??null)===95,'Capaciteitsmetadata faalt.');
    $capacityOverride=new BookingRuleOverrideRequest('STUDENT_LIMIT_EXCEEDED','Planner accepteert bewust vijf extra leerlingen.');
    $assert($change($capacityBooking,80,5,95,6,[$capacityOverride])->code===BookingAttendanceChangeCode::Success,'Capaciteitsoverride faalt.');
    $hard=$insert(BookingPolicy::STATUS_CONFIRMED,'2026-10-06',null,null,false);
    $assert($change($hard,$safeStudents,$oldSupervisor,$safeStudents+1,$oldSupervisor)->code===BookingAttendanceChangeCode::InvalidStoredBooking,'Harde stored-bookingregel blokkeert niet.');
    $historical=$insert(BookingPolicy::STATUS_CONFIRMED,'2026-07-20');
    $assert($change($historical,$safeStudents,$oldSupervisor,$safeStudents+1,$oldSupervisor)->code===BookingAttendanceChangeCode::InvalidStoredBooking,'Historische datum blokkeert niet.');
    $disabled=$insert(BookingPolicy::STATUS_CONFIRMED,'2026-10-07');$pdo->exec("INSERT INTO disabled_dates(datum,type,reden) VALUES ('2026-10-07','blocked','Test')");
    $disabledRequired=$change($disabled,$safeStudents,$oldSupervisor,$safeStudents+1,$oldSupervisor);
    $assert($disabledRequired->code===BookingAttendanceChangeCode::OverrideRequired,'Disabled date gebruikt overridepolicy niet.');
    $disabledOverride=new BookingRuleOverrideRequest('DISABLED_VISIT_DATE','Planner accepteert bewust deze geblokkeerde datum.');
    $assert($change($disabled,$safeStudents,$oldSupervisor,$safeStudents+1,$oldSupervisor,[$disabledOverride])->code===BookingAttendanceChangeCode::Success,'Disabled-dateoverride faalt.');
    $stale=$insert(BookingPolicy::STATUS_CONFIRMED,'2026-10-08');
    $assert($change($stale,$safeStudents,$oldSupervisor,$safeStudents+1,$oldSupervisor,[$capacityOverride])->code===BookingAttendanceChangeCode::InvalidOverrideRequest,'Stale override wordt niet geweigerd.');
    $unknown=new BookingRuleOverrideRequest('UNKNOWN_RULE','Planner geeft een geldige maar onbekende reden.');
    $assert($change($stale,$safeStudents,$oldSupervisor,$safeStudents+1,$oldSupervisor,[$unknown])->code===BookingAttendanceChangeCode::OverrideNotAllowed,'Onbekende override wordt niet geweigerd.');
    $duplicate=$insert(BookingPolicy::STATUS_CONFIRMED,'2026-10-09',64,4);
    $assert($change($duplicate,64,4,65,3,[$supervisorOverride,$supervisorOverride])->code===BookingAttendanceChangeCode::InvalidOverrideRequest,'Dubbele overridecode wordt niet geweigerd.');
    try { new BookingRuleOverrideRequest('MINIMUM_SUPERVISORS_NOT_MET','te kort'); $assert(false,'Te korte reden geaccepteerd.'); } catch (InvalidArgumentException) {}

    $audit=$pdo->query("SELECT changed_fields_json,change_type,changed_by_admin_id FROM booking_change_history WHERE booking_id={$optionStudents}")->fetch();
    $fields=json_decode((string)$audit['changed_fields_json'],true,512,JSON_THROW_ON_ERROR);
    $assert(array_keys($fields)===['aantal_leerlingen'] && $fields['aantal_leerlingen']['before']===$safeStudents && $fields['aantal_leerlingen']['after']===$safeStudents+1 && $audit['change_type']==='attendance_changed' && (int)$audit['changed_by_admin_id']===$adminId,'Veldgerichte before/after/change_type/admin audit faalt: '.json_encode($fields));
    $overrideAudit=$pdo->query("SELECT status_history_id,booking_change_history_id,context_fingerprint,metadata_json FROM booking_rule_overrides WHERE booking_id={$capacityBooking}")->fetch();
    $metadata=json_decode((string)$overrideAudit['metadata_json'],true,512,JSON_THROW_ON_ERROR);
    $assert($overrideAudit['status_history_id']===null && (int)$overrideAudit['booking_change_history_id']>0 && preg_match('/^[a-f0-9]{64}$/',(string)$overrideAudit['context_fingerprint'])===1 && $metadata['confirmedStudentsExcludingBooking']===70 && $metadata['projectedStudents']===165,'Overridekoppeling/fingerprint/metadata faalt.');
    $assert((int)$pdo->query('SELECT COUNT(*) FROM booking_status_history')->fetchColumn()===0,'Attendance change schrijft status-history.');

    $historyFailure=$insert(BookingPolicy::STATUS_OPTION,'2026-09-19');
    $pdo->exec("CREATE TRIGGER fail_change_history BEFORE INSERT ON booking_change_history FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='forced history failure'");
    $assert($change($historyFailure,$safeStudents,$oldSupervisor,$safeStudents+1,$oldSupervisor)->code===BookingAttendanceChangeCode::DatabaseError && (int)$row($historyFailure)['aantal_leerlingen']===$safeStudents && $auditCount($historyFailure)===0,'Historyfout rolt update niet terug.');
    $pdo->exec('DROP TRIGGER fail_change_history');
    $overrideFailure=$insert(BookingPolicy::STATUS_CONFIRMED,'2026-10-12',64,4);
    $pdo->exec("CREATE TRIGGER fail_override_audit BEFORE INSERT ON booking_rule_overrides FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='forced override failure'");
    $assert($change($overrideFailure,64,4,65,3,[$supervisorOverride])->code===BookingAttendanceChangeCode::DatabaseError && (int)$row($overrideFailure)['aantal_leerlingen']===64 && $auditCount($overrideFailure)===0 && $overrideCount($overrideFailure)===0,'Overrideauditfout rolt update/history niet terug.');
    $pdo->exec('DROP TRIGGER fail_override_audit');
    $updateFailure=$insert(BookingPolicy::STATUS_OPTION,'2026-09-21');
    $pdo->exec("CREATE TRIGGER fail_attendance_update BEFORE UPDATE ON aanvragen FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='forced update failure'");
    $assert($change($updateFailure,$safeStudents,$oldSupervisor,$safeStudents+1,$oldSupervisor)->code===BookingAttendanceChangeCode::DatabaseError && (int)$row($updateFailure)['aantal_leerlingen']===$safeStudents && $auditCount($updateFailure)===0,'Updatefout rolt niet volledig terug.');
    $pdo->exec('DROP TRIGGER fail_attendance_update');

    $lostUpdate=$insert(BookingPolicy::STATUS_OPTION,'2026-09-22');
    $connectionA=$connect();$connectionB=$connect();
    $connectionB->prepare('UPDATE aanvragen SET aantal_leerlingen=:students WHERE id=:id')->execute([':students'=>$safeStudents+4,':id'=>$lostUpdate]);
    $lostResult=$change($lostUpdate,$safeStudents,$oldSupervisor,$safeStudents+5,$oldSupervisor,[],$connectionA);
    $assert($lostResult->code===BookingAttendanceChangeCode::AttendanceConflict && (int)$row($lostUpdate)['aantal_leerlingen']===$safeStudents+4 && $auditCount($lostUpdate)===0,'Twee connecties/guarded update voorkomen lost update niet.');
    $reflection=new ReflectionClass(BookingAttendanceChangeService::class);
    $assert(!str_contains((string) file_get_contents($reflection->getFileName()),'MailSender') && !str_contains((string) file_get_contents($reflection->getFileName()),'mailMode'),'Attendance service bevat een mailactie.');

    if ($failures !== []) { foreach ($failures as $failure) fwrite(STDERR,"FAIL: {$failure}\n"); $exitCode=1; }
    else fwrite(STDOUT,"OK: 40 attendance service-, audit-, rollback-, capaciteit- en concurrency-scenario's geslaagd.\n");
} catch (Throwable $exception) {
    fwrite(STDERR,'FAIL: onverwachte attendance-integratietestfout: '.$exception->getMessage()."\n");
    $exitCode=1;
} finally {
    $cleanup();
}
exit($exitCode);

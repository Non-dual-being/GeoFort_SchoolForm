<?php
declare(strict_types=1);

use GeoFort\Services\Sql\BookingCalendarSqlService;
use GeoFort\Services\Sql\BookingDaySettingsSqlRepository;
use GeoFort\Services\Sql\StoredBookingSqlRepository;
use GeoFort\Booking\Stored\StoredBookingAssembler;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$required = ['HOST','PORT','NAME','USER']; $env=[];
foreach ($required as $key) { $value=getenv('STATUS_TEST_DB_'.$key); if ($value===false) { fwrite(STDOUT,"SKIP: geen expliciete STATUS_TEST_DB_* disposable databaseconfiguratie.\n"); exit(0); } $env[$key]=(string)$value; }
if (getenv('STATUS_TEST_DB_CONFIRM') !== 'YES_DISPOSABLE' || preg_match('/(test|tmp|scratch|disposable)/i',$env['NAME']) !== 1 || strtolower((string)getenv('APP_ENV')) === 'production') { fwrite(STDERR,"FAIL: integratietest weigert niet-aantoonbaar wegwerpbare database.\n"); exit(2); }
$password=getenv('STATUS_TEST_DB_PASSWORD');
$pdo=new PDO(sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',$env['HOST'],$env['PORT'],$env['NAME']),$env['USER'],$password===false?'':$password,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
$cleanup=static function () use ($pdo): void {
    $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
    try {
        foreach (['booking_rule_overrides','booking_status_history','booking_day_settings','aanvraag_onderwijs_selecties','disabled_dates','aanvragen','admin_users'] as $table) {
            $pdo->exec("DROP TABLE IF EXISTS {$table}");
        }
    } finally {
        $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
    }
};
$cleanup();
$exitCode=0;
try {
$pdo->exec('CREATE TABLE aanvragen (id INT PRIMARY KEY AUTO_INCREMENT, status VARCHAR(20), schoolnaam VARCHAR(255), land VARCHAR(32), adres VARCHAR(255), postcode VARCHAR(16), plaats VARCHAR(120), school_telefoonnummer VARCHAR(25), contactpersoon_telefoonnummer VARCHAR(25), contactpersoon_voornaam VARCHAR(255), contactpersoon_achternaam VARCHAR(255), email VARCHAR(255), bezoekdatum DATE, hoe_kent_u_geofort VARCHAR(120), opmerkingen TEXT, cjpPasGebruik VARCHAR(3), cjpContactpersoonNaam VARCHAR(80), cjpPasnummer VARCHAR(9), onderwijs_sector VARCHAR(40), programma VARCHAR(20), keuzemodule_key VARCHAR(120), aantal_leerlingen INT, aantal_begeleiders INT, remise_break INT, kazerne_break INT, fortgracht_break INT, glas_limonade INT, waterijsje INT, remise_lunch INT, eigen_picknick TINYINT, voorwaarden_akkoord TINYINT, voorwaarden_akkoord_op DATETIME, source_system VARCHAR(80), source_record_id BIGINT UNSIGNED, source_record_checksum CHAR(64), source_import_run_id BIGINT UNSIGNED) ENGINE=InnoDB');
$pdo->exec('CREATE TABLE aanvraag_onderwijs_selecties (id INT PRIMARY KEY AUTO_INCREMENT, aanvraag_id INT NOT NULL, sector_key VARCHAR(40), level_key VARCHAR(80), group_key VARCHAR(80), level_position INT, group_position INT, FOREIGN KEY (aanvraag_id) REFERENCES aanvragen(id)) ENGINE=InnoDB');
$pdo->exec('CREATE TABLE booking_day_settings (visit_date DATE PRIMARY KEY, max_schools_override INT NULL, max_students_override INT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL) ENGINE=InnoDB');
$pdo->exec("INSERT INTO aanvragen(bezoekdatum,status,aantal_leerlingen) VALUES ('2026-09-02','Definitief',40),('2026-09-02','In optie',100),('2026-09-02','Afgewezen',100)");
$calendar=new BookingCalendarSqlService($pdo); $all=$calendar->getBookingStatsForDate('2026-09-02'); $excluded=$calendar->getBookingStatsForDate('2026-09-02',1);
if ($all!==['bookedSchools'=>1,'bookedStudents'=>40] || $excluded!==['bookedSchools'=>0,'bookedStudents'=>0]) throw new RuntimeException('Definitieve totalen of aanvraag-exclusie kloppen niet.');
$locks=new BookingDaySettingsSqlRepository($pdo); try { $locks->lockDate('2026-09-02'); throw new RuntimeException('Lock buiten transactie is toegestaan.'); } catch (RuntimeException $e) { if (!str_contains($e->getMessage(),'transactie')) throw $e; }
$pdo->beginTransaction(); $settings=$locks->lockDate('2026-09-02'); if ($settings->visitDate!=='2026-09-02') throw new RuntimeException('Datumlock leverde verkeerde rij.'); $pdo->rollBack();
$pdo->exec("INSERT INTO aanvragen (status,schoolnaam,land,adres,postcode,plaats,school_telefoonnummer,contactpersoon_telefoonnummer,contactpersoon_voornaam,contactpersoon_achternaam,email,bezoekdatum,cjpPasGebruik,onderwijs_sector,programma,keuzemodule_key,aantal_leerlingen,aantal_begeleiders,remise_break,kazerne_break,fortgracht_break,glas_limonade,waterijsje,remise_lunch,eigen_picknick,voorwaarden_akkoord) VALUES ('In optie','Lockschool','Nederland','Dijk 1','1234 AB','Teststad','0123456789','0612345678','Jan','Jansen','jan@example.test','2026-09-03','nee','primairOnderwijs','dag','Earth-Watch',40,5,0,0,0,0,0,0,1,1)");
$bookingId=(int)$pdo->lastInsertId();
$pdo->exec("INSERT INTO aanvraag_onderwijs_selecties (aanvraag_id,sector_key,level_key,group_key,level_position,group_position) VALUES ({$bookingId},'primairOnderwijs','regulier','groep5',1,1)");
$stored=new StoredBookingSqlRepository($pdo,new StoredBookingAssembler());
$pdo->beginTransaction();
if ($stored->findByIdForUpdate(999999)!==null) throw new RuntimeException('Onbekend locked ID geeft niet null.');
$locked=$stored->findByIdForUpdate($bookingId);
if ($locked===null||$locked->id!==$bookingId||$locked->educationSelection->selectedGroupsByLevel['regulier']!==['groep5']) throw new RuntimeException('Bestaande locked aanvraag is niet opgebouwd.');
$pdo->rollBack();
fwrite(STDOUT,"OK: dagtotalen, exclusie en transactielock geslaagd.\n");
} catch (Throwable $exception) {
    fwrite(STDERR,'FAIL: onverwachte integratietestfout: '.$exception->getMessage()."\n");
    $exitCode=1;
} finally {
    $cleanup();
}
exit($exitCode);

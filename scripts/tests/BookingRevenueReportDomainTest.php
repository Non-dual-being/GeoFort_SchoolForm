<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use GeoFort\Services\Dashboard\Booking\Revenue\BookingRevenueCriteriaFactory;
use GeoFort\Services\Dashboard\Booking\Revenue\BookingRevenueReportService;
use GeoFort\Services\Sql\BookingRevenueReportSqlRepository;
use GeoFort\Validation\FieldValidationException;

$assert = static function(bool $condition,string $message):void { if(!$condition) throw new RuntimeException($message); };
$pdo=new PDO('sqlite::memory:',options:[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$pdo->exec('CREATE TABLE aanvragen(id INTEGER PRIMARY KEY,status TEXT,schoolnaam TEXT,bezoekdatum TEXT,onderwijs_sector TEXT,aantal_leerlingen INTEGER)');
$pdo->exec('CREATE TABLE booking_price_snapshots(id INTEGER PRIMARY KEY,booking_id INTEGER,sequence_number INTEGER,calculation_state TEXT,visit_amount_incl_vat_cents INTEGER,catering_amount_incl_vat_cents INTEGER,total_amount_incl_vat_cents INTEGER,total_amount_excl_vat_cents INTEGER,vat_amount_cents INTEGER)');
$booking=$pdo->prepare('INSERT INTO aanvragen VALUES(?,?,?,?,?,?)');
foreach([[1,'Definitief','PO begin','2026-08-01','primairOnderwijs',20],[2,'In optie','VO midden','2026-08-15','voortgezetOnderbouw',30],[3,'Afgewezen','VO eind','2026-08-31','voortgezetBovenbouw',40],[4,'Definitief','Geen prijs','2026-08-16','primairOnderwijs',10],[5,'Definitief','Historisch','2026-08-17','primairOnderwijs',5],[6,'In optie','Ongeldig','2026-08-18','voortgezetOnderbouw',6],[7,'Definitief','Buiten','2026-09-01','primairOnderwijs',99],[8,'Definitief','Zelfde dag','2026-08-01','primairOnderwijs',2]] as $row)$booking->execute($row);
$snapshot=$pdo->prepare('INSERT INTO booking_price_snapshots VALUES(?,?,?,?,?,?,?,?,?)');
foreach([[1,1,1,'complete',1000,200,1200,1100,100],[2,1,2,'complete',2000,300,2300,2100,200],[3,2,1,'complete',3000,400,3400,3100,300],[4,3,1,'complete',4000,500,4500,4100,400],[5,5,1,'historical_price_unavailable',null,null,null,null,null],[6,6,1,'invalid_input',null,null,null,null,null],[7,8,1,'complete',500,100,600,550,50]] as $row)$snapshot->execute($row);
$factory=new BookingRevenueCriteriaFactory();
$criteria=$factory->create(['startDate'=>'2026-08-01','endDate'=>'2026-08-31']);
$report=(new BookingRevenueReportService(new BookingRevenueReportSqlRepository($pdo)))->report($criteria);
$assert($report->counts===['bookingsTotal'=>7,'definitive'=>4,'option'=>2,'rejected'=>1,'missingPrice'=>3,'studentsTotal'=>113,'studentsPrimary'=>37,'studentsSecondary'=>76],'Aantallen, inclusieve grenzen of PO/VO-totalen onjuist.');
$assert($report->definitiveRevenue===['visitInclVatCents'=>2500,'cateringInclVatCents'=>400,'totalInclVatCents'=>2900,'totalExclVatCents'=>2650,'vatCents'=>250],'Definitieve centtotalen of nieuwste snapshot onjuist.');
$assert($report->potentialRevenue===['visitInclVatCents'=>3000,'cateringInclVatCents'=>400,'totalInclVatCents'=>3400,'totalExclVatCents'=>3100,'vatCents'=>300],'Potentiële centtotalen onjuist.');
$assert(array_column($report->bookings,'id')===[1,8,2,4,5,6,3],'Sortering is niet bezoekdatum plus booking-id.');
$missing=array_values(array_filter($report->bookings,static fn(array $row):bool=>$row['id']===4))[0];
$assert($missing['amounts']===null&&$missing['snapshotState']==='missing','Ontbrekende prijs wordt als nul of verkeerde status gepresenteerd.');
$assert($report->bookings[0]['snapshotSequence']===2&&$report->bookings[0]['amounts']['totalInclVatCents']===2300,'Niet uitsluitend nieuwste sequence geselecteerd.');
$empty=(new BookingRevenueReportService(new BookingRevenueReportSqlRepository($pdo)))->report($factory->create(['startDate'=>'2025-01-01','endDate'=>'2025-01-31']));
$assert($empty->counts['bookingsTotal']===0&&$empty->bookings===[]&&array_sum($empty->definitiveRevenue)===0,'Lege periode is niet leeg.');
try{$factory->create(['startDate'=>'2026-08-02','endDate'=>'2026-08-01']);throw new RuntimeException('Omgekeerde periode geaccepteerd.');}catch(FieldValidationException $e){$assert($e->getField()==='endDate','Periodefout niet veldgericht.');}
foreach([['startDate'=>'2026-02-30','endDate'=>'2026-03-01'],['startDate'=>'','endDate'=>'2026-03-01']] as $invalid){try{$factory->create($invalid);throw new RuntimeException('Ongeldige ISO-datum geaccepteerd.');}catch(FieldValidationException){}}
echo "Booking revenue report domain tests passed.\n";

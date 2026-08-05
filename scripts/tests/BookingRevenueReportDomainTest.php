<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use GeoFort\Services\Dashboard\Booking\Revenue\BookingRevenueCriteriaFactory;
use GeoFort\Services\Dashboard\Booking\Revenue\BookingRevenueReportService;
use GeoFort\Services\Dashboard\Booking\Export\BookingExportDateBounds;
use GeoFort\Services\Sql\BookingRevenueReportSqlRepository;
use GeoFort\Validation\FieldValidationException;

$assert = static function(bool $condition,string $message):void { if(!$condition) throw new RuntimeException($message); };
$pdo=new PDO('sqlite::memory:',options:[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$pdo->exec('CREATE TABLE aanvragen(id INTEGER PRIMARY KEY,status TEXT,schoolnaam TEXT,bezoekdatum TEXT,onderwijs_sector TEXT,aantal_leerlingen INTEGER,programma TEXT DEFAULT "dag")');
$pdo->exec('CREATE TABLE booking_price_snapshots(id INTEGER PRIMARY KEY,booking_id INTEGER,sequence_number INTEGER,calculation_state TEXT,visit_amount_incl_vat_cents INTEGER,catering_amount_incl_vat_cents INTEGER,total_amount_incl_vat_cents INTEGER,total_amount_excl_vat_cents INTEGER,vat_amount_cents INTEGER)');
$booking=$pdo->prepare('INSERT INTO aanvragen(id,status,schoolnaam,bezoekdatum,onderwijs_sector,aantal_leerlingen) VALUES(?,?,?,?,?,?)');
foreach([[1,'Definitief','PO begin','2026-08-01','primairOnderwijs',20],[2,'In optie','VO midden','2026-08-15','voortgezetOnderbouw',30],[3,'Afgewezen','VO eind','2026-08-31','voortgezetBovenbouw',40],[4,'Definitief','Geen prijs','2026-08-16','primairOnderwijs',10],[5,'Definitief','Historisch','2026-08-17','primairOnderwijs',5],[6,'In optie','Ongeldig','2026-08-18','voortgezetOnderbouw',6],[7,'Definitief','Buiten','2026-09-01','primairOnderwijs',99],[8,'Definitief','Zelfde dag','2026-08-01','primairOnderwijs',2]] as $row)$booking->execute($row);
$snapshot=$pdo->prepare('INSERT INTO booking_price_snapshots VALUES(?,?,?,?,?,?,?,?,?)');
foreach([[1,1,1,'complete',1000,200,1200,1100,100],[2,1,2,'complete',2000,300,2300,2100,200],[3,2,1,'complete',3000,400,3400,3100,300],[4,3,1,'complete',4000,500,4500,4100,400],[5,5,1,'historical_price_unavailable',null,null,null,null,null],[6,6,1,'invalid_input',null,null,null,null,null],[7,8,1,'complete',500,100,600,550,50]] as $row)$snapshot->execute($row);
$factory=new BookingRevenueCriteriaFactory();
$criteria=$factory->create(['startDate'=>'2026-08-01','endDate'=>'2026-08-31']);
$repository=new BookingRevenueReportSqlRepository($pdo);
$service=new BookingRevenueReportService($repository);
$bounds=$repository->findAvailableVisitDateRange();
$report=$service->report($criteria,$bounds);
$assert($report->counts===['bookingsTotal'=>4,'definitive'=>4,'option'=>0,'rejected'=>0,'missingPrice'=>2,'studentsTotal'=>37,'studentsPrimary'=>37,'studentsSecondary'=>0],'Definitieve scope of aantallen onjuist.');
$assert($report->definitiveRevenue===['visitInclVatCents'=>2500,'cateringInclVatCents'=>400,'totalInclVatCents'=>2900,'totalExclVatCents'=>2650,'vatCents'=>250],'Definitieve centtotalen of nieuwste snapshot onjuist.');
$assert(array_sum($report->potentialRevenue)===0,'Definitieve scope bevat potentiële omzet.');
$page=$service->page($criteria);$assert(array_column($page['items'],'id')===[1,8,4,5],'Sortering is niet bezoekdatum plus booking-id.');
$missing=array_values(array_filter($page['items'],static fn(array $row):bool=>$row['id']===4))[0];
$assert($missing['amounts']===null&&$missing['snapshotState']==='missing','Ontbrekende prijs wordt als nul of verkeerde status gepresenteerd.');
$assert($page['items'][0]['snapshotSequence']===2&&$page['items'][0]['amounts']['totalInclVatCents']===2300,'Niet uitsluitend nieuwste sequence geselecteerd.');
$option=$service->report($factory->create(['startDate'=>'2026-08-01','endDate'=>'2026-08-31','revenueScope'=>'option']),$bounds);
$assert($option->counts['bookingsTotal']===2&&$option->counts['option']===2&&$option->counts['definitive']===0&&$option->potentialRevenue['totalInclVatCents']===3400,'Optiescope onjuist.');
$combined=$service->report($factory->create(['startDate'=>'2026-08-01','endDate'=>'2026-08-31','revenueScope'=>'combined']),$bounds);
$assert($combined->counts['bookingsTotal']===6&&$combined->counts['rejected']===0&&$combined->counts['studentsTotal']===73,'Prognose bevat verkeerde of dubbele boekingen.');
$assert($factory->create(['startDate'=>'2026-08-01','endDate'=>'2026-08-31','revenueScope'=>'bad'])->scope==='definitive','Ongeldige scope valt niet veilig terug.');
for($id=9;$id<=28;$id++)$booking->execute([$id,'Definitief','Pagina '.$id,'2026-08-20','primairOnderwijs',1]);
$pagedCriteria=$factory->create(['startDate'=>'2026-08-01','endDate'=>'2026-08-31','page'=>'2']);$secondPage=$service->page($pagedCriteria);
$assert($secondPage['pagination']['perPage']===10&&$secondPage['pagination']['currentPage']===2&&count($secondPage['items'])===10,'Pagina 2 bevat niet exact resultaten 11-20.');
$assert($secondPage['pagination']['totalItems']===24&&$secondPage['pagination']['from']===11&&$secondPage['pagination']['to']===20,'Paginatotalen zijn ten onrechte tot de pagina beperkt.');
$assert(count($service->exportRows($pagedCriteria))===24,'CSV-selectie is ten onrechte beperkt tot de actieve pagina.');
$tooHigh=$service->page($factory->create(['startDate'=>'2026-08-01','endDate'=>'2026-08-31','page'=>'999']));$assert($tooHigh['pagination']['currentPage']===3,'Te hoog paginanummer wordt niet veilig begrensd.');
$empty=$service->report($factory->create(['startDate'=>'2025-01-01','endDate'=>'2025-01-31']),$bounds);
$assert($empty->counts['bookingsTotal']===0&&$empty->bookings===[]&&array_sum($empty->definitiveRevenue)===0,'Lege periode is niet leeg.');
try{$factory->create(['startDate'=>'2026-08-02','endDate'=>'2026-08-01']);throw new RuntimeException('Omgekeerde periode geaccepteerd.');}catch(FieldValidationException $e){$assert($e->getField()==='endDate','Periodefout niet veldgericht.');}
foreach([['startDate'=>'2026-02-30','endDate'=>'2026-03-01'],['startDate'=>'','endDate'=>'2026-03-01']] as $invalid){try{$factory->create($invalid);throw new RuntimeException('Ongeldige ISO-datum geaccepteerd.');}catch(FieldValidationException){}}
$assert($bounds->minDate==='2026-08-01'&&$bounds->maxDate==='2026-09-01','Actieve kalendergrenzen zijn niet onafhankelijk van rapportperiode of sluiten afgewezen niet uit.');
$normalized=$factory->create(['startDate'=>'2024-01-01','endDate'=>'2028-01-01'],$bounds);
$assert($normalized->startDate==='2026-08-01'&&$normalized->endDate==='2026-09-01','Gedeeltelijk buitenbereik wordt niet begrensd.');
$reset=$factory->create(['startDate'=>'2028-01-01','endDate'=>'2028-02-01'],$bounds);
$assert($reset->startDate==='2026-08-01'&&$reset->endDate==='2026-09-01','Volledig buitenbereik wordt niet veilig gereset.');
$reversedOutside=$factory->create(['startDate'=>'2028-02-01','endDate'=>'2028-01-01'],$bounds);
$assert($reversedOutside->startDate==='2026-08-01'&&$reversedOutside->endDate==='2026-09-01','Omgekeerde buitenbereikperiode wordt niet veilig gereset.');
$reversedWithin=$factory->create(['startDate'=>'2026-08-20','endDate'=>'2026-08-10'],$bounds);
$assert($reversedWithin->startDate==='2026-08-01'&&$reversedWithin->endDate==='2026-09-01','Omgekeerde periode binnen de grenzen wordt niet veilig gereset.');
$default=$factory->create([],$bounds);
$assert($default->startDate===$bounds->minDate&&$default->endDate===$bounds->maxDate,'Ontbrekende periode gebruikt niet het volledige beschikbare bereik.');
$pdo->exec("UPDATE aanvragen SET bezoekdatum=NULL WHERE status IN ('Definitief','In optie')");
$emptyBounds=$repository->findAvailableVisitDateRange();
$assert($emptyBounds->isEmpty(),'Lege actieve planningspopulatie retourneert geen null-grenzen.');
$emptyReport=$service->emptyReport(new BookingExportDateBounds(null,null));
$assert($emptyReport->bookings===[]&&$emptyReport->period->startDate===''&&$emptyReport->availableVisitDateRange->minDate===null,'Leeg rapport verzint een periode.');
echo "Booking revenue report domain tests passed.\n";

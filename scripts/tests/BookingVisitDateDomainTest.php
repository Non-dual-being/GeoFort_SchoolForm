<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/vendor/autoload.php';

use GeoFort\Booking\Stored\StoredBookingAssembler;
use GeoFort\Booking\Validation\BookingValidationProfile;
use GeoFort\Booking\Validation\StoredBookingValidator;
use GeoFort\Booking\Validation\StoredBookingVisitDateValidator;
use GeoFort\Services\Sql\DisabledDatesSqlService;
use GeoFort\Services\Http\Api\Admin\BookingVisitDateUpdateRequest;
use GeoFort\Services\Http\Api\Admin\BookingVisitDateUpdateRequestException;

$assert=static function(bool $condition,string $message):void{if(!$condition)throw new RuntimeException($message);};
$valid=BookingVisitDateUpdateRequest::fromJson('{"bookingId":1,"expected":{"visitDate":"2026-09-14"},"proposed":{"visitDate":"2026-09-21"},"overrides":[]}');
$assert($valid->proposedVisitDate==='2026-09-21','Geldige ISO-datum is niet geaccepteerd.');
foreach(['2026-02-30','21-09-2026','2026-9-21','2026-09-21T00:00:00Z'] as $date){try{BookingVisitDateUpdateRequest::fromJson(json_encode(['bookingId'=>1,'expected'=>['visitDate'=>'2026-09-14'],'proposed'=>['visitDate'=>$date],'overrides'=>[]],JSON_THROW_ON_ERROR));throw new RuntimeException("Ongeldige datum geaccepteerd: {$date}");}catch(BookingVisitDateUpdateRequestException $e){$assert($e->publicCode==='INVALID_VISIT_DATE','Verkeerde datumfoutcode.');}}
$row=['id'=>7,'status'=>'In optie','schoolnaam'=>'School','land'=>'Nederland','adres'=>'Dijk 1','postcode'=>'1234 AB','plaats'=>'Plaats','school_telefoonnummer'=>'1','contactpersoon_telefoonnummer'=>'2','contactpersoon_voornaam'=>'Jan','contactpersoon_achternaam'=>'Jansen','email'=>'jan@example.test','bezoekdatum'=>'2026-09-14','hoe_kent_u_geofort'=>null,'opmerkingen'=>"regel\nregel",'cjpPasGebruik'=>'nee','cjpContactpersoonNaam'=>null,'cjpPasnummer'=>null,'onderwijs_sector'=>'primairOnderwijs','programma'=>'dag','keuzemodule_key'=>'Earth-Watch','aantal_leerlingen'=>40,'aantal_begeleiders'=>4,'remise_break'=>1,'kazerne_break'=>2,'fortgracht_break'=>3,'glas_limonade'=>4,'waterijsje'=>5,'remise_lunch'=>0,'eigen_picknick'=>1,'voorwaarden_akkoord'=>1,'voorwaarden_akkoord_op'=>'2026-07-01 10:00:00','source_system'=>null,'source_record_id'=>null,'source_record_checksum'=>null,'source_import_run_id'=>null];
$booking=(new StoredBookingAssembler())->assemble($row,[]);
$changed=$booking->withVisitDate('2026-09-21');
$beforeProperties=get_object_vars($booking);$afterProperties=get_object_vars($changed);unset($beforeProperties['visitDate'],$afterProperties['visitDate']);
$assert($changed!==$booking&&$changed->visitDate==='2026-09-21'&&$afterProperties===$beforeProperties,'withVisitDate behoudt niet alle overige state of is niet immutable.');
$assert(BookingValidationProfile::ChangeVisitDateDraft!==BookingValidationProfile::ChangeVisitDateConfirmed,'Datumprofielen zijn niet gescheiden.');
$pdo=new PDO('sqlite::memory:');$pdo->exec('CREATE TABLE disabled_dates (datum TEXT PRIMARY KEY,type TEXT,reden TEXT)');$pdo->exec("INSERT INTO disabled_dates(datum,type,reden) VALUES('2026-09-21','blocked','Test')");
$disabledDates=new DisabledDatesSqlService($pdo);$today=new DateTimeImmutable('2026-07-25');
$focused=(new StoredBookingVisitDateValidator($disabledDates))->validate($changed,true,$today);
$confirm=(new StoredBookingValidator($disabledDates))->validateForTargetStatus($changed,'Definitief',$today);
$relevantFields=['bezoekdatum','programma','configuratie'];
$focusedRelevant=array_values(array_map(static fn($issue)=>$issue->code,array_filter($focused->issues,static fn($issue)=>in_array($issue->field,$relevantFields,true))));
$confirmRelevant=array_values(array_map(static fn($issue)=>$issue->code,array_filter($confirm->issues,static fn($issue)=>in_array($issue->field,$relevantFields,true))));
$assert($focusedRelevant===['DISABLED_VISIT_DATE']&&$confirmRelevant===$focusedRelevant,'Confirmability en datumwijziging hebben niet één eigenaar/dezelfde relevante datumissues.');
fwrite(STDOUT,"OK: visit-date domeincontract geslaagd.\n");

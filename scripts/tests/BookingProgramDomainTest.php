<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/vendor/autoload.php';

use GeoFort\Booking\BookingProgramConfig;
use GeoFort\Booking\Stored\StoredBookingAssembler;
use GeoFort\Booking\Validation\{BookingValidationProfile,StoredBookingProgramValidator};

$assert=static function(bool $condition,string $message):void{if(!$condition)throw new RuntimeException($message);};
$assert(array_keys(BookingProgramConfig::PROGRAMS)===['ochtend','dag'],'Onverwachte centrale programmakeys.');
$assert(BookingProgramConfig::programExists('dag')&&BookingProgramConfig::programExists('ochtend')&&!BookingProgramConfig::programExists('onbekend'),'Programma-existencecontract faalt.');
$row=['id'=>7,'status'=>'In optie','schoolnaam'=>'School','land'=>'Nederland','adres'=>'Dijk 1','postcode'=>'1234 AB','plaats'=>'Plaats','school_telefoonnummer'=>'1','contactpersoon_telefoonnummer'=>'2','contactpersoon_voornaam'=>'Jan','contactpersoon_achternaam'=>'Jansen','email'=>'jan@example.test','bezoekdatum'=>'2026-09-16','hoe_kent_u_geofort'=>null,'opmerkingen'=>"regel\nregel",'cjpPasGebruik'=>'nee','cjpContactpersoonNaam'=>null,'cjpPasnummer'=>null,'onderwijs_sector'=>'primairOnderwijs','programma'=>'dag','keuzemodule_key'=>null,'aantal_leerlingen'=>40,'aantal_begeleiders'=>4,'remise_break'=>1,'kazerne_break'=>2,'fortgracht_break'=>3,'glas_limonade'=>4,'waterijsje'=>5,'remise_lunch'=>0,'eigen_picknick'=>1,'voorwaarden_akkoord'=>1,'voorwaarden_akkoord_op'=>'2026-07-01 10:00:00','source_system'=>null,'source_record_id'=>null,'source_record_checksum'=>null,'source_import_run_id'=>null];
$booking=(new StoredBookingAssembler())->assemble($row,[['sector_key'=>'primairOnderwijs','level_key'=>'regulier','group_key'=>'groep7']]);
$changed=$booking->withProgram('ochtend');$before=get_object_vars($booking);$after=get_object_vars($changed);unset($before['program'],$after['program']);
$assert($changed!==$booking&&$changed->program==='ochtend'&&$before===$after,'withProgram behoudt niet alle overige velden exact.');
$assert(BookingValidationProfile::ChangeProgramDraft!==BookingValidationProfile::ChangeProgramConfirmed,'Programmaprofielen zijn niet gescheiden.');
$valid=(new StoredBookingProgramValidator())->validate($changed);$assert($valid->issues===[],'Geldige ochtendstate op woensdag is afgewezen.');
$wrongDay=(new StoredBookingProgramValidator())->validate($booking->withVisitDate('2026-09-17')->withProgram('ochtend'));
$assert($wrongDay->hasCode('CURRENT_CONFIGURATION_MISMATCH'),'Programma/weekdag wordt niet centraal gevalideerd.');
$wrongSectorRow=$row;$wrongSectorRow['onderwijs_sector']='voortgezetOnderbouw';$wrongSectorRow['programma']='ochtend';
$wrongSector=(new StoredBookingAssembler())->assemble($wrongSectorRow,[['sector_key'=>'voortgezetOnderbouw','level_key'=>'havo','group_key'=>'havo1']]);
$assert((new StoredBookingProgramValidator())->validate($wrongSector)->hasCode('CURRENT_CONFIGURATION_MISMATCH'),'Schoolsector/programmaregel ontbreekt.');
$validator=new StoredBookingProgramValidator();
$invalidEducationRow=$row;
$invalidEducation=(new StoredBookingAssembler())->assemble($invalidEducationRow,[['sector_key'=>'primairOnderwijs','level_key'=>'onbekend','group_key'=>'groep7']]);
$delta=$validator->validateChange($invalidEducation,$invalidEducation->withProgram('ochtend'));
$assert(!$delta->hasCode('CURRENT_CONFIGURATION_MISMATCH'),'Een identiek oud onderwijsprobleem blokkeert de programmawijziging.');
$newModuleMismatchRow=$row;
$newModuleMismatchRow['keuzemodule_key']='Earth-Watch';
$newModuleMismatch=(new StoredBookingAssembler())->assemble($newModuleMismatchRow,[['sector_key'=>'primairOnderwijs','level_key'=>'regulier','group_key'=>'groep7']]);
$moduleDelta=$validator->validateChange($newModuleMismatch,$newModuleMismatch->withProgram('ochtend'));
$assert($moduleDelta->hasCode('CURRENT_CONFIGURATION_MISMATCH'),'Een door het proposed programma veroorzaakte keuzemodulefout ontbreekt.');
$tooFewRow=$row;
$tooFewRow['aantal_leerlingen']=39;
$tooFew=(new StoredBookingAssembler())->assemble($tooFewRow,[['sector_key'=>'primairOnderwijs','level_key'=>'regulier','group_key'=>'groep7']]);
$assert($validator->validate($tooFew)->hasCode('CURRENT_CONFIGURATION_MISMATCH'),'Centrale programma-afhankelijke minimumleerlingregel ontbreekt.');
fwrite(STDOUT,"OK: programma-domeincontract geslaagd.\n");

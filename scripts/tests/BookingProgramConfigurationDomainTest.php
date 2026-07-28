<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/vendor/autoload.php';

use GeoFort\Booking\Rules\{BookingRuleOverridePolicy,ProgramConfigurationOverridePolicy};
use GeoFort\Booking\Stored\StoredBookingAssembler;
use GeoFort\Services\Booking\Data\EducationSelectionData;
use GeoFort\Booking\BookingProgramConfig;
use GeoFort\Validation\{ChoiceModuleSelectionValidator,EducationSelectionValidator,FieldValidationException};

$assert=static function(bool $condition,string $message):void{if(!$condition)throw new RuntimeException($message);};
$operationPolicy=new ProgramConfigurationOverridePolicy();
$assert($operationPolicy->definition('PROGRAM_WEEKDAY_MISMATCH')->overridable,'Weekdagissue is niet overridebaar binnen de configuratie-operatie.');
$assert(!(new BookingRuleOverridePolicy())->definition('PROGRAM_WEEKDAY_MISMATCH')->overridable,'Weekdagissue is buiten de configuratie-operatie overridebaar.');
$assert(!$operationPolicy->definition('CURRENT_CONFIGURATION_MISMATCH')->overridable,'Generieke configuratiemismatch is overridebaar geworden.');
$row=['id'=>7,'status'=>'In optie','schoolnaam'=>'School','land'=>'Nederland','adres'=>'Dijk 1','postcode'=>'1234 AB','plaats'=>'Plaats','school_telefoonnummer'=>'1','contactpersoon_telefoonnummer'=>'2','contactpersoon_voornaam'=>'Jan','contactpersoon_achternaam'=>'Jansen','email'=>'jan@example.test','bezoekdatum'=>'2027-02-10','hoe_kent_u_geofort'=>null,'opmerkingen'=>"regel\nregel",'cjpPasGebruik'=>'nee','cjpContactpersoonNaam'=>null,'cjpPasnummer'=>null,'onderwijs_sector'=>'primairOnderwijs','programma'=>'ochtend','keuzemodule_key'=>null,'aantal_leerlingen'=>40,'aantal_begeleiders'=>4,'remise_break'=>1,'kazerne_break'=>2,'fortgracht_break'=>3,'glas_limonade'=>4,'waterijsje'=>5,'remise_lunch'=>0,'eigen_picknick'=>1,'voorwaarden_akkoord'=>1,'voorwaarden_akkoord_op'=>'2026-07-01 10:00:00','source_system'=>null,'source_record_id'=>null,'source_record_checksum'=>null,'source_import_run_id'=>null];
$booking=(new StoredBookingAssembler())->assemble($row,[['sector_key'=>'primairOnderwijs','level_key'=>'regulier','group_key'=>'groep7']]);
$education=new EducationSelectionData('primairOnderwijs',['speciaal'],['speciaal'=>['groep8']]);
$changed=$booking->withProgramConfiguration('dag',80,$education,'Earth-Watch');
$before=get_object_vars($booking);$after=get_object_vars($changed);foreach(['program','studentCount','educationSelection','choiceModuleKey'] as $field){unset($before[$field],$after[$field]);}
$assert($changed!==$booking&&$changed->program==='dag'&&$changed->studentCount===80&&$changed->choiceModuleKey==='Earth-Watch'&&$changed->educationSelection===$education&&$before===$after,'Aggregatehelper wijzigt meer of minder dan vier domeindelen.');
$assert(BookingProgramConfig::SCHOOL_LEVEL_SELECTION_RULES['primairOnderwijs']['maxLevels']===1&&BookingProgramConfig::SCHOOL_LEVEL_SELECTION_RULES['primairOnderwijs']['maxGroupsPerLevel']===3,'PO-limieten wijken af van centrale projectregel.');
$assert(BookingProgramConfig::SCHOOL_LEVEL_SELECTION_RULES['voortgezetOnderbouw']['maxLevels']===3&&BookingProgramConfig::SCHOOL_LEVEL_SELECTION_RULES['voortgezetOnderbouw']['maxGroupsPerLevel']===3,'VO-limieten wijken af van centrale projectregel.');
$assert(BookingProgramConfig::SCHOOL_LEVEL_SELECTION_RULES['primairOnderwijs']['minLevels']===1&&BookingProgramConfig::SCHOOL_LEVEL_SELECTION_RULES['primairOnderwijs']['minGroupsPerLevel']===1,'PO-minimumregels wijken af.');
$assert(BookingProgramConfig::SCHOOL_LEVEL_SELECTION_RULES['voortgezetOnderbouw']['minLevels']===1&&BookingProgramConfig::SCHOOL_LEVEL_SELECTION_RULES['voortgezetOnderbouw']['minGroupsPerLevel']===1,'VO-minimumregels wijken af.');
$assert(BookingProgramConfig::getMinStudentsForSelection('primairOnderwijs','dag')===40&&BookingProgramConfig::STUDENT_LIMITS['max']['dag']===160,'Centrale dag-leerlinglimieten wijken af.');
$assert(BookingProgramConfig::getMinStudentsForSelection('primairOnderwijs','ochtend')===40&&BookingProgramConfig::STUDENT_LIMITS['max']['ochtend']===80,'Centrale ochtend-leerlinglimieten wijken af.');
$educationValidator=new EducationSelectionValidator();
$validVo=$educationValidator->validate(json_encode(['sector'=>'voortgezetOnderbouw','selectedLevels'=>['vmboBasisKader','havo','vwo'],'selectedGroupsByLevel'=>['vmboBasisKader'=>['vmbo1'],'havo'=>['havo2'],'vwo'=>['atheneum2']]],JSON_THROW_ON_ERROR),'voortgezetOnderbouw');
$assert(count($validVo->selectedLevels)===3,'VO accepteert geen drie niveaus.');
$expectEducationFailure=static function(array $payload,string $sector)use($educationValidator):void{try{$educationValidator->validate(json_encode($payload,JSON_THROW_ON_ERROR),$sector);throw new RuntimeException('Ongeldige onderwijsselectie geaccepteerd.');}catch(FieldValidationException){}};
$expectEducationFailure(['sector'=>'primairOnderwijs','selectedLevels'=>['regulier','speciaal'],'selectedGroupsByLevel'=>['regulier'=>['groep5'],'speciaal'=>['groep6']]],'primairOnderwijs');
$expectEducationFailure(['sector'=>'primairOnderwijs','selectedLevels'=>['regulier'],'selectedGroupsByLevel'=>['regulier'=>['groep5','groep6','groep7','groep8']]],'primairOnderwijs');
$expectEducationFailure(['sector'=>'voortgezetOnderbouw','selectedLevels'=>['vmboBasisKader','vmboGemengdTheoretisch','havo','vwo'],'selectedGroupsByLevel'=>['vmboBasisKader'=>['vmbo1'],'vmboGemengdTheoretisch'=>['vmbo1'],'havo'=>['havo1'],'vwo'=>['atheneum1']]],'voortgezetOnderbouw');
$expectEducationFailure(['sector'=>'voortgezetOnderbouw','selectedLevels'=>['onbekend'],'selectedGroupsByLevel'=>['onbekend'=>['x']]],'voortgezetOnderbouw');
$expectEducationFailure(['sector'=>'voortgezetOnderbouw','selectedLevels'=>['havo'],'selectedGroupsByLevel'=>['havo'=>['vmbo1']]],'voortgezetOnderbouw');
$expectEducationFailure(['sector'=>'voortgezetOnderbouw','selectedLevels'=>['havo'],'selectedGroupsByLevel'=>['havo'=>['havo2'],'vwo'=>['atheneum2']]],'voortgezetOnderbouw');
$moduleValidator=new ChoiceModuleSelectionValidator();
$assert(!BookingProgramConfig::hasChoiceModulesForSelection('primairOnderwijs','ochtend'),'Ochtend ondersteunt volgens de centrale configuratie ten onrechte keuzemodules.');
$assert(BookingProgramConfig::hasChoiceModulesForSelection('primairOnderwijs','dag'),'Dag ondersteunt volgens de centrale configuratie geen keuzemodules.');
$morningEducation=$educationValidator->validate(json_encode(['sector'=>'primairOnderwijs','selectedLevels'=>['regulier'],'selectedGroupsByLevel'=>['regulier'=>['groep7']]],JSON_THROW_ON_ERROR),'primairOnderwijs');
$assert($moduleValidator->validate(null,'primairOnderwijs','ochtend',$morningEducation)===null,'Ochtend zonder keuzemodule is ongeldig.');
try{$moduleValidator->validate('Earth-Watch','primairOnderwijs','ochtend',$morningEducation);throw new RuntimeException('Ochtend met keuzemodule is geaccepteerd.');}catch(FieldValidationException){}
$assert($moduleValidator->validate('Klimparcours','voortgezetOnderbouw','dag',$validVo)==='Klimparcours','Geldige modulecombinatie faalt.');
foreach([null,'Onbekend','Minecraft-Windenergiespeurtocht'] as $module){try{$moduleValidator->validate($module,'voortgezetOnderbouw','dag',$validVo);throw new RuntimeException('Ongeldige module geaccepteerd.');}catch(FieldValidationException){}}
fwrite(STDOUT,"OK: programmaconfiguratie-domeincontract geslaagd.\n");

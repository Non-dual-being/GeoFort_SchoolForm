<?php
declare(strict_types=1);
require dirname(__DIR__, 2).'/vendor/autoload.php';

use GeoFort\Booking\{FourModuleProgram, BookingProgramVariant, BookingProgramVersionConfig};
use GeoFort\Services\Booking\Data\FoodAndDrinkSelectionData;
use GeoFort\Services\Booking\Pricing\{BookingPricingInput, BookingPriceCalculator, BookingPriceCatalogRegistry};
use GeoFort\Services\Dashboard\Roster\{StaffDeploymentService, StaffDeploymentScope};

$checks=0;
$assert=static function(bool $ok,string $message)use(&$checks):void{if(!$ok)throw new RuntimeException($message);$checks++;};
$calculator=new BookingPriceCalculator();
$food=FoodAndDrinkSelectionData::fromStoredValues(0,0,0,0,0,0,false);
foreach(['primairOnderwijs'=>1750,'speciaalOnderwijs'=>1750,'voortgezetOnderbouw'=>2099,'voortgezetBovenbouw'=>2099] as $sector=>$unit){
    foreach([1,3,19,40,79,80,159,160] as $students){
        $input=new BookingPricingInput($sector,'dag',$students,50,$food,FourModuleProgram::VERSION);
        $q=$calculator->calculateInput($input);
        $assert($q->pricePerVisitorInclVatCents===$unit && $q->visitAmountInclVatCents===$students*$unit,'Exact cents '.$sector.' '.$students);
        $assert($q->paidSupervisors===0 && $q->freeSupervisors===50,'Free supervisors');
        $assert($q->totalAmountExclVatCents===(int)round($students*$unit/1.09,0,PHP_ROUND_HALF_UP),'VAT rounding');
        $assert($q->totalAmountExclVatCents+$q->vatAmountCents===$q->totalAmountInclVatCents,'VAT reconciliation');
        $assert($q->pricingVersion===BookingPriceCatalogRegistry::FOUR_MODULE_2027_VERSION,'New immutable version');
        $old=$calculator->calculateInput($input,BookingPriceCatalogRegistry::INITIAL_2026_VERSION);
        $assert($old->pricePerVisitorInclVatCents===($unit===1750?1800:2200),'Existing four-module agreements retain rate');
    }
}
$config=BookingProgramVersionConfig::forFrontend(FourModuleProgram::VERSION);
$assert($config['prices']['bezoek']['dag']===['basis'=>17.5,'voortgezet'=>20.99],'Public displayed prices');
$assert($config['availableFrom']==='2027-01-04','Agreed start date retained');
$old=BookingProgramVersionConfig::forFrontend();
$assert($old['prices']['bezoek']['dag']==['basis'=>18,'voortgezet'=>22],'Legacy presentation unchanged');
$assert($old['prices']['snacks']===$config['prices']['snacks']&&$old['prices']['lunch']===$config['prices']['lunch'],'Catering unchanged');
$assert($calculator->calculateInput(new BookingPricingInput('primairOnderwijs','ochtend',80,10,$food))->totalAmountInclVatCents===79600,'Morning unchanged');
$assert(BookingProgramVariant::key('dag',null)==='day-five','Legacy not inferred from date');
$assert(BookingProgramVariant::key('dag',FourModuleProgram::VERSION)==='day-four','Four identity');
$assert(BookingProgramVariant::key('dag','future-unknown')==='unknown','Unknown not silently five');
try{BookingProgramVariant::filter(['day-four']);throw new RuntimeException('Array filter accepted');}catch(\GeoFort\Validation\FieldValidationException){}
$entries=[];
foreach(FourModuleProgram::timetable() as $slot)if($slot['kind']==='lesson')$entries[]=['date'=>'2027-01-04','start'=>$slot['start'],'end'=>$slot['end'],'programVariant'=>'day-four'];
$assert(StaffDeploymentService::minutes($entries)===180,'Four lessons are three hours');
$assert(StaffDeploymentService::minutes([...$entries,...$entries])===180,'Shared VI/groups do not double hours');
$five=$entries;$five[]=['date'=>'2027-01-04','start'=>'14:00','end'=>'14:45','programVariant'=>'day-five'];
$assert(StaffDeploymentService::minutes($five)===225,'Five lessons are three hours 45 minutes');
foreach($five as &$entry)$entry+=['staffId'=>1,'name'=>'Fixture','status'=>'Definitief','rosterStatus'=>'concept','employment'=>'paid'];unset($entry);
$f=StaffDeploymentService::filters(['programVariant'=>'day-four']);
$scope=StaffDeploymentScope::select($five,$f);
$assert(count($scope['entries'])===4&&StaffDeploymentService::minutes($scope['entries'])===180,'Variant scope hours');
echo "PASS: $checks four-module price, replay, presentation and hours checks\n";

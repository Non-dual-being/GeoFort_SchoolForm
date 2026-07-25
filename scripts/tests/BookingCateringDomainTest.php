<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/vendor/autoload.php';

use GeoFort\Booking\Stored\BookingSourceMetadata;
use GeoFort\Booking\Stored\StoredBooking;
use GeoFort\Booking\Validation\BookingValidationCoordinator;
use GeoFort\Booking\Validation\BookingValidationProfile;
use GeoFort\Services\Booking\Data\EducationSelectionData;
use GeoFort\Services\Booking\Data\FoodAndDrinkSelectionData;
use GeoFort\Services\Booking\Pricing\BookingPriceCalculator;
use GeoFort\Validation\FoodAndDrinkSelectionValidator;

$assert=static function(bool $condition,string $message):void{if(!$condition)throw new RuntimeException($message);};
$stored=static fn(int $lunch,bool $picnic):FoodAndDrinkSelectionData=>FoodAndDrinkSelectionData::fromStoredValues(0,0,0,0,0,$lunch,$picnic);
$assert($stored(0,false)->lunchChoice==='none','0/0 wordt niet als none gereconstrueerd.');
$assert($stored(50,false)->lunchChoice==='remise_lunch','Remiselunch wordt niet herkend.');
$assert($stored(0,true)->lunchChoice==='eigen_picknick','Eigen picknick wordt niet herkend.');
$assert($stored(50,true)->lunchChoice==='conflict','Lunchconflict wordt stil genormaliseerd.');
$booking=static function(FoodAndDrinkSelectionData $food):StoredBooking{return new StoredBooking(1,'In optie','2026-09-01','School','Nederland','Adres','1234 AB','Plaats','1','2','Voor','Naam','a@example.test',null,'nee',null,null,'primairOnderwijs','dag','Earth-Watch',50,7,new EducationSelectionData('primairOnderwijs',['regulier'],['regulier'=>['groep5']]),$food,null,true,'2026-01-01',new BookingSourceMetadata(null,null,null,null,true));};
$validator=new BookingValidationCoordinator();
$validSnacks=[0,1,200];
foreach($validSnacks as $quantity){$result=$validator->validate(BookingValidationProfile::ChangeCatering,$booking(new FoodAndDrinkSelectionData($quantity,0,0,0,0,'eigen_picknick',0,true)));$assert($result->isValid(),"Snack {$quantity} is afgewezen.");}
$invalid=$validator->validate(BookingValidationProfile::ChangeCatering,$booking(new FoodAndDrinkSelectionData(201,0,0,0,0,'eigen_picknick',0,true)));
$assert($invalid->hasCode('CATERING_OPTION_LIMIT_EXCEEDED'),'Snack 201 mist specifieke limietcode.');
foreach([50,200] as $quantity)$assert($validator->validate(BookingValidationProfile::ChangeCatering,$booking(new FoodAndDrinkSelectionData(0,0,0,0,0,'remise_lunch',$quantity,false)))->isValid(),"Lunch {$quantity} is afgewezen.");
foreach([49,201] as $quantity)$assert($validator->validate(BookingValidationProfile::ChangeCatering,$booking(new FoodAndDrinkSelectionData(0,0,0,0,0,'remise_lunch',$quantity,false)))->hasCode('INVALID_REMISE_LUNCH_QUANTITY'),"Lunch {$quantity} mist specifieke code.");
$assert($validator->validate(BookingValidationProfile::ChangeCatering,$booking($stored(0,false)))->hasCode('MISSING_LUNCH_SELECTION'),'None is als nieuwe geldige selectie behandeld.');
$assert($validator->validate(BookingValidationProfile::ChangeCatering,$booking($stored(50,true)))->hasCode('CONFLICTING_LUNCH_SELECTION'),'Conflict mist specifieke code.');
$semanticConflict=$validator->validate(BookingValidationProfile::ChangeCatering,$booking(new FoodAndDrinkSelectionData(0,0,0,0,0,'eigen_picknick',50,true)));
$assert($semanticConflict->hasCode('CONFLICTING_LUNCH_SELECTION'),'Tegenstrijdige nieuwe picknickselectie mist een specifieke issue.');
$confirmConflict=$validator->validate(BookingValidationProfile::ConfirmBooking,$booking($stored(50,true)));
$assert($confirmConflict->hasCode('CONFLICTING_LUNCH_SELECTION')&&!$confirmConflict->hasCode('CURRENT_CONFIGURATION_MISMATCH'),'ConfirmBooking gebruikt niet uitsluitend de gespecialiseerde cateringissue.');
$unrelatedInvalid=new StoredBooking(2,'Afgewezen','not-a-date','School','Nederland','Adres','1234 AB','Plaats','1','2','Voor','Naam','a@example.test',null,'ja',null,null,'onbekend','onbekend',null,null,null,new EducationSelectionData('onbekend',[],[]),new FoodAndDrinkSelectionData(0,0,0,0,0,'eigen_picknick',0,true),null,false,null,new BookingSourceMetadata(null,null,null,null,false));
$assert($validator->validate(BookingValidationProfile::ChangeCatering,$unrelatedInvalid)->isValid(),'ChangeCatering valideert ongerelateerde CJP-, onderwijs-, attendance-, datum- of voorwaardenvelden.');
$original=$booking($stored(0,true));$changed=$original->withFoodAndDrink(new FoodAndDrinkSelectionData(1,0,0,0,0,'eigen_picknick',0,true));
$originalValues=get_object_vars($original);$changedValues=get_object_vars($changed);unset($originalValues['foodAndDrinkSelection'],$changedValues['foodAndDrinkSelection']);
$assert($changedValues===$originalValues&&$changed->foodAndDrinkSelection->remiseBreak===1,'withFoodAndDrink behoudt overige velden niet exact.');
$assert($stored(0,true)->orderedQuantities()===[]&&$stored(0,false)->orderedQuantities()===[],'None of Eigen picknick levert ten onrechte een betaalde quantityregel op.');
$priced=(new BookingPriceCalculator())->calculate('primairOnderwijs','dag',50,7,new FoodAndDrinkSelectionData(1,0,0,0,0,'remise_lunch',50,false))->toArray();
$free=(new BookingPriceCalculator())->calculate('primairOnderwijs','dag',50,7,$stored(0,true))->toArray();
$assert(count($priced['foodAndDrink']['lines'])===2&&$priced['foodAndDrink']['totalInclVat']>0&&$free['foodAndDrink']['lines']===[]&&$free['foodAndDrink']['totalInclVat']===0.0,'Centrale cateringprijsberekening of gratis Eigen picknick is onjuist.');
$public=(new FoodAndDrinkSelectionValidator())->validate(['remiseBreak'=>'0','kazerneBreak'=>'0','fortgrachtBreak'=>'0','waterijsje'=>'0','glasLimonade'=>'0','lunchChoice'=>'eigen_picknick','remiseLunch'=>'0']);
$assert($public->eigenPicknick&&$public->remiseLunch===0,'Publieke cateringvalidator is geregressed.');
echo "Booking catering domain tests passed.\n";

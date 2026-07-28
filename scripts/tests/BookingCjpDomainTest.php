<?php

declare(strict_types=1);

require dirname(__DIR__,2).'/vendor/autoload.php';

use GeoFort\Booking\Cjp\BookingCjpDetails;
use GeoFort\Services\Booking\Cjp\BookingCjpValidator;

$assert=static function(bool $condition,string $message):void{if(!$condition)throw new RuntimeException($message);};
$validator=new BookingCjpValidator();
$valid=$validator->validate(new BookingCjpDetails('ja',"  Jan\u{00A0}de   Vries  ",' 1234 5678 '));
$assert($valid['issues']===[]&&$valid['details']?->contactName==='Jan de Vries'&&$valid['details']?->cardNumber==='12345678','Geldige CJP-state of normalisatie faalt.');
$no=$validator->validate(new BookingCjpDetails('nee',null,null));
$assert($no['issues']===[]&&$no['details']?->contactName===null&&$no['details']?->cardNumber===null,'Geldige nee-state faalt.');
foreach([
    [new BookingCjpDetails('','',null),'INVALID_CJP_SELECTION'],
    [new BookingCjpDetails('misschien',null,null),'INVALID_CJP_SELECTION'],
    [new BookingCjpDetails('ja','', '12345678'),'INVALID_CJP_CONTACT_NAME'],
    [new BookingCjpDetails('ja','Jan','123'),'INVALID_CJP_CARD_NUMBER'],
] as [$details,$code]){$result=$validator->validate($details);$codes=array_map(static fn($issue)=>$issue->code,$result['issues']);$assert(in_array($code,$codes,true),"Issue {$code} ontbreekt.");foreach($result['issues'] as $issue)$assert($issue->title!==''&&$issue->description!=='','Issue mist titel of beschrijving.');}
$hidden=$validator->validate(new BookingCjpDetails('nee','Jan Jansen','12345678'));
$assert($hidden['issues']===[]&&$hidden['details']?->toArray()===['useCjp'=>'nee','contactName'=>null,'cardNumber'=>null],'Verborgen CJP-waarden bij nee worden niet autoritatief genormaliseerd.');
$before=new BookingCjpDetails('ja','Jan','12345678');$same=new BookingCjpDetails('ja','Jan','12345678');$after=new BookingCjpDetails('ja','Piet','12345678');
$assert($before->toArray()===$same->toArray(),'No-opvergelijking faalt.');$assert($before->toArray()!==$after->toArray(),'Expected-conflictvergelijking faalt.');$assert((new BookingCjpDetails('nee',null,null))->toArray()!==(new BookingCjpDetails('nee','',null))->toArray(),'Expected snapshot vergelijkt null en lege tekst niet strikt.');
$diff=[];foreach(['cjpPasGebruik'=>'useCjp','cjpContactpersoonNaam'=>'contactName','cjpPasnummer'=>'cardNumber']as$column=>$property)if($before->{$property}!==$after->{$property})$diff[$column]=['before'=>$before->{$property},'after'=>$after->{$property}];
$assert(array_keys($diff)===['cjpContactpersoonNaam'],'Auditdiff bevat andere velden dan werkelijk gewijzigd.');
echo "BookingCjpDomainTest OK\n";

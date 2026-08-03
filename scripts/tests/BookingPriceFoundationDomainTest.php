<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/vendor/autoload.php';

use GeoFort\Services\Booking\Data\FoodAndDrinkSelectionData;
use GeoFort\Services\Booking\Pricing\{BookingPriceCalculator,BookingPriceCatalog,BookingPriceCatalogRegistry,BookingPriceSnapshot,BookingPriceSnapshotFactory,BookingPricingInput,BookingPricingInputChecksum};

$assert=static function(bool $condition,string $message):void{if(!$condition)throw new RuntimeException($message);};
$food=static fn(int $snack=0,int $lunch=0,bool $picnic=true):FoodAndDrinkSelectionData=>FoodAndDrinkSelectionData::fromStoredValues($snack,0,0,0,0,$lunch,$picnic);
$registry=new BookingPriceCatalogRegistry();
$catalog=$registry->active();
$assert($catalog->version===BookingPriceCatalogRegistry::INITIAL_2026_VERSION,'Actieve versie is niet expliciet.');
$assert($registry->get(BookingPriceCatalogRegistry::INITIAL_2026_VERSION)===$catalog,'Historische versie kan niet gericht worden opgehaald.');
foreach($catalog->visitPricesCents() as $prices)foreach($prices as $price)$assert(is_int($price),'Bezoekprijs is geen integer cents.');
foreach($catalog->cateringPricesCents() as $price)$assert(is_int($price),'Cateringprijs is geen integer cents.');
$assert($catalog->studentsPerFreeSupervisor===8&&$catalog->freeSupervisorCount(9)===2,'Catalog-v1 fixeert de oorspronkelijke gratis-begeleidersregel niet.');
$assert($catalog->vatBasisPoints===900&&$catalog->vatMeaning==='prices_include_vat'&&$catalog->vatRoundingRule===BookingPriceCatalog::VAT_ROUNDING_HALF_UP_TOTAL_CENTS&&$catalog->calculationProfile===BookingPriceCatalog::CALCULATION_PROFILE_PER_ITEM_V1,'Catalog-v1 fixeert btw, afronding of calculation profile niet.');
try{$registry->get('unknown');throw new RuntimeException('Onbekende versie geaccepteerd.');}catch(InvalidArgumentException $e){$assert($e->getMessage()==='PRICE_VERSION_UNKNOWN','Onveilige onbekende-versiefout.');}

$calculator=new BookingPriceCalculator($registry);
$quote=$calculator->calculate('primairOnderwijs','dag',50,8,$food(1,50,false));
$assert($quote->pricePerVisitorInclVatCents===1800,'Dagprijs PO onjuist.');
$assert($quote->freeSupervisors===7&&$quote->paidSupervisors===1,'Gratis/betaalde begeleiders onjuist.');
$assert($quote->visitAmountInclVatCents===91800,'Bezoekwaarde onjuist.');
$assert($quote->cateringAmountInclVatCents===18260,'Cateringwaarde onjuist.');
$assert($quote->visitAmountInclVatCents+$quote->cateringAmountInclVatCents===$quote->totalAmountInclVatCents,'Bezoek+catering is niet totaal.');
$assert($quote->totalAmountExclVatCents+$quote->vatAmountCents===$quote->totalAmountInclVatCents,'Exclusief+btw is niet inclusief.');
$free=$calculator->calculate('primairOnderwijs','ochtend',2,1,$food());
$assert($free->pricePerVisitorInclVatCents===995&&$free->cateringAmountInclVatCents===0,'Ochtend- of nulcateringprijs onjuist.');
$vo=$calculator->calculate('voortgezetOnderbouw','dag',1,1,$food());
$assert($vo->pricePerVisitorInclVatCents===2200,'VO-prijs onjuist.');
foreach([[-1,0],[1,-1]] as [$students,$supervisors])try{$calculator->calculate('primairOnderwijs','dag',$students,$supervisors,$food());throw new RuntimeException('Negatief aantal geaccepteerd.');}catch(InvalidArgumentException){}

$input=new BookingPricingInput('primairOnderwijs','dag',50,8,$food(1,50,false));
$checksums=new BookingPricingInputChecksum();
$json=$checksums->canonicalJson($input);$checksum=$checksums->checksum($input);
$assert($checksum===$checksums->checksum($input)&&strlen($checksum)===64,'Checksum is niet deterministisch.');
$assert(!str_contains($json,'schoolName')&&!str_contains($json,'email')&&!str_contains($json,'comments'),'Canonical input bevat persoonsgegevens.');
$changed=new BookingPricingInput('primairOnderwijs','dag',51,8,$food(1,50,false));
$assert($checksums->checksum($changed)!==$checksum,'Prijsbepalend veld wijzigt checksum niet.');
$assert(str_contains($json,'"ownPicnic":false')&&str_contains($json,'"remiseLunch":50'),'Boolean/integersemantiek ontbreekt.');

$factory=new BookingPriceSnapshotFactory($checksums);
$snapshot=$factory->complete(7,1,null,BookingPriceSnapshot::REASON_SUBMISSION,$input,$quote,null);
$assert($snapshot->sequenceNumber===1&&$snapshot->isComplete(),'Eerste complete snapshot onjuist.');
$assert($snapshot->visitAmountInclVatCents+$snapshot->cateringAmountInclVatCents===$snapshot->totalAmountInclVatCents,'Snapshotinvariant onjuist.');
$assert($snapshot->checksumFormatVersion===BookingPricingInputChecksum::FORMAT_VERSION,'Checksumformatversie ontbreekt.');
try{new BookingPriceSnapshot(null,7,2,null,BookingPriceSnapshot::REASON_PLANNER_UPDATE,BookingPriceSnapshot::STATE_HISTORICAL_UNAVAILABLE,null,null,null,null,0,null,null,null,null,$json,[],$checksum,1,null);throw new RuntimeException('Onbekend bedrag als nul geaccepteerd.');}catch(InvalidArgumentException){}

$futureCatalog=new BookingPriceCatalog('future-test','EUR',2100,'prices_include_vat',BookingPriceCatalog::VAT_ROUNDING_HALF_UP_TOTAL_CENTS,BookingPriceCatalog::CALCULATION_PROFILE_PER_ITEM_V1,4,['primairOnderwijs'=>'basis','speciaalOnderwijs'=>'basis','voortgezetOnderbouw'=>'voortgezet','voortgezetBovenbouw'=>'voortgezet'],['ochtend'=>['basis'=>1095],'dag'=>['basis'=>1900,'voortgezet'=>2300]],['remise_break'=>300,'kazerne_break'=>300,'fortgracht_break'=>300,'glas_limonade'=>120,'waterijsje'=>120,'remise_lunch'=>400,'eigen_picknick'=>0]);
$futureCalculator=new BookingPriceCalculator(new BookingPriceCatalogRegistry([$catalog,$futureCatalog],'future-test'));
$assert($futureCalculator->calculateInput($input,BookingPriceCatalogRegistry::INITIAL_2026_VERSION)->totalAmountInclVatCents===$quote->totalAmountInclVatCents,'Nieuwe actieve catalogus herwaardeert bestaande versie.');
$historicalAgain=$futureCalculator->calculateInput($input,BookingPriceCatalogRegistry::INITIAL_2026_VERSION);
$activeFuture=$futureCalculator->calculateInput($input);
$assert($historicalAgain->freeSupervisors===7&&$activeFuture->freeSupervisors===13,'Historische gratis-begeleidersregel volgt ten onrechte de actieve catalogus.');
$assert($historicalAgain->vatBasisPoints===900&&$activeFuture->vatBasisPoints===2100&&$historicalAgain->totalAmountExclVatCents===$quote->totalAmountExclVatCents,'Btw of afronding wordt niet door de gekozen prijsversie bepaald.');
$historicalSnapshot=$factory->complete(7,2,$snapshot->id,BookingPriceSnapshot::REASON_PLANNER_UPDATE,$input,$historicalAgain,null);
$assert($historicalSnapshot->pricingVersion===$snapshot->pricingVersion&&$historicalSnapshot->totalAmountInclVatCents===$snapshot->totalAmountInclVatCents&&$historicalSnapshot->vatAmountCents===$snapshot->vatAmountCents,'Catalog-v1 snapshot verandert onder een latere actieve calculation profile.');
echo "Booking price foundation domain tests passed.\n";

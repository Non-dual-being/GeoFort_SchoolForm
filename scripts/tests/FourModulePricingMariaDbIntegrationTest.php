<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/vendor/autoload.php';
require __DIR__.'/Support/DisposableBookingMariaDb.php';
use GeoFort\Booking\FourModuleProgram;
use GeoFort\Services\Booking\Data\FoodAndDrinkSelectionData;
use GeoFort\Services\Booking\Pricing\{BookingPricingInput,BookingPriceCalculator,BookingPriceCatalogRegistry,BookingPriceSnapshotService,BookingPriceSnapshot,BookingPriceSnapshotFactory};
use GeoFort\Services\Sql\{BookingPriceSnapshotSqlRepository,BookingRevenueReportSqlRepository,BookingAnalyticsRepository,BookingExportSqlRepository};
use GeoFort\Services\Dashboard\Booking\Revenue\{BookingRevenueCriteria,BookingRevenueReportService,BookingRevenueCsvWriter};
use GeoFort\Services\Dashboard\Booking\Analytics\{BookingAnalyticsCriteriaFactory,BookingAnalyticsService};
use GeoFort\Services\Dashboard\Booking\Export\{SpreadsheetFormulaEscaper,BookingExportRowFactory};
$checks=0;$assert=static function(bool $ok,string $message)use(&$checks):void{if(!$ok)throw new RuntimeException($message);$checks++;};
$db=DisposableBookingMariaDb::create('four_prices');
try{
    $pdo=$db->pdo;$pdo->beginTransaction();$calc=new BookingPriceCalculator();$repo=new BookingPriceSnapshotSqlRepository($pdo);$prices=new BookingPriceSnapshotService($repo,$calc);
    $none=FoodAndDrinkSelectionData::fromStoredValues(0,0,0,0,0,0,false);
    $insert=$pdo->prepare("INSERT INTO aanvragen(status,schoolnaam,land,adres,postcode,plaats,school_telefoonnummer,contactpersoon_telefoonnummer,contactpersoon_voornaam,contactpersoon_achternaam,email,bezoekdatum,onderwijs_sector,programma,program_version,aantal_leerlingen,aantal_begeleiders,voorwaarden_akkoord) VALUES(?,?,'NL','Test','1234AB','Test','1','2','Test','Test','test@example.test','2027-01-04',?,?,?,?,30,1)");
    $cases=[['primairOnderwijs','dag',FourModuleProgram::VERSION,80,'Definitief',1750],['primairOnderwijs','dag',FourModuleProgram::VERSION,40,'Definitief',1750],['voortgezetOnderbouw','dag',FourModuleProgram::VERSION,80,'Definitief',2099],['voortgezetBovenbouw','dag',FourModuleProgram::VERSION,19,'In optie',2099],['primairOnderwijs','dag',null,80,'Definitief',1800],['primairOnderwijs','ochtend',null,40,'Definitief',995],['voortgezetOnderbouw','dag',FourModuleProgram::VERSION,80,'Afgewezen',2099]];
    $expectedFour=0;$ids=[];
    foreach($cases as $i=>[$sector,$program,$version,$students,$status,$unit]){
        $insert->execute([$status,'Fixture '.$i,$sector,$program,$version,$students]);$id=(int)$pdo->lastInsertId();$ids[]=$id;
        if ($i === 1) $pdo->prepare("INSERT INTO aanvraag_onderwijs_selecties(aanvraag_id,sector_key,level_key,group_key) VALUES(?,'primairOnderwijs','speciaal','groep7')")->execute([$id]);
        $input=new BookingPricingInput($sector,$program,$students,30,$none,$version);
        $first=$prices->appendUsingActiveVersion($id,$input,BookingPriceSnapshot::REASON_SUBMISSION);
        $assert($first->details['visit']['pricePerVisitorInclVatCents']===$unit,'Stored unit');
        $assert($first->details['pricingVersion']===($version===null?'catalog-v1':'catalog-day-four-2027-v1'),'Stored catalog');
        if($status==='Definitief'&&$version!==null)$expectedFour+=$first->totalAmountInclVatCents;
        $confirmed=$prices->appendUsingExistingVersion($id,$input,BookingPriceSnapshot::REASON_CONFIRMATION,1);
        $assert($confirmed->sequenceNumber===2&&$confirmed->totalAmountInclVatCents===$first->totalAmountInclVatCents,'Confirmation preserves quote');
    }
    // A four-module agreement made before this change retains its original catalog.
    $insert->execute(['Definitief','Existing four','voortgezetOnderbouw','dag',FourModuleProgram::VERSION,40]);$legacy=(int)$pdo->lastInsertId();
    $input=new BookingPricingInput('voortgezetOnderbouw','dag',40,30,$none,FourModuleProgram::VERSION);
    $repo->append((new BookingPriceSnapshotFactory())->complete($legacy,1,null,BookingPriceSnapshot::REASON_SUBMISSION,$input,$calc->calculateInput($input,'catalog-v1'),null));
    $newInput=new BookingPricingInput('voortgezetOnderbouw','dag',41,30,$none,FourModuleProgram::VERSION);
    $changed=$prices->appendUsingExistingVersion($legacy,$newInput,BookingPriceSnapshot::REASON_PLANNER_UPDATE,1);
    $pdo->prepare('UPDATE aanvragen SET aantal_leerlingen=41 WHERE id=?')->execute([$legacy]);
    $assert($changed->totalAmountInclVatCents===90200&&$changed->pricingVersion==='catalog-v1','Existing agreement after attendance change');
    $assert($prices->appendUsingExistingVersion($legacy,$newInput,BookingPriceSnapshot::REASON_PLANNER_UPDATE,1)->id===$changed->id,'Unchanged input is idempotent');
    $expectedFour+=90200;
    // Missing snapshots stay missing, never silently repriced in reports.
    $insert->execute(['Definitief','No price','primairOnderwijs','dag',FourModuleProgram::VERSION,10]);
    $revenueRepo=new BookingRevenueReportSqlRepository($pdo);$revenue=new BookingRevenueReportService($revenueRepo);$bounds=$revenue->availableVisitDateRange();
    $criterion=new BookingRevenueCriteria('2027-01-04','2027-01-04',programVariant:'day-four');
    $report=$revenue->report($criterion,$bounds);
    $assert($report->counts['bookingsTotal']===5&&$report->counts['missingPrice']===1,'Four counts and missing price');
    $assert($report->counts['studentsTotal']===251,'Four student total');
    $assert($report->definitiveRevenue['totalInclVatCents']===$expectedFour,'Revenue uses latest stored quote, no duplication or repricing');
    $rows=$revenue->page($criterion)['items'];
    $assert(count($rows)===5&&count(array_unique(array_column($rows,'programVariant')))===1,'Pagination and variant agree');
    $assert(array_column($rows,'unitPriceInclVatCents')===[1750,1750,2099,2200,null],'Visible stored unit prices include historic rate');
    $stream=fopen('php://temp','w+');(new BookingRevenueCsvWriter(new SpreadsheetFormulaEscaper()))->write($stream,$revenue->exportRows($criterion));rewind($stream);$csv=stream_get_contents($stream);fclose($stream);
    $assert(str_contains($csv,'20,99')&&str_contains($csv,'17,50')&&str_contains($csv,'22,00')&&str_contains($csv,'vier modules'),'CSV exact prices and variant');
    $assert(!str_contains($csv,'Fixture 4')&&!str_contains($csv,'Fixture 5'),'CSV honors filter');
    $five=$revenue->report(new BookingRevenueCriteria('2027-01-04','2027-01-04',programVariant:'day-five'),$bounds);
    $assert($five->counts['bookingsTotal']===1,'2027 old five-module agreement remains five');
    $option=$revenue->report(new BookingRevenueCriteria('2027-01-04','2027-01-04','option',programVariant:'day-four'),$bounds);
    $assert($option->potentialRevenue['totalInclVatCents']===39881&&$option->counts['bookingsTotal']===1,'Option exact VO total');
    $analytics=new BookingAnalyticsService(new BookingAnalyticsRepository($pdo),new BookingExportSqlRepository($pdo));
    $criteria=(new BookingAnalyticsCriteriaFactory())->create(['startDate'=>'2027-01-04','endDate'=>'2027-01-04','programVariant'=>'day-four','population'=>'confirmed'],$bounds);
    $analysis=$analytics->analyze($criteria)->toArray();
    $assert($analysis['summary']['activeBookings']===5&&$analysis['summary']['plannedStudents']===251,'Analytics and revenue same selected bookings');
    $assert($analysis['programDistribution'][0]['activeBookings']===5&&$analysis['programDistribution'][1]['activeBookings']===0,'Variant distribution');
    $assert(array_sum(array_column($analysis['monthlyTrend'],'plannedStudents'))===251,'Monthly analytics honors variant');
    $assert(array_sum(array_column($analysis['seasonalityAnalysis']['visitDateBuckets'],'students'))===251,'Deep analytics honors variant');
    $assert($pdo->query('SELECT COUNT(*) FROM aanvragen')->fetchColumn()==9,'Read reports do not mutate bookings');
    $primaryCriteria=(new BookingAnalyticsCriteriaFactory())->create(['startDate'=>'2027-01-04','endDate'=>'2027-01-04','programVariant'=>'day-four','sector'=>'primairOnderwijs','population'=>'confirmed'],$bounds);
    $assert($analytics->analyze($primaryCriteria)->summary->toArray()['plannedStudents']===130,'Primary analytics includes SO according to stored education model');
    for($i=0;$i<11;$i++)$insert->execute(['Definitief','Pagination '.$i,'primairOnderwijs','dag',FourModuleProgram::VERSION,1]);
    $second=$revenue->page(new BookingRevenueCriteria('2027-01-04','2027-01-04',page:2,programVariant:'day-four'));
    $assert($second['pagination']['totalItems']===16&&count($second['items'])===6,'Variant pagination counts entire filtered selection');
    $assert(count($revenue->exportRows($criterion))===16,'Export is complete across pages');
    foreach($second['items'] as $item)$assert($item['programVariant']==='day-four','Pagination does not leak other variants');
    echo "PASS: $checks MariaDB snapshot, revenue, CSV and analysis checks\n";
}finally{if($pdo->inTransaction())$pdo->rollBack();$db->drop();}

<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/vendor/autoload.php';
require __DIR__.'/Support/RosterLifecycleFixtures.php';

use GeoFort\Booking\{FourModuleProgram, Stored\StoredBookingAssembler};
use GeoFort\Booking\VisitDate\{BookingVisitDateChangeCommand,BookingVisitDateChangeCode};
use GeoFort\Booking\Validation\{StoredBookingVisitDateValidator,StoredBookingValidator};
use GeoFort\Services\Booking\Availability\BookingAvailabilityService;
use GeoFort\Services\Booking\Presentation\EducationSelectionSummaryFactory;
use GeoFort\Services\Booking\Pricing\{BookingPriceCalculator,BookingPriceSnapshotServiceFactory};
use GeoFort\Services\Booking\Submission\BookingSubmissionService;
use GeoFort\Services\Booking\Program\BookingProgramAccessService;
use GeoFort\Services\Booking\VisitDate\BookingVisitDateChangeServiceFactory;
use GeoFort\Services\Booking\Roster\{BookingRosterResolver,RosterGroupCountResolver};
use GeoFort\Services\Dashboard\Booking\DashboardBookingVisitDateCalendarService;
use GeoFort\Services\Dashboard\Booking\Analytics\{BookingAdvancedAnalyticsCalculator,BookingAnalyticsCriteria};
use GeoFort\Services\Dashboard\Booking\Export\BookingExportDateBounds;
use GeoFort\Services\Http\Api\Booking\{BookingFormHandler,BookingProgramConfigAction,BookingPriceQuoteAction,BookingRosterAction};
use GeoFort\Services\Http\Response\JsonResponse;
use GeoFort\Services\Http\Url\EnvironmentBaseUrlProvider;
use GeoFort\Services\Mail\{BookingMailService,MailConfig,MailInterface};
use GeoFort\Services\Mail\Templates\{BookingRequestMailTemplate,MailLayout,MailLinks};
use GeoFort\Services\Sql\{BookingCalendarSqlService,BookingDaySettingsSqlRepository,DisabledDatesSqlService,EducationSelectionSqlService,FormSubmitLogService,RequestService,BookingProgramAccessSqlRepository,StoredBookingSqlRepository,RosterSqlService};
use GeoFort\Validation\{Validator,EducationSelectionValidator,ProgramSelectionValidator,ChoiceModuleSelectionValidator,StudentCountValidator,SupervisorCountValidator,FoodAndDrinkSelectionValidator,TermsAcceptanceValidator,FieldValidationException};

$db=DisposableBookingMariaDb::create('program_availability');$pdo=$db->pdo;$checks=0;
$assert=static function(bool $ok,string $message)use(&$checks):void{if(!$ok)throw new RuntimeException($message);$checks++;};
try {
    $pdo->exec(file_get_contents(dirname(__DIR__,2).'/database/sql/2026-10-30_create_visit_surveys.sql'));
    RosterLifecycleFixtures::schema($pdo);
    $rosters=RosterLifecycleFixtures::services($pdo);
    $plan=RosterLifecycleFixtures::validPlan($pdo,$rosters);
    $rosterBefore=[];
    foreach($pdo->query("SHOW TABLES LIKE 'roster_%'")->fetchAll(PDO::FETCH_COLUMN) as $table) $rosterBefore[$table]=$pdo->query("SELECT * FROM `$table`")->fetchAll();
    $repo=new BookingProgramAccessSqlRepository($pdo);$access=new BookingProgramAccessService($repo);
    $programValidator=new ProgramSelectionValidator($repo);
    $disabled=new DisabledDatesSqlService($pdo);$daySettings=new BookingDaySettingsSqlRepository($pdo);$calendar=new BookingCalendarSqlService($pdo);
    $bookings=new StoredBookingSqlRepository($pdo,new StoredBookingAssembler());
    $mailer=new class implements MailInterface {
        public int $sent=0;
        public function send(string $toEmail,string $toName,string $subject,string $htmlBody,string $textBody,array $cc=[],array $attachments=[],array $bcc=[]):void{$this->sent++;}
    };
    $links=new MailLinks('https://test.example','https://test.example/voorwaarden','onderwijs@example.test');
    $mail=new BookingMailService($mailer,new MailConfig('',0,'','','','noreply@example.test','GeoFort','planner@example.test',null,[],'testing'),new BookingRequestMailTemplate(new MailLayout($links),$links,new Validator(),new EducationSelectionSummaryFactory()));
    $availability=new BookingAvailabilityService($calendar,$disabled);
    $submission=new BookingSubmissionService($pdo,new FormSubmitLogService($pdo),new RequestService($pdo),new EducationSelectionSqlService($pdo),$mail,$daySettings,$availability,(new BookingPriceSnapshotServiceFactory($pdo))->create());
    $response=new JsonResponse(new EnvironmentBaseUrlProvider('development','https://onderwijsformulier.test'));
    $handler=new BookingFormHandler($response,new Validator(),new EducationSelectionValidator(),new FormSubmitLogService($pdo),$availability,$submission,$programValidator,new ChoiceModuleSelectionValidator(),new StudentCountValidator(),new SupervisorCountValidator(),new FoodAndDrinkSelectionValidator(),new TermsAcceptanceValidator(),'192.0.2.77',0);
    $capture=static function(callable $call):array{ob_start();try{$call();$body=ob_get_contents();}finally{ob_end_clean();}return [http_response_code(),json_decode($body,true,512,JSON_THROW_ON_ERROR)];};
    $call=static fn(array $post):array=>$capture(fn()=>$handler->handle($post));
    $base=['schoolnaam'=>'Configuratietest','land'=>'Nederland','adres'=>'Dijk 1','postcode'=>'1234 AB','plaats'=>'Herwijnen','schoolTelefoonnummer'=>'0345123456','contactpersoonTelefoonnummer'=>'0612345678','contactpersoonVoornaam'=>'Test','contactpersoonAchternaam'=>'Planner','email'=>'test@example.test','hoeKentUGeoFort'=>'Via de website van GeoFort','cjpPasGebruik'=>'nee','onderwijsSector'=>'primairOnderwijs','programma'=>'ochtend','programVariant'=>'auto','keuzemodule'=>'','aantalLeerlingen'=>'60','aantalBegeleiders'=>'8','educationSelection'=>json_encode(['sector'=>'primairOnderwijs','selectedLevels'=>['regulier'],'selectedGroupsByLevel'=>['regulier'=>['groep7']]]),'lunchChoice'=>'eigen_picknick','voorwaardenAkkoord'=>'1','bezoekdatum'=>'2026-12-08'];
    [$status,$result]=$call($base);$assert($status===422,'Tuesday must initially be closed: '.json_encode($result));
    $rule=['program'=>'morning','from'=>'2026-12-08','until'=>'2026-12-08','weekdays'=>[2],'open'=>true,'note'=>'Private planner explanation'];
    $assert($access->saveConfiguration(true,[$rule],0,1,'planner')['ok'],'Open one Tuesday');
    [$status,$result]=$call($base);$assert($status===200&&$result['ok'],'Opened Tuesday submission: '.json_encode($result));
    $tuesdayId=(int)$pdo->lastInsertId(); // Fetch by its unique stored school/date, not the last dependent insert.
    $tuesdayId=(int)$pdo->query("SELECT id FROM aanvragen WHERE schoolnaam='Configuratietest' AND bezoekdatum='2026-12-08'")->fetchColumn();
    [$status,$result]=$call([...$base,'bezoekdatum'=>'2026-12-15']);$assert($status===422,'Following Tuesday closed');
    [$status,$result]=$call([...$base,'onderwijsSector'=>'voortgezetOnderbouw']);$assert($status===422,'Morning not opened for VO');
    $pdo->exec("INSERT INTO disabled_dates(datum,type,reden) VALUES('2026-12-08','blocked','Test closure')");
    [$status,$result]=$call($base);$assert($status===422,'Period cannot bypass blocked day');
    $pdo->exec("DELETE FROM disabled_dates WHERE datum='2026-12-08'");
    [$status,$result]=$call([...$base,'schoolnaam'=>'Verplaatstest','bezoekdatum'=>'2026-12-09']);$assert($status===200,'Normal Wednesday remains open');
    $moveId=(int)$pdo->query("SELECT id FROM aanvragen WHERE schoolnaam='Verplaatstest'")->fetchColumn();
    $dates=(new BookingVisitDateChangeServiceFactory($pdo))->create();
    $dateCalendar=new DashboardBookingVisitDateCalendarService($bookings,$calendar,$daySettings,$disabled,new StoredBookingVisitDateValidator($disabled,$programValidator));
    $days=$dateCalendar->get($moveId,'2026-12-07','2026-12-15',new DateTimeImmutable('2026-11-02'))['days'];
    $byDate=array_column($days,null,'date');
    $assert($byDate['2026-12-08']['selectable']&&!$byDate['2026-12-15']['selectable'],'Dashboard calendar same period');
    $move=$dates->change(new BookingVisitDateChangeCommand($moveId,'2026-12-09','2026-12-08',1),new DateTimeImmutable('2026-11-02'));
    $assert($move->code===BookingVisitDateChangeCode::Success,'Move morning to Tuesday: '.$move->code->value);
    $move=$dates->change(new BookingVisitDateChangeCommand($moveId,'2026-12-08','2026-12-15',1),new DateTimeImmutable('2026-11-02'));
    $assert($move->code===BookingVisitDateChangeCode::InvalidStoredBooking,'Unavailable Tuesday must fail');
    $assert($bookings->findById($moveId)->visitDate==='2026-12-08','Failed move must preserve date');

    $five=[...$base,'schoolnaam'=>'Vijfmoduletest','programma'=>'dag','programVariant'=>'five','keuzemodule'=>'Earth-Watch','bezoekdatum'=>'2026-12-10'];
    [$status,$result]=$call($five);$assert($status===200,'Existing five request');
    $fiveId=(int)$pdo->query("SELECT id FROM aanvragen WHERE schoolnaam='Vijfmoduletest'")->fetchColumn();
    $before=$pdo->query('SELECT * FROM aanvragen ORDER BY id')->fetchAll();
    $priceBefore=$pdo->query('SELECT * FROM booking_price_snapshots ORDER BY id')->fetchAll();
    $assert($access->saveConfiguration(false,[$rule],1,1,'planner')['ok'],'Close five');
    $assert($before===$pdo->query('SELECT * FROM aanvragen ORDER BY id')->fetchAll(),'Configuration mutated requests');
    $assert($priceBefore===$pdo->query('SELECT * FROM booking_price_snapshots ORDER BY id')->fetchAll(),'Configuration mutated prices');
    foreach($rosterBefore as $table=>$rows)$assert($rows===$pdo->query("SELECT * FROM `$table`")->fetchAll(),'Configuration mutated '.$table);
    [$status,$result]=$call($five);$assert($status===422,'New five submission blocked');
    $priceAction=new BookingPriceQuoteAction($response,new BookingPriceCalculator(),$programValidator,new StudentCountValidator(),new SupervisorCountValidator(),new FoodAndDrinkSelectionValidator());
    [$status,$result]=$capture(fn()=>$priceAction->send($five));$assert($status===422,'Closed five quote blocked');
    $rosterAction=new BookingRosterAction($response,new BookingRosterResolver(new RosterSqlService($pdo),new RosterGroupCountResolver()),$programValidator,new EducationSelectionValidator(),new ChoiceModuleSelectionValidator(),new StudentCountValidator(),new SupervisorCountValidator());
    [$status,$result]=$capture(fn()=>$rosterAction->send($five));$assert($status===422,'Closed five example blocked before lookup');
    $four=[...$five,'programVariant'=>'four','bezoekdatum'=>'2027-01-05'];
    [$status,$result]=$capture(fn()=>$priceAction->send($four));$assert($status===200,'Four quote remains available');
    [$status,$result]=$capture(fn()=>$rosterAction->send($four));$assert($status===200,'Four example remains available');
    [$status,$result]=$call([...$four,'schoolnaam'=>'Viermoduletest']);$assert($status===200,'Four submission remains available');
    $assert($bookings->findById($fiveId)->programVersion===null,'Existing five not silently converted');
    $storedValidation=(new StoredBookingValidator($disabled))->validateForTargetStatus($bookings->findById($fiveId),'Definitief',new DateTimeImmutable('2026-11-02'));
    $assert(!$storedValidation->hasCode('CURRENT_CONFIGURATION_MISMATCH'),'Existing five remains confirmable after close');
    $assert($dates->change(new BookingVisitDateChangeCommand($fiveId,'2026-12-10','2026-12-11',1))->code===BookingVisitDateChangeCode::InvalidStoredBooking,'New five date blocked');
    $assert($dates->change(new BookingVisitDateChangeCommand($fiveId,'2026-12-10','2026-12-10',1))->code===BookingVisitDateChangeCode::NoVisitDateChange,'Existing closed-date no-op preserved');

    $pdo->beginTransaction();
    try { $access->assertCanSubmit('dag',null,'2026-12-10');$assert(false,'Transactional recheck bypassed'); }
    catch(FieldValidationException){$assert(true,'Transactional recheck');} finally {$pdo->rollBack();}
    $analytic=new BookingAdvancedAnalyticsCalculator($disabled,$daySettings,programAccess:$repo);
    $criteria=new BookingAnalyticsCriteria('2026-12-07','2026-12-11','2026-12-07','2026-12-11',new BookingExportDateBounds('2026-12-07','2026-12-11'),program:'ochtend');
    $report=$analytic->calculate([],$criteria,[]);
    $assert($report['capacityByMonth'][0]['availableDays']===2,'Morning analytics must count Tuesday and Wednesday');
    [$status,$result]=$capture(fn()=>(new BookingProgramConfigAction($response,$repo))->send());
    $assert($status===200&&$result['data']['availability']['periods'][0]['weekdays']===[2],'Public policy present');
    $assert(!isset($result['data']['availability']['periods'][0]['note']),'Internal note leaked');
    $assert($result['data']['availability']===$result['data']['fourModuleConfig']['availability'],'Both variants share configuration');
    $manualService = new GeoFort\Services\Dashboard\Roster\RosterPlanService($pdo, $bookings, $rosters['plans'], new RosterGroupCountResolver(), $rosters['sessions'], $rosters['config'], $rosters['templates'], $rosters['staff'], null, $rosters['snapshots'], $repo);
    $morningPlan = $manualService->createManual('Extra ochtend', 'Herwijnen', '2026-12-08', 'primairOnderwijs', 'ochtend', 40, 3, 1)['plan'];
    $validation = $rosters['validator']->validate($morningPlan['id'], $morningPlan['revision']);
    $assert(!in_array('PROGRAM_WEEKDAY_OVERRIDE', array_column($validation->warnings, 'code'), true), 'Configured morning roster needs no weekday warning');
    $assert($access->saveConfiguration(false,[],2,1,'planner')['ok'],'Remove period');
    $validation = $rosters['validator']->validate($morningPlan['id'], $morningPlan['revision']);
    $assert(in_array('PROGRAM_WEEKDAY_OVERRIDE', array_column($validation->warnings, 'code'), true), 'Existing morning outside later configuration is a warning');
    $assert(!in_array('PROGRAM_WEEKDAY_OVERRIDE', array_column($validation->errors, 'code'), true), 'Later configuration cannot invalidate an existing roster');
    try { $manualService->createManual('Gesloten ochtend', 'Herwijnen', '2026-12-15', 'primairOnderwijs', 'ochtend', 40, 3, 1); $assert(false, 'Unopened manual morning accepted'); }
    catch (GeoFort\Services\Dashboard\Roster\RosterPlanException) { $assert(true, 'Unopened manual morning blocked without explicit maatwerk exception'); }
    $configBeforeFailure = $repo->configuration();
    $historyBeforeFailure = $pdo->query('SELECT * FROM booking_program_access_history ORDER BY id')->fetchAll();
    $pdo->exec("CREATE TRIGGER fail_availability_audit BEFORE INSERT ON booking_program_access_history FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='forced audit failure'");
    try { $repo->saveConfiguration(true, [$rule], $configBeforeFailure['revision'], 1); $assert(false, 'Audit failure was ignored'); }
    catch (PDOException) { $assert($repo->configuration() === $configBeforeFailure && $pdo->query('SELECT * FROM booking_program_access_history ORDER BY id')->fetchAll() === $historyBeforeFailure, 'Audit failure must roll back both settings tables and history'); }
    finally { $pdo->exec('DROP TRIGGER fail_availability_audit'); }
    $storedValidation=(new StoredBookingValidator($disabled))->validateForTargetStatus($bookings->findById($tuesdayId),'Definitief',new DateTimeImmutable('2026-11-02'));
    $assert(!$storedValidation->hasCode('CURRENT_CONFIGURATION_MISMATCH'),'Existing agreed Tuesday remains confirmable');
    $assert($mailer->sent===4,'Only four captured request mails, none for settings/date edits');
    $assert((new BookingProgramAccessService(new BookingProgramAccessSqlRepository($db->connect())))->saveConfiguration(true,[],1,1,'planner')['code']==='CONFLICT','Separate stale connection rejected');
    echo "PASS {$checks} MariaDB checks: real public submissions/quotes/examples, Tuesday calendar/move, blocked dates, existing agreements/prices/rosters, analytics, snapshots, audit and stale writes; captured mail only\n";
} finally {$db->drop();}

<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/vendor/autoload.php';
require __DIR__.'/Support/DisposableBookingMariaDb.php';

use GeoFort\Services\Booking\Availability\BookingAvailabilityService;
use GeoFort\Services\Booking\Presentation\EducationSelectionSummaryFactory;
use GeoFort\Services\Booking\Pricing\BookingPriceSnapshotServiceFactory;
use GeoFort\Services\Booking\Submission\BookingSubmissionService;
use GeoFort\Services\Http\Api\Booking\BookingFormHandler;
use GeoFort\Services\Http\Response\JsonResponse;
use GeoFort\Services\Http\Url\EnvironmentBaseUrlProvider;
use GeoFort\Services\Mail\{BookingMailService,MailConfig,MailInterface};
use GeoFort\Services\Mail\Templates\{BookingRequestMailTemplate,MailLayout,MailLinks};
use GeoFort\Services\Sql\{BookingCalendarSqlService,BookingDaySettingsSqlRepository,DisabledDatesSqlService,EducationSelectionSqlService,FormSubmitLogService,RequestService};
use GeoFort\Validation\{Validator,EducationSelectionValidator,ProgramSelectionValidator,ChoiceModuleSelectionValidator,StudentCountValidator,SupervisorCountValidator,FoodAndDrinkSelectionValidator,TermsAcceptanceValidator};

$db=DisposableBookingMariaDb::create('public_handler');$pdo=$db->pdo;
$assert=static function(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);};
try {
    $pdo->exec(file_get_contents(dirname(__DIR__,2).'/database/sql/2026-10-30_create_visit_surveys.sql'));
    $mailer=new class implements MailInterface {
        public int $sent=0;
        public function send(string $toEmail,string $toName,string $subject,string $htmlBody,string $textBody,array $cc=[],array $attachments=[],array $bcc=[]):void{$this->sent++;}
    };
    $links=new MailLinks('https://test.example','https://test.example/voorwaarden','onderwijs@example.test');
    $mail=new BookingMailService($mailer,new MailConfig('',0,'','','','noreply@example.test','GeoFort','planner@example.test',null,[],'testing'),new BookingRequestMailTemplate(new MailLayout($links),$links,new Validator(),new EducationSelectionSummaryFactory()));
    $availability=new BookingAvailabilityService(new BookingCalendarSqlService($pdo),new DisabledDatesSqlService($pdo));
    $submission=new BookingSubmissionService($pdo,new FormSubmitLogService($pdo),new RequestService($pdo),new EducationSelectionSqlService($pdo),$mail,new BookingDaySettingsSqlRepository($pdo),$availability,(new BookingPriceSnapshotServiceFactory($pdo))->create());
    $handler=new BookingFormHandler(new JsonResponse(new EnvironmentBaseUrlProvider('development','https://onderwijsformulier.test')),new Validator(),new EducationSelectionValidator(),new FormSubmitLogService($pdo),$availability,$submission,new ProgramSelectionValidator(),new ChoiceModuleSelectionValidator(),new StudentCountValidator(),new SupervisorCountValidator(),new FoodAndDrinkSelectionValidator(),new TermsAcceptanceValidator(),'192.0.2.55',0);
    $call=static function(array $post)use($handler):array{
        ob_start();try{$handler->handle($post);$body=ob_get_contents();}finally{ob_end_clean();}
        return [http_response_code(),json_decode($body,true,512,JSON_THROW_ON_ERROR)];
    };
    $start=new DateTimeImmutable('next wednesday +4 weeks');
    if($start->format('Y-m-d')<'2027-01-04')$start=new DateTimeImmutable('2027-02-03');
    $base=['schoolnaam'=>'Formulier test','land'=>'Nederland','adres'=>'Dijk 1','postcode'=>'1234 AB','plaats'=>'Herwijnen','schoolTelefoonnummer'=>'0345123456','contactpersoonTelefoonnummer'=>'0612345678','contactpersoonVoornaam'=>'Sanne','contactpersoonAchternaam'=>'Jansen','email'=>'sanne@example.test','hoeKentUGeoFort'=>'Via de website van GeoFort','cjpPasGebruik'=>'nee','onderwijsSector'=>'primairOnderwijs','programma'=>'dag','programVariant'=>'four','keuzemodule'=>'Earth-Watch','aantalLeerlingen'=>'60','aantalBegeleiders'=>'4','educationSelection'=>json_encode(['sector'=>'primairOnderwijs','selectedLevels'=>['regulier'],'selectedGroupsByLevel'=>['regulier'=>['groep7']]]),'lunchChoice'=>'eigen_picknick','voorwaardenAkkoord'=>'1'];
    $index=0;
    foreach(['four','five','morning'] as $variant)foreach(['0','1'] as $consent){
        $post=[...$base,'schoolnaam'=>'Formulier test '.++$index,'bezoekdatum'=>$start->modify('+'.$index.' weeks')->format('Y-m-d'),'programVariant'=>$variant==='morning'?'auto':$variant,'programma'=>$variant==='morning'?'ochtend':'dag','keuzemodule'=>$variant==='four'?'Earth-Watch':($variant==='five'?'Earth-Watch':''),'evaluationConsent'=>$consent];
        [$status,$result]=$call($post);
        $assert($status===200&&($result['ok']??false)===true,"Public handler $variant consent=$consent failed: HTTP $status ".json_encode($result));
        $id=(int)$pdo->query('SELECT MAX(id) FROM aanvragen')->fetchColumn();
        $hasConsent=(int)$pdo->query('SELECT COUNT(*) FROM visit_survey_consents WHERE booking_id='.$id)->fetchColumn();
        $assert($hasConsent===(int)$consent,'Consent did not reach the saved request');
        $assert($mailer->sent===$index,'Exactly one captured confirmation expected per accepted request');
    }
    unset($post['evaluationConsent']);
    $post['schoolnaam']='Formulier zonder evaluatieveld';$post['bezoekdatum']=$start->modify('+8 weeks')->format('Y-m-d');
    [$status,$result]=$call($post);
    $assert($status===200&&$result['ok']===true,'Older form without consent field must still submit');
    $assert((int)$pdo->query('SELECT COUNT(*) FROM visit_survey_consents')->fetchColumn()===3,'Missing field inferred consent');
    foreach([['evaluationConsent'=>['1']],['voorwaardenAkkoord'=>'0']] as $invalid){
        [$status,$result]=$call([...$post,...$invalid]);
        $field=array_key_first($invalid);
        $assert($status===422&&isset($result['fieldErrors'][$field]),'Invalid '.$field.' must return a field error, not HTTP 500');
    }
    $assert((int)$pdo->query('SELECT COUNT(*) FROM aanvragen')->fetchColumn()===7&&$mailer->sent===7,'Invalid requests persisted or attempted mail');
    echo "PASS public BookingFormHandler: four/five/morning, checked/unchecked/missing consent, validation errors, saved consent and captured confirmation mail; disposable database only\n";
}finally{$db->drop();}

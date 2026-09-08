<?php

declare(strict_types=1);

require dirname(__DIR__,2).'/vendor/autoload.php';
require __DIR__.'/Support/DisposableBookingMariaDb.php';

use GeoFort\Booking\BookingPolicy;
use GeoFort\Services\Booking\Availability\BookingAvailabilityService;
use GeoFort\Services\Booking\Data\{BookingRequestData,EducationSelectionData,FoodAndDrinkSelectionData};
use GeoFort\Services\Booking\Presentation\EducationSelectionSummaryFactory;
use GeoFort\Services\Booking\Pricing\{BookingPriceCatalogRegistry,BookingPriceSnapshotServiceFactory};
use GeoFort\Services\Booking\Submission\BookingSubmissionService;
use GeoFort\Services\Mail\{Attachment,BookingMailService,MailConfig,MailInterface};
use GeoFort\Services\Mail\Templates\{BookingRequestMailTemplate,MailLayout,MailLinks};
use GeoFort\Services\Sql\{BookingCalendarSqlService,BookingDaySettingsSqlRepository,DisabledDatesSqlService,EducationSelectionSqlService,FormSubmitLogService,RequestService};
use GeoFort\Validation\Validator;

final class SubmissionAuditMailer implements MailInterface
{
    public int $sent=0;public string $text='';public bool $fail=false;
    public function __construct(private PDO $pdo){}
    /** @param list<string> $cc @param list<Attachment> $attachments */
    public function send(string $toEmail,string $toName,string $subject,string $htmlBody,string $textBody,array $cc=[],array $attachments=[],array $bcc=[]):void
    {
        if($this->pdo->inTransaction())throw new RuntimeException('Mail werd voor commit verzonden.');
        if((int)$this->pdo->query('SELECT COUNT(*) FROM booking_price_snapshots')->fetchColumn()<1)throw new RuntimeException('Mail werd zonder opgeslagen snapshot verzonden.');
        $this->sent++;$this->text=$textBody;if($this->fail)throw new RuntimeException('Test SMTP failure');
    }
}

$disposable=DisposableBookingMariaDb::create('submission');$pdo=$disposable->pdo;
$assert=static function(bool $condition,string $message):void{if(!$condition)throw new RuntimeException($message);};
$request=static fn(string $school,string $date):BookingRequestData=>new BookingRequestData($school,'Nederland','Dijk 1','1234 AB','Plaats','0345123456','0612345678','Sanne','Jansen','sanne@example.test',$date,$date,'Test','nee',null,null,'primairOnderwijs',BookingPolicy::PROGRAM_DAY,'Earth-Watch',31,4,new EducationSelectionData('primairOnderwijs',['regulier'],['regulier'=>['groep5']]),FoodAndDrinkSelectionData::fromStoredValues(3,0,0,0,0,0,true),null,true);
$service=static function(SubmissionAuditMailer $mailer)use($pdo):BookingSubmissionService{
    $links=new MailLinks('https://test.example','https://test.example/voorwaarden','onderwijs@example.test');
    $mailService=new BookingMailService($mailer,new MailConfig('',0,'','','','noreply@example.test','GeoFort','planner@example.test',null,[],'testing'),new BookingRequestMailTemplate(new MailLayout($links),$links,new Validator(),new EducationSelectionSummaryFactory()));
    $disabled=new DisabledDatesSqlService($pdo);
    return new BookingSubmissionService($pdo,new FormSubmitLogService($pdo),new RequestService($pdo),new EducationSelectionSqlService($pdo),$mailService,new BookingDaySettingsSqlRepository($pdo),new BookingAvailabilityService(new BookingCalendarSqlService($pdo),$disabled),(new BookingPriceSnapshotServiceFactory($pdo))->create());
};
try{
    $mailer=new SubmissionAuditMailer($pdo);$successfulResult=$service($mailer)->submit($request('Submissiontest','2027-03-10'),'192.0.2.10');
    $bookingId=(int)$pdo->query("SELECT id FROM aanvragen WHERE schoolnaam='Submissiontest'")->fetchColumn();$snapshot=$pdo->query("SELECT * FROM booking_price_snapshots WHERE booking_id={$bookingId}")->fetch();
    $expectedTotal='€ '.number_format(((int)$snapshot['total_amount_incl_vat_cents'])/100,2,',','.');
    $assert($bookingId>0&&$snapshot!==false&&$snapshot['snapshot_reason']==='submission'&&$snapshot['pricing_version']===BookingPriceCatalogRegistry::ACTIVE_VERSION,'Echte submission sloeg booking/snapshot/versie niet op.');
    $assert((int)$pdo->query("SELECT COUNT(*) FROM aanvraag_onderwijs_selecties WHERE aanvraag_id={$bookingId}")->fetchColumn()===1&&(int)$pdo->query("SELECT COUNT(*) FROM form_submit_log WHERE ip_address='192.0.2.10'")->fetchColumn()===1,'Selection of submitlog volgt submissiontransactie niet.');
    $assert($successfulResult->mailSent&&$mailer->sent===1&&str_contains(str_replace("\u{00A0}",' ',$mailer->text),str_replace("\u{00A0}",' ',$expectedTotal)),'Submissionmail bevat niet exact het opgeslagen snapshottotaal.');

    $pdo->exec("CREATE TRIGGER fail_real_submission_snapshot BEFORE INSERT ON booking_price_snapshots FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='forced snapshot failure'");
    $failed=false;try{$service(new SubmissionAuditMailer($pdo))->submit($request('Submission rollback','2027-03-11'),'192.0.2.11');}catch(RuntimeException){$failed=true;}$pdo->exec('DROP TRIGGER fail_real_submission_snapshot');
    $assert($failed&&(int)$pdo->query("SELECT COUNT(*) FROM aanvragen WHERE schoolnaam='Submission rollback'")->fetchColumn()===0&&(int)$pdo->query("SELECT COUNT(*) FROM form_submit_log WHERE ip_address='192.0.2.11'")->fetchColumn()===0&&(int)$pdo->query("SELECT COUNT(*) FROM booking_day_settings WHERE visit_date='2027-03-11'")->fetchColumn()===0,'Snapshotfout liet halve booking/selection/submitlog/availabilitylock achter.');

    $failingMailer=new SubmissionAuditMailer($pdo);$failingMailer->fail=true;$mailFailureResult=$service($failingMailer)->submit($request('Submission mail failure','2027-03-12'),'192.0.2.12');
    $mailFailureId=(int)$pdo->query("SELECT id FROM aanvragen WHERE schoolnaam='Submission mail failure'")->fetchColumn();
    $assert(!$mailFailureResult->mailSent&&$failingMailer->sent===1&&$mailFailureId>0&&(int)$pdo->query("SELECT COUNT(*) FROM booking_price_snapshots WHERE booking_id={$mailFailureId}")->fetchColumn()===1,'Mailfalen wordt niet gerapporteerd of verwijdert gecommitte booking/snapshot.');
    fwrite(STDOUT,"OK: echte publieke BookingSubmissionService MariaDB-integratie geslaagd.\n");
}finally{$disposable->drop();}

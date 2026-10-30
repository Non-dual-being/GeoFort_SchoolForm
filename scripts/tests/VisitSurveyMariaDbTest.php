<?php
declare(strict_types=1);
require __DIR__ . '/Support/RosterLifecycleFixtures.php';

use GeoFort\Services\Survey\{VisitSurveyPolicy, VisitSurveyService};
use GeoFort\Services\Sql\VisitSurveySqlRepository;
use GeoFort\Services\Mail\MailInterface;
use GeoFort\Services\Mail\Templates\{MailLayout, MailLinks};

final class CapturedVisitMailer implements MailInterface
{
    public array $messages = [];
    public bool $fail = false;
    public function send(string $toEmail,string $toName,string $subject,string $htmlBody,string $textBody,array $cc=[],array $attachments=[],array $bcc=[]): void
    {
        if ($this->fail) throw new RuntimeException('Simulated transport failure');
        $this->messages[] = compact('toEmail','toName','subject','htmlBody','textBody','cc','bcc');
    }
}

$db = DisposableBookingMariaDb::create('visit_survey'); $pdo = $db->pdo;
$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void { $checks++; if (!$condition) throw new RuntimeException($message); };
$reject = static function (callable $fn) use ($assert): void { try { $fn(); } catch (InvalidArgumentException|\GeoFort\Validation\FieldValidationException) { $assert(true,'reject'); return; } throw new RuntimeException('Invalid input accepted'); };
try {
    RosterLifecycleFixtures::schema($pdo);
    $pdo->exec(file_get_contents(dirname(__DIR__,2) . '/database/sql/2026-10-30_create_visit_surveys.sql'));
    $repo = new VisitSurveySqlRepository($pdo); $service = new VisitSurveyService($repo);
    $now = new DateTimeImmutable('2026-11-10 09:00:00', new DateTimeZone('Europe/Amsterdam'));
    $make = function (string $school, bool $consent = true, array $modules = ['Earth-Watch','Dynamische-Globe'], string $date = '2026-11-06', string $status = 'Definitief', string $sector = 'primairOnderwijs', ?string $version = null, string $program = 'dag') use ($pdo, $repo): int {
        $pdo->prepare("INSERT INTO aanvragen(status,schoolnaam,land,adres,postcode,plaats,school_telefoonnummer,contactpersoon_telefoonnummer,contactpersoon_voornaam,contactpersoon_achternaam,email,bezoekdatum,onderwijs_sector,programma,program_version,aantal_leerlingen,aantal_begeleiders,voorwaarden_akkoord)
            VALUES (?,?,'Nederland','Dijk 1','1234 AB','Plaats','0101234567','0612345678','Sanne','Jansen','contact@example.test',?,?,?,?,25,2,1)")->execute([$status,$school,$date,$sector,$program,$version]);
        $id = (int) $pdo->lastInsertId();
        if ($consent) $repo->recordConsent($id, 'contact@example.test');
        if ($modules !== []) {
            $pdo->prepare("INSERT INTO roster_plans(booking_id,planning_mode,visit_date,status,revision,created_by_admin_id,updated_by_admin_id) VALUES (?,'booking_locked',?,'concept',1,1,1)")->execute([$id,$date]);
            $plan = (int) $pdo->lastInsertId();
            foreach ($modules as $key) $pdo->prepare("INSERT INTO roster_sessions(roster_plan_id,item_type,module_key,start_time,end_time,created_by_admin_id,updated_by_admin_id) VALUES (?,'activity',?,'10:00','10:45',1,1)")->execute([$plan,$key]);
        }
        return $id;
    };
    $ids = [];
    $ids['normal'] = $make('De rivier');
    $ids['four'] = $make('De globe', true, ['Klimaat-Experience','Voedsel-Innovatie'], '2027-01-08', 'Definitief', 'voortgezetOnderbouw', 'day-four-2027');
    $ids['noConsent'] = $make('Geen toestemming', false);
    $ids['test'] = $make('PLANNERTEST Waterkant PO');
    $ids['rejected'] = $make('Afgewezen bezoek',true,[],status:'Afgewezen');
    $ids['option'] = $make('Optiebezoek',true,[],status:'In optie');
    $ids['missing'] = $make('Geen opgeslagen lessen',true,[]);
    $ids['future'] = $make('Volgend bezoek',true,date:'2026-12-01');
    $ids['old'] = $make('Oud bezoek',true,date:'2026-09-01');
    $ids['changed'] = $make('Ander contact'); $pdo->exec("UPDATE aanvragen SET email='other@example.test' WHERE id={$ids['changed']}");
    $ids['excluded'] = $make('Niet gekomen'); $service->exclude($ids['excluded'],1);
    $ids['so'] = $make('Speciaal onderwijs',true,['Earth-Watch','Dynamische-Globe'],program:'ochtend');
    $pdo->prepare("INSERT INTO aanvraag_onderwijs_selecties(aanvraag_id,sector_key,level_key) VALUES (?,'primairOnderwijs','speciaal')")->execute([$ids['so']]);
    $ids['linked'] = $make('Tweede gekoppelde school');
    $ids['postponed'] = $make('Bezoek verplaatst'); $pdo->exec("UPDATE aanvragen SET bezoekdatum='2026-11-09' WHERE id={$ids['postponed']}");
    $sourceBefore = serialize([$pdo->query('SELECT * FROM aanvragen ORDER BY id')->fetchAll(),$pdo->query('SELECT * FROM roster_sessions ORDER BY id')->fetchAll(),$pdo->query('SELECT * FROM roster_plans ORDER BY id')->fetchAll()]);
    $assert($repo->settings()['enabled'] === false, 'Migration enabled mail');
    $mailer = new CapturedVisitMailer(); $layout = new MailLayout(new MailLinks('https://example.test','https://example.test/voorwaarden','onderwijs@example.test'));
    $assert($service->dispatch($mailer,$layout,'https://example.test',$now)['sent'] === 0, 'Disabled sending ran');
    $assert(VisitSurveyPolicy::consent(null) === false && VisitSurveyPolicy::consent('1') === true, 'Consent default');
    $reject(fn () => VisitSurveyPolicy::consent('yes'));
    $reject(fn () => $service->saveSettings(['enabled'=>true,'delayDays'=>0,'startDate'=>'2026-11-10','revision'=>1],1,$now));
    $reject(fn () => $service->saveSettings(['enabled'=>true,'delayDays'=>2,'startDate'=>'2026-10-01','revision'=>1],1,$now));
    $service->saveSettings(['enabled'=>true,'delayDays'=>2,'startDate'=>'2026-10-30','revision'=>1],1,new DateTimeImmutable('2026-10-30'));
    $report = $service->report([], $now); $byId = array_column($report['visits'],null,'bookingId');
    $assert(!isset($byId[$ids['noConsent']]),'Old/nonconsenting booking in queue');
    foreach (['test'=>'test','rejected'=>'not-confirmed','option'=>'not-confirmed','missing'=>'missing-modules','future'=>'scheduled','old'=>'before-start','changed'=>'contact-changed','excluded'=>'excluded','postponed'=>'scheduled'] as $id=>$state) $assert($byId[$ids[$id]]['state']===$state,"Eligibility $id");
    $assert($byId[$ids['postponed']]['dueDate']==='2026-11-11','Moved date did not move invitation');
    $assert($byId[$ids['so']]['sector']==='so','Special education lost');
    $assert($byId[$ids['four']]['variant']==='day-four','Four module identity lost');
    $assert($service->dispatch($mailer,$layout,'https://example.test',$now)['sent']===3,'Wrong eligible visits');
    $assert($service->dispatch($mailer,$layout,'https://example.test',$now)['sent']===0,'Duplicate mail on repeated task');
    foreach ($mailer->messages as $mail) $assert($mail['toEmail']==='contact@example.test' && $mail['cc']===[] && $mail['bcc']===[], 'Wrong envelope');
    $assert(count($mailer->messages)===3, 'Linked schools did not receive individual invitation');
    foreach ($mailer->messages as $mail) $assert(str_contains($mail['htmlBody'],'Beste Sanne,') && str_starts_with($mail['textBody'],'Beste Sanne,'), 'Dispatcher did not use contact first name');
    $tokens=[];
    foreach ($mailer->messages as $mail) { preg_match('/#token=([a-f0-9]{64})/',$mail['textBody'],$match); $tokens[]=$match[1]; }
    $token=$tokens[0]; $open=$service->publicAction($token,'open',[],$now);
    $assert($open['state']==='open' && count($open['modules'])===2, 'Public question snapshot wrong');
    $reject(fn () => $service->publicAction(str_repeat('a',64),'open',[],$now));
    $reject(fn () => $service->publicAction($token,'respond',['satisfaction'=>'satisfied','favoriteModule'=>'Never-followed'],$now));
    $reject(fn () => $service->publicAction($token,'respond',['satisfaction'=>'5'],$now));
    $reject(fn () => $service->publicAction($token,'respond',['satisfaction'=>'neutral','tip'=>str_repeat('woord ',51)],$now));
    $reject(fn () => $service->publicAction($token,'respond',['satisfaction'=>'neutral','tip'=>str_repeat('a',1001)],$now));
    $assert(VisitSurveyPolicy::wordCount("één l'été 你好 leerling-docent 😀")===4,'Unicode word definition');
    $assert(VisitSurveyPolicy::wordCount("e\u{0301}e\u{0301}n woord")===2,'Combining accent split a word');
    $answer=['satisfaction'=>'satisfied','favoriteModule'=>'Earth-Watch','tip'=>'<script>alert(1)</script> Meer buitenlessen, graag!'];
    $assert($service->publicAction($token,'respond',$answer,$now)['state']==='answered','Response failed');
    $service->publicAction($token,'respond',['satisfaction'=>'dissatisfied'],$now);
    $assert((int)$pdo->query('SELECT COUNT(*) FROM visit_survey_responses')->fetchColumn()===1,'Duplicate response');
    $assert($pdo->query('SELECT satisfaction FROM visit_survey_responses')->fetchColumn()==='satisfied','Second response overwrote first');
    $r=$service->report([],$now); $assert($r['responses']===1 && $r['invited']===3 && $r['responseRate']===33.3,'Response denominator wrong');
    $m=array_column($r['modules'],null,'key'); $assert($m['Earth-Watch']['percentage']===100.0 && $m['Dynamische-Globe']['percentage']===0.0,'Exposure denominator wrong');
    $assert($service->report(['sector'=>'so'],$now)['invited']===1,'SO filter wrong');
    $assert($service->report(['variant'=>'day-four'],$now)['responses']===0,'Variant filter wrong');
    $assert($service->publicAction($tokens[1],'open',[],$now->modify('+31 days'))['state']==='expired','Expired link active');
    $service->publicAction($token,'withdraw',[],$now->modify('+31 days'));
    $assert((int)$pdo->query('SELECT COUNT(*) FROM visit_survey_responses')->fetchColumn()===0,'Withdrawal retained response');
    $assert($service->publicAction($token,'open',[],$now)['state']==='withdrawn','Withdrawn link active');
    $assert($sourceBefore===serialize([$pdo->query('SELECT * FROM aanvragen ORDER BY id')->fetchAll(),$pdo->query('SELECT * FROM roster_sessions ORDER BY id')->fetchAll(),$pdo->query('SELECT * FROM roster_plans ORDER BY id')->fetchAll()]),'Survey changed requests or rosters');
    // An ambiguous failure is recorded for review and is never resent automatically.
    $failed = $make('Transportcontrole'); $mailer->fail=true;
    $assert($service->dispatch($mailer,$layout,'https://example.test',$now)['failed']===1,'Mail failure not recorded');
    $mailer->fail=false; $assert($service->dispatch($mailer,$layout,'https://example.test',$now)['sent']===0,'Ambiguous delivery retried');
    $repo2=new VisitSurveySqlRepository($db->connect());
    $repo->withLock($failed,function() use($repo2,$failed,$assert) { try { $repo2->withLock($failed,fn()=>null); } catch(RuntimeException) { $assert(true,'lock'); return; } throw new RuntimeException('Concurrent worker acquired same visit'); });
    // Reports retain the invitation's module/program identity when a later administrative edit occurs.
    $pdo->exec("UPDATE roster_sessions s JOIN roster_plans p ON p.id=s.roster_plan_id SET s.module_key='Crisismanagement' WHERE p.booking_id={$ids['normal']}");
    $afterEdit=array_column($service->report([],$now)['visits'],null,'bookingId');
    $assert(array_column($afterEdit[$ids['normal']]['modules'],'key')===['Dynamische-Globe','Earth-Watch'],'Historical modules drifted after edit');
    $pdo->exec("UPDATE aanvragen SET status='Afgewezen' WHERE id={$ids['normal']}");
    $normalInvitation=$pdo->query("SELECT token_hash FROM visit_survey_invitations WHERE booking_id={$ids['normal']}")->fetchColumn();
    foreach($tokens as $t) if(hash('sha256',$t)===$normalInvitation) $assert($service->publicAction($t,'open',[],$now)['state']==='unavailable','Rejected booking still accepts survey');
    echo "PASS $checks visit-survey checks: explicit consent, eligibility, frozen modules, one delivery/response, Unicode, filters, withdrawal, no application writes; all mail captured\n";
} finally { $db->drop(); }

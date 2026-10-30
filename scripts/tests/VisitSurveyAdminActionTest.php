<?php
declare(strict_types=1);
require __DIR__.'/Support/RosterLifecycleFixtures.php';
use GeoFort\Services\Http\Api\Admin\DashboardVisitSurveyAction;
use GeoFort\Services\Survey\VisitSurveyService;
use GeoFort\Services\Sql\{VisitSurveySqlRepository, AdminUsersSqlService};
use GeoFort\Services\Auth\CsrfTokenService;
use GeoFort\Services\Http\Response\JsonResponse;
use GeoFort\Services\Http\Url\EnvironmentBaseUrlProvider;
use GeoFort\Security\{AuthMiddleware,SessionGuard};

ob_start();
$db=DisposableBookingMariaDb::create('survey_admin');
try {
    RosterLifecycleFixtures::schema($db->pdo);
    $db->pdo->exec(file_get_contents(dirname(__DIR__,2).'/database/sql/2026-10-30_create_visit_surveys.sql'));
    $assert=RosterLifecycleFixtures::assert(...); $csrf=new CsrfTokenService();
    $auth=new AuthMiddleware('survey_admin_contract','Lax',false);$auth->startPublicSession();
    $service=new VisitSurveyService(new VisitSurveySqlRepository($db->pdo));
    $action=new DashboardVisitSurveyAction($auth,new SessionGuard(new AdminUsersSqlService($db->pdo,5),3600,900),$csrf,$service,new JsonResponse(new EnvironmentBaseUrlProvider('development','https://onderwijsformulier.test')),'development');
    $send=function(string $method,?string $token=null,array $body=[],array $filters=[])use($action):array{
        ob_start();try{$action->send($method,'application/json',$token,json_encode($body),'survey-test',$filters);$data=json_decode(ob_get_contents(),true,512,JSON_THROW_ON_ERROR);}finally{ob_end_clean();}return[http_response_code(),$data];
    };
    $assert($send('GET')[0]===401,'Guest can read evaluations');
    $login=function(string $role)use($auth):void{$auth->establishAuthenticatedSession(['id'=>1,'email'=>'admin@example.test','name'=>'Fixture admin','role'=>$role],null,'survey-test');};
    $login('viewer');$assert($send('GET')[0]===403,'Viewer can access personal tips');
    $login('planner');[$status,$data]=$send('GET');
    $assert($status===200 && $data['local']===true && $data['data']['settings']['enabled']===false,'Planner report failed');
    $input=['action'=>'settings','enabled'=>false,'delayDays'=>3,'startDate'=>null,'revision'=>1];
    $assert($send('POST',null,$input)[0]===403,'Missing CSRF accepted');
    $assert($send('POST',$csrf->getOrCreate('another-action'),$input)[0]===403,'Cross-action CSRF accepted');
    $assert($send('POST',$data['csrfToken'],$input)[0]===200,'Valid settings rejected');
    $assert($send('POST',$data['csrfToken'],$input)[0]===422,'Stale settings accepted');
    $assert($send('GET',filters:['from'=>'2026-02-30'])[0]===422,'Invalid report date accepted');
    $assert($send('GET',filters:['sector'=>'anything'])[0]===422,'Invalid sector accepted');
    $assert($send('DELETE')[0]===405,'Unsupported method accepted');
    $assert((new VisitSurveySqlRepository($db->pdo))->settings()['delayDays']===3,'Wrong delay persisted');
    ob_end_clean();echo "PASS survey admin HTTP action: auth, role, scoped CSRF, stale settings, filters, local state and method guard\n";
} finally {$db->drop();if(ob_get_level())ob_end_clean();}

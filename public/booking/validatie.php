<?php
declare(strict_types=1);

use GeoFort\Database\Connector;

use GeoFort\Services\Booking\BookingSubmissionService;

use GeoFort\Services\Http\GlobalBaseUrlProvider;
use GeoFort\Services\Http\JsonResponse;
use GeoFort\Services\Http\ClientIpResolver;
use GeoFort\Services\Http\IpResult;
use GeoFort\Services\Http\BookingFormHandler;

use GeoFort\Services\Mail\MailConfig;
use GeoFort\Services\Mail\BookingMailService;
use GeoFort\Services\Mail\PhpMailerMailer;
use GeoFort\Services\Mail\Template\BookingRequestTemplate;

use GeoFort\Services\Sql\FormSubmitLogService;
use GeoFort\Services\Sql\RequestService;

use GeoFort\Validation\Validator;



$container = require_once __DIR__ . '/../../bootstrap.php';
$response = null;

ob_start();
try {

    //set up basics and pdo connection

    $pdo                    = $container['db'][Connector::class];
    $urlProvider            = $container['http'][GlobalBaseUrlProvider::class];
    $coolDown               = $container['config']['app_cooldown'] ?? 30;
    $response               = new JsonResponse($urlProvider);
    $method                 = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $env                    = $container['config']['app_env'];

    if ($method !== 'POST'){
        $response->methodNotAllowed()->send();
        return;
    }
    
    $ipResult               = ClientIpResolver::getClientIp($_SERVER);

    if ($ipResult->hasError || $ipResult->ip === null){
        $response->serverError(
            'Ongeldig verzoek',
            400,
            false
        );
        return;
    } 


    //validation, submit and database insert 

    $validator              = new Validator();
    $submitSqlService       = new FormSubmitLogService($pdo);
    $requestService         = new RequestService($pdo);
    $requestSubmitService   = new BookingSubmissionService(
                                    pdo: $pdo,
                                    submitSqlLogService: $submitSqlService,
                                    requestService: $requestService  
                                    
                            );

    //mail


    $mailConfig             = new MailConfig(
        host:               $container['mail']['mail_host'],
        port:               $container['mail']['mail_port'],
        username:           $container['mail']['mail_planner_email_user'],
        password:           $container['mail']['mail_planner_email_pwd'],
        fromEmail:          $container['mail']['mail_planner_email_user'],
        fromName:           'GeoFort Onderwijs',
        plannerEmail:       $container['mail']['mail_planner_email_user'],
        testReceiverEmail:  $container['mail']['mail_receiver_email_user'], 
    );

    $mailTemplate           = new BookingRequestTemplate();
    $mailer                 = new PhpMailerMailer($mailConfig);

    $mailBookingService     = new BookingMailService(
        mailer:     $mailer,
        config:     $mailConfig,
        template:   $mailTemplate
    );

    $handler                = new BookingFormHandler(
                                    response: $response,
                                    validator: $validator,
                                    submitSqlLogService: $submitSqlService,
                                    submissionService: $requestSubmitService,
                                    ip: $ipResult->ip,
                                    cooldownSeconds: $coolDown                                    
                            );
    

    $handler->handle($_POST);

} catch(\Throwable $e){
    ob_end_clean();
    error_log($e->getMessage());
    $response->serverError(
        $e->getMessage() ?? 'Kritieke fout',
        500,
        true
    )
    ->send();
}

?>
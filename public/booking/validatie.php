<?php
declare(strict_types=1);
use GeoFort\Services\Booking\BookingSubmissionService;

use GeoFort\Services\Http\GlobalBaseUrlProvider;
use GeoFort\Services\Http\JsonResponse;
use GeoFort\Services\Http\ClientIpResolver;
use GeoFort\Services\Http\IpResult;
use GeoFort\Services\Http\BookingFormHandler;

use GeoFort\Services\Sql\FormSubmitLogService;
use GeoFort\Services\Sql\RequestService;

use GeoFort\Validation\Validator;
use GeoFort\Database\Connector;


$container = require_once __DIR__ . '/../../bootstrap.php';
$response = null;

ob_start();
try {


    $pdo                    = $container['db'][Connector::class];
    $urlProvider            = $container['http'][GlobalBaseUrlProvider::class];
    $response               = new JsonResponse($urlProvider);
    $method                 = $_SERVER['REQUEST_METHOD'] ?? 'GET';

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




    $validator              = new Validator();
    $submitSqlService       = new FormSubmitLogService($pdo);
    $requestService         = new RequestService($pdo);
    $requestSubmitService   = new BookingSubmissionService(
                                    pdo: $pdo,
                                    submitSqlLogService: $submitSqlService,
                                    requestService: $requestService  
                                    
                            );

    $handler                = new BookingFormHandler(
                                    response: $response,
                                    validator: $validator,
                                    submitSqlLogService: $submitSqlService,
                                    submissionService: $requestSubmitService,
                                    ip: $ipResult->ip,
                                    cooldownSeconds: 30                                    
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
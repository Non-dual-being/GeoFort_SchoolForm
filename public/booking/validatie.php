<?php
declare(strict_types=1);
use GeoFort\Booking\BookingSubmissionService;
use GeoFort\Http\BookingFormHandler;

use GeoFort\Services\Http\JsonResponse;
use GeoFort\Services\Http\ClientIpResolver;
use GeoFort\Services\Http\IpResult;
use GeoFort\Service\Sql\FormSubmitLogService;
use GeoFort\Service\Sql\RequestService;

use GeoFort\Validation\Validator;
use GeoFort\Database\Connector;


$container = require_once dirname(__DIR__, 2) . '/bootstrap.php';
$response = null;

try {

    $pdo                    = $container['db'][Connector::class];
    $urlProvider            = $container['http'][BaseUrlProvider::class];
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
    $submitService          = new FormSubmitLogService($pdo);
    $requestService         = new RequestService($pdo);
    $requestSubmitService   = new BookingSubmissionService(
                                    $pdo,
                                    $submitLogService,
                                    $requestService  
                                    
                            );

    $handler                = new BookingFormHandler(
                                    $response,
                                    $validator,
                                    $requestSubmitService,
                                    $submitService,
                                    $ip,
                                    cooldownSeconds: 30                                    
                            );

    $handler->handle($_POST);

} catch(\Throwable $e){
    $reponse->serverError(
        $e->getMessage() ?? 'Kritieke fout',
        500,
        true
    )
    ->send();
}

?>
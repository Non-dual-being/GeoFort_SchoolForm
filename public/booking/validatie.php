<?php
declare(strict_types=1);
use GeoFort\Booking\BookingSubmissionService;
use GeoFort\Http\BookingFormHandler;

use GeoFort\Services\Http\JsonResponse;
use GeoFort\Services\Htpp\ClientIpResolver;
use GeoFort\Services\Http\IpResult;
use GeoFort\Service\Sql\FormSubmitLogService;
use GeoFort\Service\Sql\RequestService;

use GeoFort\Validation\Validator;
use GeoFort\Database\Connector;


$container = require_once dirname(__DIR__, 2) . '/bootstrap.php';

try {

    if ($_SERVER['REQUEST_METHOD'] ?? 'GET' !== 'POST'){
        $response
            ->serverError('Method not allowed', 405, false)
            ->send();

        return;
    } 

    $ipResult = ClientIpResolver::getClientIp($_SERVER);

    if ($ipResult->hasError) {
        $response
            ->serverError('Ongeldig verzoek', 405, false)
            ->send();

        return;

    }

    $ip                     = $ipResult->ip;
    $urlProvider            = $container['http'][BaseUrlProvider::class];
    $pdo                    = $container['db'][Connector::class];

    $response               = new JsonResponse($urlProvider);
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
                                    $submitService,
                                    $ip                                    
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
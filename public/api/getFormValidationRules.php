<?php
declare(strict_types=1);
use GeoFort\Validation\FormRules;
use GeoFort\Services\Http\JsonResponse;
use GeoFort\Services\Http\GlobalBaseUrlProvider;
use GeoFort\Services\Http\FormRulesHandler;


try {
    $container = require_once __DIR__ . '/../../bootstrap.php';
    $baseUrlProvider = $container['http'][GlobalBaseUrlProvider::class];
    $jsonResponse =  new JsonResponse($baseUrlProvider);
    
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
        $jsonResponse->methodNotAllowed()->send();
        exit;
    }

   $formRulesHandler = new FormRulesHandler($jsonResponse);
   $formRulesHandler->handle();

} catch (Throwable $e){
    error_log('Form validation rules fetch error: ' . $e->getMessage());

    if ($jsonResponse  instanceof JsonResponse) {
        $jsonResponse 
            ->serverError('Kritieke fout', 500, false)
            ->send();
        return;
    }

    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');

    echo json_encode([
        'ok' => false,
        'message' => 'Kritieke fout',
    ]);

}
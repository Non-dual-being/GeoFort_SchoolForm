<?php
declare(strict_types=1);
use GeoFort\Services\Http\Response\JsonResponse;
use GeoFort\Services\Http\Url\EnvironmentBaseUrlProvider;
use GeoFort\Services\Http\Api\Booking\BookingProgramConfigAction;
use GeoFort\Booking\BookingProgramConfig;

$jsonResponse = null;
try {
    $container = require_once __DIR__ . '/../../bootstrap.php';

    $baseUrlProvider = $container['http'][EnvironmentBaseUrlProvider::class];

    $jsonResponse =  new JsonResponse($baseUrlProvider);

    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
        $jsonResponse->methodNotAllowed()->send();
        exit;
    }

    $bookingProgramConfigActor = new BookingProgramConfigAction($jsonResponse,
        new \GeoFort\Services\Sql\BookingProgramAccessSqlRepository($container['db'][\GeoFort\Database\Connector::class]));
    $bookingProgramConfigActor->send();

} catch (Throwable $e) {
    error_log('BookingProgramConfig fetch error: ' . $e->getMessage());

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

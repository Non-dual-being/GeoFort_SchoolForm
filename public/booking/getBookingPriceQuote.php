<?php

declare(strict_types=1);

use GeoFort\Services\Booking\Pricing\BookingPriceCalculator;
use GeoFort\Services\Http\Api\Booking\BookingPriceQuoteAction;
use GeoFort\Services\Http\Response\JsonResponse;
use GeoFort\Services\Http\Url\EnvironmentBaseUrlProvider;
use GeoFort\Validation\FoodAndDrinkSelectionValidator;
use GeoFort\Validation\ProgramSelectionValidator;
use GeoFort\Validation\StudentCountValidator;
use GeoFort\Validation\SupervisorCountValidator;

$response = null;

try {
    $container = require_once __DIR__ . '/../../bootstrap.php';

    $baseUrlProvider = $container['http'][EnvironmentBaseUrlProvider::class];
    $response = new JsonResponse($baseUrlProvider);

    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        $response->methodNotAllowed()->send();
        return;
    }

    $action = new BookingPriceQuoteAction(
        response: $response,
        priceCalculator: new BookingPriceCalculator(),
        programSelectionValidator: new ProgramSelectionValidator(),
        studentCountValidator: new StudentCountValidator(),
        supervisorCountValidator: new SupervisorCountValidator(),
        foodAndDrinkSelectionValidator: new FoodAndDrinkSelectionValidator(),
    );

    $action->send($_POST);
} catch (Throwable $e) {
    error_log('Booking price quote controller error: ' . $e->getMessage());

    if ($response instanceof JsonResponse) {
        $response
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

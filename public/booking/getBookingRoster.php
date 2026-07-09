<?php

declare(strict_types=1);

use GeoFort\Database\Connector;
use GeoFort\Services\Booking\Roster\BookingRosterResolver;
use GeoFort\Services\Booking\Roster\RosterGroupCountResolver;
use GeoFort\Services\Http\Api\Booking\BookingRosterAction;
use GeoFort\Services\Http\Response\JsonResponse;
use GeoFort\Services\Http\Url\EnvironmentBaseUrlProvider;
use GeoFort\Services\Sql\RosterSqlService;
use GeoFort\Validation\ChoiceModuleSelectionValidator;
use GeoFort\Validation\EducationSelectionValidator;
use GeoFort\Validation\ProgramSelectionValidator;
use GeoFort\Validation\StudentCountValidator;
use GeoFort\Validation\SupervisorCountValidator;

$response = null;

try {
    $container = require_once __DIR__ . '/../../bootstrap.php';

    $pdo = $container['db'][Connector::class];
    $baseUrlProvider = $container['http'][EnvironmentBaseUrlProvider::class];

    $response = new JsonResponse($baseUrlProvider);

    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        $response->methodNotAllowed()->send();
        return;
    }

    $rosterSqlService = new RosterSqlService($pdo);
    $groupCountResolver = new RosterGroupCountResolver();
    $bookingRosterResolver = new BookingRosterResolver(
        rosterSqlService: $rosterSqlService,
        groupCountResolver: $groupCountResolver,
    );

    $action = new BookingRosterAction(
        response: $response,
        bookingRosterResolver: $bookingRosterResolver,
        programSelectionValidator: new ProgramSelectionValidator(),
        educationSelectionValidator: new EducationSelectionValidator(),
        choiceModuleSelectionValidator: new ChoiceModuleSelectionValidator(),
        studentCountValidator: new StudentCountValidator(),
        supervisorCountValidator: new SupervisorCountValidator(),
    );

    $action->send($_POST);
} catch (Throwable $e) {
    error_log('Booking roster controller error: ' . $e->getMessage());

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

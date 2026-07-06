<?php

declare(strict_types=1);

use GeoFort\Database\Connector;
use GeoFort\Services\Booking\Availability\BookingAvailabilityService;
use GeoFort\Services\Http\Api\Booking\DisabledDatesAction;
use GeoFort\Services\Http\Response\JsonResponse;
use GeoFort\Services\Http\Url\EnvironmentBaseUrlProvider;
use GeoFort\Services\Sql\BookingCalendarSqlService;
use GeoFort\Services\Sql\DisabledDatesSqlService;

$jsonResponse = null;

try {
    $container = require_once __DIR__ . '/../../bootstrap.php';

    $baseUrlProvider = $container['http'][EnvironmentBaseUrlProvider::class];
    $jsonResponse = new JsonResponse($baseUrlProvider);

    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
        $jsonResponse->methodNotAllowed()->send();
        exit;
    }

    $pdo = $container['db'][Connector::class];

    $disabledDatesSqlService = new DisabledDatesSqlService($pdo);
    $bookingCalendarSqlService = new BookingCalendarSqlService($pdo);

    $bookingAvailabilityService = new BookingAvailabilityService(
        calendarSql: $bookingCalendarSqlService,
        disabledDatesSql: $disabledDatesSqlService,
    );

    $disabledDatesAction = new DisabledDatesAction(
        response: $jsonResponse,
        disabledDatesSql: $disabledDatesSqlService,
        bookingAvailabilityService: $bookingAvailabilityService,
    );

    $disabledDatesAction->send();
} catch (Throwable $e) {
    error_log('DisabledDates fetch error: ' . $e->getMessage());

    if ($jsonResponse instanceof JsonResponse) {
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
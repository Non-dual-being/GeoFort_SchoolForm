<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);

$action = (string) file_get_contents(
    $root . '/src/Services/Http/Api/Admin/DashboardRosterConvertToCustomAction.php',
);
$endpoint = (string) file_get_contents(
    $root . '/public/api/admin/rosters/convert-to-custom.php',
);
$bootstrap = (string) file_get_contents($root . '/bootstrap.php');

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

foreach ([
    'final readonly class DashboardRosterConvertToCustomAction',
    'DashboardRosterCreateAction::CSRF_SCOPE',
    "'METHOD_NOT_ALLOWED'",
    "'INVALID_REQUEST'",
    "'INVALID_CSRF'",
    '$this->service->convertBookingPlanToCustom(',
    'planId: $planId',
    'expectedRevision: $expectedRevision',
    "actingAdminId: (int) \$_SESSION['user_id']",
    "catch (RosterPlanException \$exception)",
    "'data' => ['plan' => \$plan]",
] as $needle) {
    $assert(
        str_contains($action, $needle),
        "D3C2 HTTP action missing: {$needle}",
    );
}

$assert(
    str_contains($endpoint, 'DashboardRosterConvertToCustomAction::class'),
    'D3C2 endpoint does not dispatch the conversion action.',
);

foreach ([
    'use GeoFort\Services\Http\Api\Admin\DashboardRosterConvertToCustomAction;',
    '$dashboardRosterConvertToCustomAction = new DashboardRosterConvertToCustomAction(',
    'DashboardRosterConvertToCustomAction::class => $dashboardRosterConvertToCustomAction,',
] as $needle) {
    $assert(
        str_contains($bootstrap, $needle),
        "D3C2 bootstrap wiring missing: {$needle}",
    );
}

fwrite(STDOUT, "OK: D3C2 conversion HTTP contract passed.\n");
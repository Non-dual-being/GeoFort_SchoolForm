<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);

$api = (string) file_get_contents(
    $root . '/resources/js/admin/services/dashboardRosterApi.ts',
);
$types = (string) file_get_contents(
    $root . '/resources/js/admin/types/roster.ts',
);

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

foreach ([
    'export interface DashboardRosterConvertToCustomRequest',
    'planId: number;',
    'expectedRevision: number;',
    'export interface DashboardRosterConvertToCustomResponse',
    'plan: DashboardRosterPlan;',
] as $needle) {
    $assert(
        str_contains($types, $needle),
        "D3C2 roster type missing: {$needle}",
    );
}

foreach ([
    'export function convertDashboardRosterToCustom(',
    'request: DashboardRosterConvertToCustomRequest',
    'Promise<DashboardRosterConvertToCustomResponse>',
    '"/api/admin/rosters/convert-to-custom.php"',
    '"X-CSRF-Token": token',
    'body: JSON.stringify(request)',
] as $needle) {
    $assert(
        str_contains($api, $needle),
        "D3C2 frontend API missing: {$needle}",
    );
}

fwrite(STDOUT, "OK: D3C2 conversion frontend API contract passed.\n");
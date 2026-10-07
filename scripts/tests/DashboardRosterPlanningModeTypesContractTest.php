<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);

$types = (string) file_get_contents(
    $root . '/resources/js/admin/types/roster.ts',
);
$api = (string) file_get_contents(
    $root . '/resources/js/admin/services/dashboardRosterApi.ts',
);

foreach ([
    'bookingId?: number | null;',
    'planningMode: "booking_locked" | "custom";',
] as $needle) {
    if (!str_contains($types, $needle)) {
        throw new RuntimeException(
            "D3A TypeScript contract missing: {$needle}",
        );
    }
}

foreach ([
    'request: DashboardRosterManualCreateRequest',
    'source: "manual"',
    '...request',
] as $needle) {
    if (!str_contains($api, $needle)) {
        throw new RuntimeException(
            "D3A API transport contract missing: {$needle}",
        );
    }
}

fwrite(
    STDOUT,
    "OK: D3A optional booking link TypeScript contract passed.\n",
);
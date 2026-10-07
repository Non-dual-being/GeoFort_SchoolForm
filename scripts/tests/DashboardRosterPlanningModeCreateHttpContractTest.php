<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);

$action = (string) file_get_contents(
    $root . '/src/Services/Http/Api/Admin/DashboardRosterCreateAction.php',
);

foreach ([
    "\$bookingId = \$payload['bookingId'] ?? null;",
    "(\$bookingId !== null && (!is_int(\$bookingId) || \$bookingId <= 0))",
    'bookingId: $bookingId,',
] as $needle) {
    if (!str_contains($action, $needle)) {
        throw new RuntimeException(
            "D3A HTTP contract missing: {$needle}",
        );
    }
}

fwrite(
    STDOUT,
    "OK: D3A optional booking link HTTP contract passed.\n",
);
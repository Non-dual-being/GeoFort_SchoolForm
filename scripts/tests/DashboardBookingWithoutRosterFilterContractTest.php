<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);

$filters = (string) file_get_contents(
    $root . '/src/Services/Dashboard/Booking/DashboardBookingFilters.php',
);
$parser = (string) file_get_contents(
    $root . '/src/Services/Dashboard/Booking/DashboardBookingFilterParser.php',
);
$sql = (string) file_get_contents(
    $root . '/src/Services/Sql/DashboardBookingSqlService.php',
);

foreach ([
    'public bool $withoutRoster = false',
    "\$this->booleanFlag(\$query, 'withoutRoster')",
    'withoutRoster: $withoutRoster',
    'if ($filters->withoutRoster)',
    'FROM roster_plans rp_filter',
    'rp_filter.booking_id = aanvragen.id',
] as $needle) {
    if (
        !str_contains($filters, $needle)
        && !str_contains($parser, $needle)
        && !str_contains($sql, $needle)
    ) {
        throw new RuntimeException("withoutRoster backend contract missing: {$needle}");
    }
}

fwrite(STDOUT, "OK: booking without-roster backend contract passed.\n");
<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);

$foundation = (string) file_get_contents(
    $root . '/database/sql/2026-09-21_create_roster_planning_foundation.sql',
);
$migration = (string) file_get_contents(
    $root . '/database/sql/2026-09-28_add_roster_planning_mode.sql',
);
$repository = (string) file_get_contents(
    $root . '/src/Services/Sql/RosterPlanSqlRepository.php',
);

$assertContains = static function (
    string $haystack,
    string $needle,
    string $message,
): void {
    if (!str_contains($haystack, $needle)) {
        throw new RuntimeException($message . " Missing: {$needle}");
    }
};

foreach ([
    "planning_mode ENUM('booking_locked', 'custom') NOT NULL",
    'UNIQUE KEY uq_roster_plans_booking (booking_id)',
] as $needle) {
    $assertContains(
        $foundation,
        $needle,
        'Planning-mode foundation contract failed.',
    );
}

foreach ([
    "WHEN booking_id IS NULL THEN 'custom'",
    "ELSE 'booking_locked'",
] as $needle) {
    $assertContains(
        $migration,
        $needle,
        'Planning-mode migration backfill contract failed.',
    );
}

foreach ([
    "'booking_locked'",
    "'custom'",
    '?int $bookingId = null',
    'rp.planning_mode',
    'SELECT id, booking_id, planning_mode, status, revision',
] as $needle) {
    $assertContains(
        $repository,
        $needle,
        'Planning-mode repository contract failed.',
    );
}

fwrite(
    STDOUT,
    "OK: V8B2D1 planning-mode repository contract passed.\n",
);
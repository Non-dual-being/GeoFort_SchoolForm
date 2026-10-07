<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$service = (string) file_get_contents(
    $root . '/src/Services/Dashboard/Roster/RosterPlanService.php',
);
$repository = (string) file_get_contents(
    $root . '/src/Services/Sql/RosterPlanSqlRepository.php',
);

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

foreach ([
    'public function convertBookingPlanToCustom(',
    'ROSTER_CONVERSION_NOT_BOOKING_LOCKED',
    'ROSTER_CONVERSION_LOCKED',
    'ROSTER_CONVERSION_HAS_SESSIONS',
    'ROSTER_CONVERSION_CONTEXT_EXISTS',
    'requiredModuleKeys(',
    'saveManualContext(',
    'saveManualModuleKeys(',
    'convertBookingPlanToCustom($planId, $booking->visitDate)',
    'advanceRevision($planId, $actingAdminId)',
] as $needle) {
    $assert(str_contains($service, $needle), "Conversion service missing: {$needle}");
}

$assert(
    str_contains($service, "(\$plan['planning_mode'] ?? null) !== 'custom'"),
    'Manual Programma guard is not based on planning authority.',
);

foreach ([
    'public function convertBookingPlanToCustom(int $planId, string $visitDate): void',
    "SET planning_mode = 'custom'",
    'visit_date = :visit_date',
    'source_booking_fingerprint = NULL',
    "AND planning_mode = 'booking_locked'",
] as $needle) {
    $assert(str_contains($repository, $needle), "Conversion repository missing: {$needle}");
}

fwrite(STDOUT, "OK: D3C1 conversion backend contract passed.\n");
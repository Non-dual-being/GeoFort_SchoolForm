<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$service = (string) file_get_contents(
    $root . '/src/Services/Dashboard/Roster/RosterPlanService.php',
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
    "(\$plan['planning_mode'] ?? null) !== 'custom'",
    "\$planningMode = \$this->nullableString(\$row['planning_mode'] ?? null)",
    "\$manualContext = \$planningMode === 'custom'",
    "\$row['schoolnaam'] = \$manualContext['school_name'] ?? null;",
    "\$row['programma'] = \$manualContext['program_key'] ?? null;",
    "'planningMode' => \$planningMode",
] as $needle) {
    $assertContains(
        $service,
        $needle,
        'V8B2D2B2A list/guard contract failed.',
    );
}

fwrite(
    STDOUT,
    "OK: V8B2D2B2A listPlans + Programma guard contract passed.\n",
);
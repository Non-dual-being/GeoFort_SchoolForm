<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$service = (string) file_get_contents(
    $root . '/src/Services/Dashboard/Roster/RosterSessionService.php',
);

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

foreach ([
    'private function planningContext(array $plan): array',
    '$manualContext = $this->plans->manualContextForPlan((int) $plan[\'id\']);',
    '\'moduleCount\' => count($moduleKeys)',
    '$this->assertModuleAllowed($context[\'moduleKeys\'], $moduleKey);',
    '$moduleCount = $context[\'moduleCount\'];',
    '\'MANUAL_CONTEXT_NOT_FOUND\'',
    '\'MANUAL_PROGRAM_REQUIRED\'',
] as $needle) {
    $assert(
        str_contains($service, $needle),
        'Session authority contract mist: ' . $needle,
    );
}

$saveActivityStart = strpos($service, 'public function saveActivity(');
$groupStart = strpos($service, 'public function saveGroupStaffAssignment(');
$deleteStart = strpos($service, 'public function deleteActivity(');

$assert($saveActivityStart !== false, 'saveActivity ontbreekt.');
$assert($groupStart !== false, 'saveGroupStaffAssignment ontbreekt.');
$assert($deleteStart !== false, 'deleteActivity ontbreekt.');

$saveActivity = substr($service, $saveActivityStart, $groupStart - $saveActivityStart);
$groupStaff = substr($service, $groupStart, $deleteStart - $groupStart);

$assert(
    !str_contains($saveActivity, '$booking = $this->requireBooking($plan);'),
    'saveActivity gebruikt nog direct booking-authority.',
);
$assert(
    !str_contains($groupStaff, '$booking = $this->requireBooking($plan);'),
    'saveGroupStaffAssignment gebruikt nog direct booking-authority.',
);
$assert(
    str_contains($saveActivity, '$context = $this->planningContext($plan);'),
    'saveActivity mist planningContext.',
);
$assert(
    str_contains($groupStaff, '$context = $this->planningContext($plan);'),
    'saveGroupStaffAssignment mist planningContext.',
);

fwrite(STDOUT, "OK: D3C5A2 session authority contract passed.\n");
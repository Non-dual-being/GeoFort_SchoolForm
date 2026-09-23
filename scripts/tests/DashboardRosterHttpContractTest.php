<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$files = [
    '/public/api/admin/rosters/create.php',
    '/public/api/admin/rosters/index.php',
    '/public/api/admin/rosters/show.php',
    '/public/api/admin/rosters/session-save.php',
    '/public/api/admin/rosters/session-delete.php',
    '/public/api/admin/rosters/generation-preview.php',
    '/public/api/admin/rosters/generation-apply.php',
    '/src/Services/Http/Api/Admin/DashboardRosterCreateAction.php',
    '/src/Services/Http/Api/Admin/DashboardRosterListAction.php',
    '/src/Services/Http/Api/Admin/DashboardRosterDetailAction.php',
    '/src/Services/Http/Api/Admin/DashboardRosterSessionSaveAction.php',
    '/src/Services/Http/Api/Admin/DashboardRosterSessionDeleteAction.php',
    '/src/Services/Dashboard/Roster/RosterPlanService.php',
    '/src/Services/Dashboard/Roster/RosterSessionService.php',
    '/src/Services/Dashboard/Roster/RosterAutoGenerator.php',
    '/src/Services/Http/Api/Admin/DashboardRosterGenerationPreviewAction.php',
    '/src/Services/Http/Api/Admin/DashboardRosterGenerationApplyAction.php',
    '/src/Services/Sql/RosterSessionSqlRepository.php',
];
$content = '';
foreach ($files as $file) {
    $content .= file_get_contents($root . $file);
}

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
};

foreach ([
    'DashboardRosterCreateAction',
    'DashboardRosterListAction',
    'DashboardRosterDetailAction',
    'DashboardRosterSessionSaveAction',
    'DashboardRosterSessionDeleteAction',
    "CSRF_SCOPE = 'manage-roster-session'",
    'SessionGuard',
    'PrivatePageBootstrapper',
    'RosterGroupCountResolver',
    'GROUP_TIME_CONFLICT',
    'DUPLICATE_MODULE_FOR_GROUP',
    'PARALLEL_SESSION_LIMIT',
    'ROSTER_REVISION_CONFLICT',
    "CSRF_SCOPE = 'generate-roster-plan'",
    'EXISTING_SESSIONS_REQUIRE_REPLACE',
    'GENERATION_PARALLEL_LIMIT',
] as $needle) {
    $assert(str_contains($content, $needle), "Rooster HTTP contract mist {$needle}.");
}

fwrite(STDOUT, "OK: roster HTTP contract passed.\n");
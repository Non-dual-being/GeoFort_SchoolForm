<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$createEndpoint = file_get_contents($root . '/public/api/admin/rosters/create.php');
$listEndpoint = file_get_contents($root . '/public/api/admin/rosters/index.php');
$showEndpoint = file_get_contents($root . '/public/api/admin/rosters/show.php');
$createAction = file_get_contents($root . '/src/Services/Http/Api/Admin/DashboardRosterCreateAction.php');
$listAction = file_get_contents($root . '/src/Services/Http/Api/Admin/DashboardRosterListAction.php');
$showAction = file_get_contents($root . '/src/Services/Http/Api/Admin/DashboardRosterDetailAction.php');
$service = file_get_contents($root . '/src/Services/Dashboard/Roster/RosterPlanService.php');
$repository = file_get_contents($root . '/src/Services/Sql/RosterPlanSqlRepository.php');

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
};

$assert(str_contains($createEndpoint, 'DashboardRosterCreateAction'), 'Create-endpoint routeert niet naar de rooster-action.');
$assert(str_contains($listEndpoint, 'DashboardRosterListAction'), 'List-endpoint routeert niet naar de rooster-action.');
$assert(str_contains($showEndpoint, 'DashboardRosterDetailAction'), 'Show-endpoint routeert niet naar de rooster-action.');
$assert(str_contains($createAction, "CSRF_SCOPE = 'create-roster-plan'"), 'Roostercreate heeft geen eigen CSRF-scope.');
$assert(str_contains($createAction, "method !== 'POST'"), 'Roostercreate dwingt POST niet af.');
$assert(str_contains($createAction, 'SessionGuard'), 'Roostercreate valideert de privÃ©sessie niet.');
$assert(str_contains($listAction, 'PrivatePageBootstrapper'), 'Roosterlijst vereist geen privÃ©sessie.');
$assert(str_contains($showAction, 'PrivatePageBootstrapper'), 'Roosterdetail vereist geen privÃ©sessie.');
$assert(!str_contains($listAction . $showAction, 'CsrfTokenService'), 'Read-endpoints vragen onnodig CSRF.');
$assert(str_contains($service, 'RosterGroupCountResolver'), 'Roostercreate hergebruikt de centrale groepsresolver niet.');
$assert(str_contains($repository, 'UNIQUE') === false, 'Repository hoort geen schema-DDL te bevatten.');

fwrite(STDOUT, "OK: rooster HTTP-contract geslaagd.\n");
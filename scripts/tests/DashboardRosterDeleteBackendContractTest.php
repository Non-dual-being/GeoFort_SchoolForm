<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);

$endpoint = (string) file_get_contents(
    $root . '/public/api/admin/rosters/delete.php',
);
$action = (string) file_get_contents(
    $root . '/src/Services/Http/Api/Admin/DashboardRosterDeleteAction.php',
);
$service = (string) file_get_contents(
    $root . '/src/Services/Dashboard/Roster/RosterPlanService.php',
);
$repository = (string) file_get_contents(
    $root . '/src/Services/Sql/RosterPlanSqlRepository.php',
);
$bootstrap = (string) file_get_contents($root . '/bootstrap.php');

$assertContains = static function (
    string $haystack,
    string $needle,
    string $message,
): void {
    if (!str_contains($haystack, $needle)) {
        throw new RuntimeException($message . " Ontbreekt: {$needle}");
    }
};

$assertContains(
    $endpoint,
    'DashboardRosterDeleteAction::class',
    'Delete-endpoint moet via de dashboard action lopen.',
);
$assertContains(
    $action,
    'DashboardRosterCreateAction::CSRF_SCOPE',
    'Delete gebruikt bewust het bestaande roosterplan-CSRF contract.',
);
$assertContains(
    $action,
    "\$this->service->deleteConcept(\$planId, (int) \$_SESSION['user_id']);",
    'HTTP-action moet businessflow delegeren aan RosterPlanService.',
);
$assertContains(
    $service,
    "if (\$plan['status'] !== 'concept' && !\$rejected)",
    'Niet-conceptroosters mogen niet hard verwijderd worden.',
);
$assertContains(
    $service,
    '$this->plans->lockPlan($planId)',
    'Verwijderen moet het bestaande plan-lockmechanisme hergebruiken.',
);
$assertContains(
    $repository,
    'DELETE FROM roster_plans WHERE id = :plan_id',
    'Repository moet alleen het plan verwijderen; bestaande FK-cascades ruimen kinderen op.',
);
$assertContains(
    $bootstrap,
    'DashboardRosterDeleteAction::class => $dashboardRosterDeleteAction',
    'Delete-action moet in de bestaande controllercontainer zijn geregistreerd.',
);

fwrite(STDOUT, "OK: roster V8B2A2b delete backend contract passed.\n");

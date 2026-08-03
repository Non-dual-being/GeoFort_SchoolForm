<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$endpoint = file_get_contents($root . '/public/api/admin/calendar/overview.php');
$action = file_get_contents($root . '/src/Services/Http/Api/Admin/DashboardCalendarOverviewAction.php');
$request = file_get_contents($root . '/src/Services/Http/Api/Admin/DashboardCalendarOverviewRequest.php');
$repository = file_get_contents($root . '/src/Services/Sql/DashboardCalendarOverviewSqlRepository.php');
$assert = static function (bool $condition, string $message): void { if (!$condition) { fwrite(STDERR, "FAIL: {$message}\n"); exit(1); } };

$assert(str_contains($endpoint, 'DashboardCalendarOverviewAction'), 'Endpoint routeert verkeerd.');
$assert(str_contains($action, 'PrivatePageBootstrapper'), 'Privésessie ontbreekt.');
$assert(str_contains($action, "\$method !== 'GET'") && str_contains($action, "header('Allow', 'GET')"), 'GET/Allow-contract ontbreekt.');
$assert(!str_contains($action, 'CsrfTokenService'), 'Read-endpoint gebruikt CSRF.');
$assert(str_contains($action, "private, no-store"), 'Private cacheheader ontbreekt.');
$assert(str_contains($request, "\$keys !== ['month', 'year']"), 'Onbekende parameters worden niet geweigerd.');
$assert(str_contains($repository, 'GROUP BY bezoekdatum, status, programma'), 'Cube-aggregatie ontbreekt.');
$assert(!preg_match('/\bJOIN\b/i', $repository), 'Aggregatiequery bevat een join.');
$assert(!str_contains($repository, 'SELECT *'), 'Aggregatiequery gebruikt SELECT *.');
foreach (['schoolnaam','contactpersoon','email','opmerkingen','booking_status_history'] as $privateNeedle) $assert(!str_contains($repository, $privateNeedle), "Privébron {$privateNeedle} gebruikt.");
exit(0);

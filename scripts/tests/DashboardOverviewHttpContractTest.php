<?php
declare(strict_types=1);
$root = dirname(__DIR__, 2);
$endpoint = file_get_contents($root . '/public/api/admin/overview.php');
$action = file_get_contents($root . '/src/Services/Http/Api/Admin/DashboardOverviewAction.php');
$repository = file_get_contents($root . '/src/Services/Sql/DashboardOverviewSqlRepository.php');
$data = file_get_contents($root . '/src/Dashboard/Overview/DashboardOverviewData.php');
$assert = static function (bool $condition, string $message): void { if (!$condition) { fwrite(STDERR, "FAIL: {$message}\n"); exit(1); } };
$assert(str_contains($endpoint, 'DashboardOverviewAction'), 'Endpoint routeert niet naar de overviewactie.');
$assert(str_contains($action, 'PrivatePageBootstrapper'), 'Autorisatie via privÃ©sessie ontbreekt.');
$assert(str_contains($action, "\$method !== 'GET'") && str_contains($action, "header('Allow', 'GET')"), 'Read-only GET-contract ontbreekt.');
$assert(str_contains($action, 'private, no-store') && !str_contains($action, 'CsrfTokenService'), 'Cache- of CSRF-contract klopt niet.');
foreach (['options','nextOption','currentMonth','generatedForDate','timezone'] as $key) $assert(str_contains($data, "'{$key}'"), "Responsecontract mist {$key}.");
$assert(substr_count($repository, 'prepare(') === 4, 'Dashboard gebruikt niet exact vier vaste statements.');
$assert(str_contains($repository, 'bezoekdatum >= :start AND bezoekdatum < :end'), 'Halfopen maandgrenzen ontbreken.');
exit(0);

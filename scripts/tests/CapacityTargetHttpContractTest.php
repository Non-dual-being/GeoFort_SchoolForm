<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$action = (string) file_get_contents($root . '/src/Services/Http/Api/Admin/DashboardCapacityTargetUpdateAction.php');
$request = (string) file_get_contents($root . '/src/Services/Http/Api/Admin/CapacityTargetUpdateRequest.php');
$service = (string) file_get_contents($root . '/src/Services/Dashboard/Booking/Analytics/CapacityTargetManagementService.php');
$endpoint = (string) file_get_contents($root . '/public/api/admin/requests/update-capacity-target.php');
$readAction = (string) file_get_contents($root . '/src/Services/Http/Api/Admin/DashboardBookingAnalyticsAction.php');
$migration = (string) file_get_contents($root . '/database/sql/2026-08-07_create_capacity_targets.sql');
$targetCalculator = (string) file_get_contents($root . '/src/Services/Dashboard/Booking/Analytics/CapacityTargetAnalyticsCalculator.php');
$migrationRunner = (string) file_get_contents($root . '/scripts/apply-capacity-targets-migration.php');
$assert = static fn (bool $condition, string $message) => $condition ?: throw new RuntimeException($message);
$assert(str_contains($action, "update-capacity-target") && str_contains($action, 'CsrfTokenService') && str_contains($endpoint, 'HTTP_X_CSRF_TOKEN'), 'Actiegebonden CSRF-validatie ontbreekt.');
$assert(str_contains($action, 'SessionGuard') && str_contains($service, 'CapacityTargetPolicy::canManage'), 'Authenticatie of targetautorisatie ontbreekt.');
$assert(str_contains($readAction, 'PrivatePageBootstrapper') && str_contains($readAction, "method !== 'GET'"), 'Analytics lezen sluit niet aan op bestaande private leesrechten.');
$assert(str_contains($request, 'actualKeys !== $expectedKeys'), 'Exacte payloadbegrenzing ontbreekt.');
$assert(str_contains($service, 'beginTransaction') && str_contains($service, 'TARGET_CONFLICT') && str_contains($service, 'hash_equals'), 'Transactie of optimistische conflictcontrole ontbreekt.');
$assert(str_contains($migration, 'effective_date') && str_contains($migration, 'created_by_admin_id') && str_contains($migration, 'updated_by_admin_id') && str_contains($migration, 'DECIMAL(2,1)'), 'Historische of geaudite targetopslag ontbreekt.');
$assert(!str_contains($migration, '\\_') && !str_contains($migration, 'USE '), 'Migratie bevat tekstuele underscore-escapes of een hardgecodeerde databaseselectie.');
$assert(str_contains($migrationRunner, 'SELECT DATABASE()') && str_contains($migrationRunner, 'SCHEMA_MISMATCH') && str_contains($migrationRunner, 'CapacityTargetSchemaInspector'), 'Veilige databaseselectie of validatie van een bestaand schema ontbreekt.');
$assert(str_contains($service, 'sqlstate=%s') && str_contains($service, 'action=save_capacity_target') && !str_contains($service, '$exception->getMessage()'), 'PDO-foutlogging mist veilige context of logt een ruwe databasefout.');
$assert(str_contains($targetCalculator, "context('unavailable'") && str_contains($targetCalculator, 'action=load_capacity_targets'), 'Een targetqueryfout wordt niet geïsoleerd of veilig gelogd.');
echo "Capacity target HTTP contract tests passed.\n";

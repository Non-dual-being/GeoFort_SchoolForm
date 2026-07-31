<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$action = (string) file_get_contents($root . '/src/Services/Http/Api/Admin/DashboardBookingAnalyticsAction.php');
$repository = (string) file_get_contents($root . '/src/Services/Sql/BookingAnalyticsRepository.php');
$service = (string) file_get_contents($root . '/src/Services/Dashboard/Booking/Analytics/BookingAnalyticsService.php');
$endpoint = (string) file_get_contents($root . '/public/api/admin/requests/analytics.php');
$bootstrap = (string) file_get_contents($root . '/bootstrap.php');
$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};

$assert(str_contains($action, 'PrivatePageBootstrapper') && str_contains($action, "method !== 'GET'"), 'Private read-only HTTP-contract ontbreekt.');
foreach (['criteria','dateBounds','summary','monthlyTrend','sectorDistribution','programDistribution','choiceModuleDistribution','compositionDistribution','weekdayDistribution','busiestVisitDates','studentCountAnalysis','cateringAnalysis','yearlyAnalysis','seasonalityAnalysis'] as $field) {
    $assert(str_contains($action . $service, "'{$field}'"), "API-contract mist {$field}.");
}
$assert(!str_contains($repository, 'SELECT *'), 'Analyticsrepository gebruikt SELECT *.');
$assert(str_contains($repository, 'SELECT DISTINCT aanvraag_id, level_key, group_key')
    && str_contains($repository, 'GROUP BY a.id, a.onderwijs_sector'), 'Onderwijsselecties worden niet eerst per booking veilig geaggregeerd.');
$assert(substr_count($repository, 'aanvraag_onderwijs_selecties') === 1, 'Hoofdcijfers raken onnodig childrecords.');
$assert(str_contains($service, 'BookingPolicy::isActiveStatus') && str_contains($service, 'BookingProgramConfig::SCHOOL_TYPES_BY_KEY')
    && str_contains($service, 'BookingProgramConfig::MODULE_LABELS'), 'Centrale status- of presentatiesemantiek ontbreekt.');
$assert(str_contains($endpoint, 'DashboardBookingAnalyticsAction') && str_contains($bootstrap, 'DashboardBookingAnalyticsAction::class =>'), 'Endpointregistratie ontbreekt.');
$assert(str_contains($action, "'[DashboardBookingAnalyticsAction] analytics failed: ' . \$exception::class")
    && !str_contains($action, "analytics failed: ' . \$exception->getMessage()"), 'Analytics logt mogelijk private database-informatie.');

echo "Booking analytics HTTP contract tests passed.\n";

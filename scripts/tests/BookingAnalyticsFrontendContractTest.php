<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$router = (string) file_get_contents($root . '/resources/js/admin/router/index.ts');
$navigation = (string) file_get_contents($root . '/resources/js/admin/components/layout/DashboardNavigation.vue');
$view = (string) file_get_contents($root . '/resources/js/admin/views/DashboardBookingAnalyticsView.vue');
$table = (string) file_get_contents($root . '/resources/js/admin/components/analytics/AnalyticsTable.vue');
$api = (string) file_get_contents($root . '/resources/js/admin/services/dashboardBookingAnalyticsApi.ts');
$css = (string) file_get_contents($root . '/resources/css/admin/booking-analytics.css');
$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};

$assert(str_contains($router, '/analytics') && str_contains($router, 'DashboardBookingAnalyticsView'), 'Analyticsroute ontbreekt.');
$assert(strpos($navigation, 'Aanvragen') < strpos($navigation, 'Agenda')
    && strpos($navigation, 'Agenda') < strpos($navigation, 'Export')
    && strpos($navigation, 'Export') < strpos($navigation, 'Analytics'), 'Navigatievolgorde is onjuist.');
foreach (['Managementsummary','Ontwikkeling per maand','Onderwijsverdeling','Programma’s','Keuzemodules','Groepssamenstelling','Planning en spreiding','Scholen per bezoekdag','Top 10 drukste bezoekdatums'] as $label) {
    $assert(str_contains($view, $label), "Vereiste sectie ontbreekt: {$label}.");
}
$assert(str_contains($view, 'Scholen per bezoekdag') && str_contains($view, 'Top 10 drukste bezoekdatums'), 'Nieuwe seizoenanalyses ontbreken.');
$assert(str_contains($view, 'AdminDateField') && str_contains($view, 'startDate') && str_contains($view, 'endDate'), 'Periodefilter ontbreekt.');
$assert(str_contains($view, 'summarySequence') || (str_contains($view, 'sequence') && str_contains($view, 'AbortController')), 'Stale-responsebescherming ontbreekt.');
$assert(str_contains($view, 'role="status"') && str_contains($view, 'role="alert"') && str_contains($view, 'isEmpty'), 'Loading/error/empty-states zijn onvolledig.');
$assert(str_contains($table, '<table') && str_contains($table, '<caption') && str_contains($table, 'scope="col"') && str_contains($table, 'scope="row"'), 'Toegankelijk tabelcontract ontbreekt.');
$assert(str_contains($css, 'overflow-x: auto') && str_contains($css, '@media (max-width: 850px)'), 'Responsive tabelwrapper/grid ontbreekt.');
$assert(str_contains($api, 'BookingAnalyticsResponse') && str_contains($api, 'analytics.php'), 'Typed analytics-API ontbreekt.');
foreach (['primairOnderwijs','voortgezetOnderbouw','voortgezetBovenbouw','Earth-Watch','Klimaat-Mysterie'] as $key) {
    $assert(!str_contains($view, ">{$key}<"), "Interne key zichtbaar als label in view: {$key}.");
}

echo "Booking analytics frontend contract tests passed.\n";

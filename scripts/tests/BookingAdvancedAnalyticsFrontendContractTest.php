<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$view = file_get_contents($root . '/resources/js/admin/views/DashboardBookingAnalyticsView.vue');
$charts = file_get_contents($root . '/resources/js/admin/analytics/chartBuilders.ts');
$css = file_get_contents($root . '/resources/css/admin/booking-analytics.css');
$chartComponent = file_get_contents($root . '/resources/js/admin/components/analytics/AnalyticsChart.vue');
$tableComponent = file_get_contents($root . '/resources/js/admin/components/analytics/AnalyticsTable.vue');
$presentation = file_get_contents($root . '/resources/js/admin/utils/advancedAnalyticsPresentation.ts');
$assert = static fn (bool $condition, string $message) => $condition ?: throw new RuntimeException($message);

$assert(str_contains($view, 'aria-pressed') && str_contains($view, 'advancedView'), 'De hot-switch mist toegankelijke actieve status.');
$assert(str_contains($view, 'newSchoolsByMonth') && str_contains($view, 'capacityByMonth'), 'Beide API-datasets worden niet gepresenteerd.');
$assert(substr_count($view, 'fetchBookingAnalytics(') === 1, 'Wisselen van verdiepende analyse mag geen extra API-pad introduceren.');
$assert(str_contains($view, 'controller?.abort()') && str_contains($view, 'request !== sequence'), 'Stale-requestbescherming ontbreekt.');
$assert(str_contains($charts, 'buildNewSchoolsChart') && str_contains($charts, 'buildCapacityChart'), 'Grafiekbouwers ontbreken.');
$assert(str_contains($css, 'container-type: inline-size') && str_contains($css, '@container (min-width: 2160px)') && str_contains($css, '@container (max-width: 1050px)') && str_contains($css, '@container (max-width: 620px)'), 'Filtergrid gebruikt niet de vereiste containerbreedtes.');
$assert(str_contains($presentation, 'findIndex') && str_contains($presentation, 'rows.slice(first, last + 1)') && str_contains($view, 'visibleNewSchoolsMonths'), 'Begrenzing van lege randmaanden ontbreekt.');
$assert(str_contains($view, 'Binnen deze selectie zijn geen nieuwe scholen gevonden.'), 'Volledig lege nieuwe-scholenreeks mist een empty state.');
$assert(substr_count($view, 'monthly-window') >= 4 && str_contains($chartComponent, 'horizontaal scrollbare grafiek') && str_contains($chartComponent, 'visibleMonths'), 'Herbruikbaar horizontaal maandvenster ontbreekt waar het inhoudelijk wordt gebruikt.');
$assert(str_contains($tableComponent, 'admin-analytics-table-wrap--monthly') && str_contains($tableComponent, 'horizontaal en verticaal scrollbare tabel') && str_contains($css, '--analytics-month-rows: 6') && str_contains($css, '--analytics-month-rows: 4') && str_contains($css, '--analytics-month-rows: 3'), 'Verticaal maandvenster of toegankelijke naam ontbreekt.');
$assert(str_contains($css, '.admin-analytics-table thead th') && str_contains($css, 'position: sticky'), 'Sticky tabelheader ontbreekt.');
$assert(str_contains($view, "initial-sort-key=\"month\"") && str_contains($view, "displayKey:'label'") && !str_contains($view, "label:'Boekings %',format:'percentage',sortable:false"), 'Advanced kolommen gebruiken niet de bestaande sorteerfunctionaliteit.');
echo "Booking advanced analytics frontend contract tests passed.\n";

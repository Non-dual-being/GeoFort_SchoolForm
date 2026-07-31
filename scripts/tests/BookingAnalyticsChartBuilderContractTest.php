<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$builder = (string) file_get_contents($root . '/resources/js/admin/analytics/chartBuilders.ts');
$chart = (string) file_get_contents($root . '/resources/js/admin/components/analytics/AnalyticsChart.vue');
$router = (string) file_get_contents($root . '/resources/js/admin/router/index.ts');
$view = (string) file_get_contents($root . '/resources/js/admin/views/DashboardBookingAnalyticsView.vue');
$css = (string) file_get_contents($root . '/resources/css/admin/booking-analytics.css');
$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
foreach (['buildStudentChart','buildCateringChart','buildYearChart','buildSeasonChart','buildSchoolOccupancyChart','buildTopDaysChart','reducedMotionConfig'] as $function) {
    $assert(str_contains($builder, "function {$function}"), "Pure charthelper ontbreekt: {$function}.");
}
$assert(str_contains($chart, 'chart?.destroy()') && str_contains($chart, 'onBeforeUnmount'), 'Chart lifecycle ruimt instances niet op.');
$assert(str_contains($router, 'component: () => import("../views/DashboardBookingAnalyticsView.vue")'), 'Analyticsroute is niet lazy.');
$assert(str_contains($view, 'IntersectionObserver') && str_contains($view, 'activeSection') && str_contains($view, 'scrollIntoView'), 'Scrollspy/ankernavigatie ontbreekt.');
$assert(str_contains($view, 'presentations') && str_contains($view, 'studentDisplay') && str_contains($view, 'studentMode')
    && str_contains($view, 'cateringMode') && str_contains($view, 'yearMetric') && str_contains($view, 'seasonMetric'), 'Interactieve toggles zijn onvolledig.');
$assert(str_contains($css, 'prefers-reduced-motion: reduce') && str_contains($css, 'overflow-x: auto'), 'Reduced-motion of mobiele subnavigatie ontbreekt.');

echo "Booking analytics chart builder contract tests passed.\n";

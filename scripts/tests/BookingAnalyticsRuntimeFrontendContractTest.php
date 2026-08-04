<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$view = (string) file_get_contents($root . '/resources/js/admin/views/DashboardBookingAnalyticsView.vue');
$chart = (string) file_get_contents($root . '/resources/js/admin/components/analytics/AnalyticsChart.vue');
$table = (string) file_get_contents($root . '/resources/js/admin/components/analytics/AnalyticsTable.vue');
$tableLogic = (string) file_get_contents($root . '/resources/js/admin/composables/useAnalyticsTable.ts')
    . (string) file_get_contents($root . '/resources/js/admin/utils/analyticsTableSorting.ts');
$select = (string) file_get_contents($root . '/resources/js/admin/components/form/AdminSelect.vue');
$css = (string) file_get_contents($root . '/resources/css/admin/booking-analytics.css');
$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};

$matched = preg_match_all('/\.admin-analytics-chapter\s*\{([^}]+)\}/', $css, $chapterRules);
$visibleByDefault = false;
foreach ($chapterRules[1] ?? [] as $rule) {
    if (str_contains($rule, 'opacity: 1')) $visibleByDefault = true;
}
$assert($visibleByDefault, 'Analyticsinhoud is niet standaard zichtbaar.');
$assert(!str_contains($css, '.admin-analytics-chapter {' . PHP_EOL . '    opacity: 0'), 'Basissectie gebruikt nog opacity 0.');
$assert(str_contains($view, 'typeof IntersectionObserver === "undefined"') && str_contains($view, 'revealFallback')
    && str_contains($view, 'is-reveal-enhanced'), 'Observerfallback/progressive enhancement ontbreekt.');
$assert(str_contains($css, 'prefers-reduced-motion: reduce') && str_contains($view, 'reducedMotion.value'), 'Reduced-motioncontract ontbreekt.');

foreach (['await nextTick()', 'getBoundingClientRect()', 'requestAnimationFrame', 'ResizeObserver', 'chart.update("none")', 'chart.resize()', 'chart?.destroy()', 'onBeforeUnmount'] as $needle) {
    $assert(str_contains($chart, $needle), "Chart lifecycle mist {$needle}.");
}
$assert(!str_contains($chart, 'props.active === false'), 'Chartinitialisatie is nog aan revealstate gekoppeld.');
$assert(str_contains($chart, 'state === \'empty\'') && str_contains($chart, 'state === \'error\'')
    && str_contains($chart, 'Grafiek opnieuw laden'), 'Chart empty/error/retry ontbreekt.');
$assert(str_contains($chart, 'canvas ref="canvas"') && !str_contains($chart, '<canvas v-show'), 'Canvas wordt tijdens initialisatie uit layout gehaald.');

foreach (['ArrowDown','ArrowUp','Home','End','Enter','Escape','Tab','pointerdown','aria-expanded','aria-controls','role="listbox"','aria-selected'] as $needle) {
    $assert(str_contains($select, $needle), "Custom select mist {$needle}.");
}
$assert(str_contains($select, 'emit("change"') && str_contains($select, 'emit("open"') && str_contains($select, 'emit("close"'), 'Selectevents zijn onvolledig.');
$assert(str_contains($view, '<AdminSelect') && !str_contains($view, '<select'), 'Analytics gebruikt nog native selects.');

foreach (['Intl.Collator("nl-NL"', '"month"', '"weekday"', '"band"', 'stableSortAnalyticsRows', 'filterAnalyticsRows'] as $needle) {
    $assert(str_contains($tableLogic, $needle), "Tabelinteractie mist {$needle}.");
}
$assert(str_contains($table, 'aria-sort') && str_contains($table, 'table.toggleSort')
    && str_contains($table, 'Tabel herstellen') && str_contains($table, 'Geen rijen voldoen'), 'Tabelsortering/filterfallback is onvolledig.');
$assert(str_contains($view, ':selected-key="selectedStudentDetail?.label ?? null"'), 'Grafiekselectie markeert de tabelrij niet.');

echo "Booking analytics runtime frontend contract tests passed.\n";

<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$view = (string) file_get_contents($root . '/resources/js/admin/views/DashboardBookingAnalyticsView.vue');
$builder = (string) file_get_contents($root . '/resources/js/admin/analytics/chartBuilders.ts');
$css = (string) file_get_contents($root . '/resources/css/admin/booking-analytics.css');
$types = (string) file_get_contents($root . '/resources/js/admin/types/bookingAnalytics.ts');
$assert = static function (bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); };

foreach (['seasonLevel', 'seasonMetric', 'monthlyBuckets', 'weekdayBuckets', 'rankedTopDays', 'seasonContext', 'schoolOccupancy'] as $token) {
    $assert(str_contains($view, $token), "Seizoeninteractie mist {$token}.");
}
$assert(str_contains($builder, 'indexAxis: "x"') && str_contains($view, 'Per weekdag'), 'Weekdagweergave is niet een verticale kolomgrafiek.');
$assert(str_contains($builder, 'buildSchoolOccupancyChart') && str_contains($builder, 'stacked: true') && str_contains($builder, 'max: 100'), '100%-gestapelde schoolbezettingsgrafiek ontbreekt.');
$assert(str_contains($builder, 'buildTopDaysChart') && str_contains($view, 'Top 10 drukste bezoekdatums'), 'Read-only Top 10 ontbreekt.');
$assert(!str_contains($view, 'Maand-weekdaganalyse') && !str_contains($view, 'seasonalityAnalysis.cells'), 'Verouderde seizoenmatrix is nog zichtbaar.');
$assert(str_contains($types, 'interface MonthlyBucket') && str_contains($types, 'interface WeekdayBucket') && str_contains($types, 'interface VisitDateBucket') && str_contains($types, 'interface SchoolOccupancyAnalysis') && str_contains($types, 'interface TopDay'), 'Typed seizoencontract ontbreekt.');
$assert(!str_contains($view, 'Per dag') && !str_contains($types, 'interface DailyBucket'), 'Oude dagsemantiek is nog zichtbaar.');
$assert(str_contains($view, 'value === "daily"') && str_contains($view, '? "weekday" : "monthly"'), 'Oude URL-waarde wordt niet veilig genormaliseerd.');
$assert(str_contains($view, 'scrollOffset()') && str_contains($view, 'sectionObserver') && str_contains($view, 'revealObserver'), 'Aparte scrollspy/reveal-observers ontbreken.');
$assert(str_contains($css, '--analytics-scroll-offset') && str_contains($css, 'scroll-margin-top: var(--analytics-scroll-offset)'), 'Centrale scrolloffset ontbreekt.');
$assert(!str_contains($view, 'Sectorverdeling" :rows="data.sectorDistribution" :columns=') || !str_contains($view, "label:'Sector',type:'search'"), 'Onderwijsverdeling heeft nog een zoekfilter.');
$assert(!str_contains($view, "label:'Programma',type:'search'"), 'Programmaverdeling heeft nog een zoekfilter.');

echo "Booking analytics seasonality/interaction contract tests passed.\n";

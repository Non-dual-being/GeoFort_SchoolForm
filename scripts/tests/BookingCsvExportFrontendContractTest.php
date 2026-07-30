<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$exportView = (string) file_get_contents($root . '/resources/js/admin/views/DashboardBookingExportView.vue');
$bookingsView = (string) file_get_contents($root . '/resources/js/admin/views/DashboardBookingsView.vue');
$service = (string) file_get_contents($root . '/resources/js/admin/services/dashboardBookingExportApi.ts');
$router = (string) file_get_contents($root . '/resources/js/admin/router/index.ts');
$navigation = (string) file_get_contents($root . '/resources/js/admin/components/layout/DashboardNavigation.vue');
$css = (string) file_get_contents($root . '/resources/css/admin/booking-export.css');

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};

$assert(str_contains($router, 'path: "/export"') && str_contains($router, 'DashboardBookingExportView'), 'Direct laadbare exportroute ontbreekt.');
$assert(strpos($navigation, 'Aanvragen') < strpos($navigation, 'Agenda') && strpos($navigation, 'Agenda') < strpos($navigation, 'Export'), 'Navigatievolgorde is onjuist.');
$assert(str_contains($navigation, '<Download') && str_contains($navigation, 'to="/export"'), 'Exportnavigatie met icoon ontbreekt.');
$assert(!str_contains($bookingsView, 'BookingExport'), 'Exportkaart staat nog in aanvragenview.');
$assert(str_contains($exportView, 'fetchBookingExportBounds'), 'Metadata wordt niet zelfstandig geladen.');
$assert(str_contains($exportView, 'startDate.value = result.bounds.minDate'), 'Standaard begindatum is niet de databasegrens.');
$assert(str_contains($exportView, 'endDate.value = result.bounds.maxDate'), 'Standaard einddatum is niet de databasegrens.');
$assert(str_contains($exportView, 'setTimeout(() => void loadSummary(key), 300)'), 'Summarydebounce ontbreekt.');
$assert(str_contains($exportView, 'summaryController?.abort()') && str_contains($exportView, 'summarySequence'), 'Stale summarybescherming ontbreekt.');
$assert(str_contains($exportView, 'Volledige periode'), 'Volledige-periodeknop ontbreekt.');
$assert(str_contains($exportView, 'Intl.NumberFormat("nl-NL"'), 'Nederlandse getalnotatie ontbreekt.');
$assert(str_contains($exportView, ':disabled="!canDownload"'), 'CSV disabledlogica ontbreekt.');
$assert(str_contains($exportView, 'period.effectiveStartDate') && str_contains($exportView, 'period.effectiveEndDate'), 'CSV krijgt effectieve periode niet.');
$assert(str_contains($exportView, 'role="alert"') && str_contains($exportView, 'aria-live="polite"'), 'Toegankelijke fout/loadingstates ontbreken.');
$assert(substr_count($exportView, '<article class="admin-export-category"') === 4, 'Er zijn niet exact vier categoriekaarten.');
foreach (['Aanvragen</h3>', 'Leerlingen</h3>', 'Programma</h3>', 'Onderwijsprofiel</h3>'] as $heading) {
    $assert(str_contains($exportView, $heading), "Categorieheading ontbreekt: {$heading}.");
}
$assert(str_contains($exportView, 'VO: één niveau') && str_contains($exportView, 'Drie of meer geselecteerde groepen'), 'Betekenisvolle niveau- of groepsverdeling ontbreekt.');
$assert(str_contains($exportView, 'Keuzemodules dagprogramma') && str_contains($exportView, 'eligibleBookings'), 'Keuzemoduleverdeling gebruikt de relevante populatie niet.');
foreach (['Geselecteerde niveaus</dt>', 'Geselecteerde groepen</dt>', 'Met keuzemodule', 'Zonder keuzemodule'] as $removedMetric) {
    $assert(!str_contains($exportView, $removedMetric), "Verwijderde technische metric staat nog in de view: {$removedMetric}.");
}
$assert(str_contains($exportView, '<dl class="admin-export-metrics">'), 'Compacte semantische metriclijsten ontbreken.');
$assert(!str_contains($exportView, 'admin-export-stats'), 'Oude losse statistiekkaarten staan nog in de view.');
$assert(str_contains($css, 'repeat(2, minmax(0, 1fr))') && str_contains($css, '@media (max-width: 760px)'), 'Responsive 2x2-categoriegridcontract ontbreekt.');
$assert(str_contains($css, '@container (min-width: 27rem)'), 'Interne responsive metricgrid ontbreekt.');
$assert(!str_contains($css, '.admin-export-stats'), 'Oude massieve statistiek-CSS bestaat nog.');
$assert(str_contains($css, 'prefers-reduced-motion'), 'Loading-skeleton respecteert reduced motion niet.');
$assert(str_contains($service, 'export-metadata.php') && str_contains($service, 'export-summary.php'), 'Metadata- of summaryendpoint ontbreekt.');
$assert(str_contains($service, 'response.ok') && str_contains($service, 'response.blob()'), 'Blob-downloadcontrole ontbreekt.');
$assert(str_contains($service, 'filenameFromDisposition'), 'Content-Disposition-bestandsnaam ontbreekt.');
$assert(str_contains($service, 'URL.createObjectURL') && str_contains($service, 'URL.revokeObjectURL'), 'Object-URL lifecycle is onvolledig.');

echo "Booking export frontend and navigation contract tests passed.\n";

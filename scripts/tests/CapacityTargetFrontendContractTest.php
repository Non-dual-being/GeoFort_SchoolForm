<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$view = (string) file_get_contents($root . '/resources/js/admin/views/DashboardBookingAnalyticsView.vue');
$component = (string) file_get_contents($root . '/resources/js/admin/components/analytics/CapacityTargetComparison.vue');
$scenario = (string) file_get_contents($root . '/resources/js/admin/utils/capacityTargetScenario.ts');
$analyticsChart = (string) file_get_contents($root . '/resources/js/admin/components/analytics/AnalyticsChart.vue');
$analyticsTable = (string) file_get_contents($root . '/resources/js/admin/components/analytics/AnalyticsTable.vue');
$numberControl = (string) file_get_contents($root . '/resources/js/admin/components/form/AdminNumberControl.vue');
$chart = (string) file_get_contents($root . '/resources/js/admin/analytics/chartBuilders.ts');
$css = (string) file_get_contents($root . '/resources/css/admin/booking-analytics.css');
$assert = static fn (bool $condition, string $message) => $condition ?: throw new RuntimeException($message);

foreach (['Capaciteit', 'Targetresultaten', 'Doelen instellen', 'Doelen bekijken of aanpassen', 'Niet-opgeslagen scenario', 'Terugzetten naar opgeslagen target', 'Officieel target opslaan', 'Organisatiedoel instellen'] as $needle) {
    $assert(str_contains($view, $needle), "Target-UX mist {$needle}.");
}
$assert(str_contains($view, 'const capacityView = ref<CapacityView>("capacity")'), 'Verdieping 05 opent niet standaard op de oorspronkelijke capaciteitsweergave.');
$assert(str_contains($view, 'buildCapacityChart(data.value.capacityByMonth)') && str_contains($view, '<AnalyticsChart v-if="capacityChart" :config="capacityChart"') && str_contains($view, 'caption="Capaciteitsbenutting per maand"'), 'Oorspronkelijke capaciteitsgrafiek en -tabel zijn niet rechtstreeks hersteld.');
$capacityPanelStart = strpos($view, 'id="capacity-panel-capacity"');
$capacityPanelEnd = strpos($view, 'id="capacity-panel-targets"');
$capacityPanel = $capacityPanelStart !== false && $capacityPanelEnd !== false ? substr($view, $capacityPanelStart, $capacityPanelEnd - $capacityPanelStart) : '';
$targetPanelStart = strpos($view, 'id="capacity-panel-targets"');
$settingsPanelStart = strpos($view, 'id="capacity-panel-settings"');
$targetPanel = $targetPanelStart !== false && $settingsPanelStart !== false ? substr($view, $targetPanelStart, $settingsPanelStart - $targetPanelStart) : '';
$settingsPanel = $settingsPanelStart !== false ? substr($view, $settingsPanelStart, strpos($view, '</template>', $settingsPanelStart) - $settingsPanelStart) : '';
$assert($capacityPanel !== '' && !str_contains($capacityPanel, 'capacityTarget') && !str_contains($capacityPanel, 'TargetComparison') && !str_contains($capacityPanel, 'organisatietarget') && str_contains($capacityPanel, 'allow-zero-data monthly-window'), 'Capaciteit is niet targetonafhankelijk of mist het interne chartmaandvenster.');
$assert(str_contains($view, 'capacityTargetByMonth') && !str_contains($view, '<CapacityTargetComparison :rows="data.capacityByMonth"'), 'Officiële targetresultaten zijn niet gescheiden van technische capaciteit.');
$assert(str_contains($view, "capacityTargetContext.history.length === 0") && str_contains($view, 'Er wordt daarom geen targetlijn getekend'), 'Lege targettoestand ontbreekt of tekent een verzonnen targetlijn.');
$assert(str_contains($scenario, 'filterCapacityTargetResultRows') && str_contains($scenario, 'row.targetAvailableDays > 0') && str_contains($scenario, '(row.bookingsTargetComparison.actual ?? 0) > 0'), 'Targetmaanden worden niet op effectieve targetdagen en meegetelde boekingsrecords gefilterd.');
$assert(str_contains($view, 'officialTargetRows') && str_contains($view, 'officialTargetRows.length === 0') && str_contains($view, 'Nog geen targetresultaten beschikbaar.'), 'Targetresultaten gebruiken niet één gefilterde dataset met empty state.');
$assert(str_contains($component, 'buildCapacityTargetChart(displayRows.value') && str_contains($component, 'v-for="row in displayRows"'), 'Targetgrafieken en maandkaarten gebruiken niet exact dezelfde gefilterde dataset.');
$assert($targetPanel !== '' && substr_count($targetPanel, '<CapacityTargetComparison') === 1, 'Targetresultaten rendert de officiële vergelijkingsgrafieken niet meer.');
$assert($settingsPanel !== '' && !str_contains($settingsPanel, '<CapacityTargetComparison') && !str_contains($settingsPanel, 'officialTargetRows') && !str_contains($settingsPanel, 'Targetresultaten per maand'), 'Doelen instellen bevat nog dubbele officiële analytics.');
$assert(substr_count($view, 'role="tab"') === 3 && substr_count($view, 'role="tabpanel"') === 3 && substr_count($view, ':aria-selected=') === 3 && str_contains($view, 'handleCapacityTabKeydown'), 'De drie capaciteitsweergaven zijn geen volledig toegankelijke tabs.');
$assert(str_contains($css, '.admin-capacity-view-switch { grid-template-columns: repeat(3, max-content); width: max-content;') && str_contains($css, 'white-space: nowrap') && str_contains($css, '.admin-capacity-view-switch button:focus-visible'), 'Tabs blijven niet in één compacte desktoprij of missen zichtbare toetsenbordfocus.');
$assert(str_contains($view, 'input-mode="decimal"') && str_contains($view, ':step="0.1"'), 'Boekingtarget biedt geen logische decimale invoer op mobiel en desktop.');
$assert(substr_count($view, '<CapacityTargetComparison') === 1, 'Doelen instellen rendert nog een tweede targetresultaatcomponent.');
$assert(str_contains($css, '.admin-capacity-target-charts { display: grid; width: 100%; min-width: 0; grid-template-columns: minmax(0, 1fr);') && !str_contains($css, '.admin-capacity-target-charts { display: grid; grid-template-columns: repeat(3'), 'Targetgrafieken gebruiken niet elk de volledige beschikbare breedte.');
$assert(str_contains($analyticsChart, 'viewportWidth.value <= 0') && str_contains($analyticsChart, 'if (!chart && measurable()) void initialize()'), 'Chartinitialisatie beschermt niet tegen een nulbrede of later zichtbare container.');
$assert(str_contains($analyticsChart, 'admin-analytics-chart__viewport') && str_contains($analyticsChart, 'chartWidth') && str_contains($analyticsChart, 'Scroll horizontaal om alle maanden te bekijken.') && str_contains($css, '.admin-analytics-chart__viewport') && str_contains($css, 'overflow-x: auto'), 'Technische grafiek mist een intern horizontaal scrollbare chartviewport.');
$assert(str_contains($analyticsTable, 'ref="scrollRegion"') && str_contains($analyticsTable, 'horizontallyScrollable') && str_contains($analyticsTable, 'Scroll horizontaal om alle kolommen te bekijken.') && str_contains($css, '.admin-analytics-table-wrap--monthly') && str_contains($css, 'overflow: auto'), 'Capaciteitstabel mist lokale horizontale of verticale scrolling.');
$assert(str_contains($css, '.admin-analytics-page') && str_contains($css, 'max-width: 100%') && str_contains($css, '.admin-capacity-view { display: grid; box-sizing: border-box; width: 100%; max-width: 100%; min-width: 0; grid-template-columns: minmax(0, 1fr);') && str_contains($css, '.admin-analytics-table-block') && str_contains($css, 'box-sizing: border-box'), 'De breedteketen voorkomt horizontale pagina-overflow niet aantoonbaar.');
$assert(str_contains($settingsPanel, 'admin-capacity-target-info') && str_contains($settingsPanel, 'daginstellingen en programmagrens') && str_contains($settingsPanel, 'gesloten dagen met meetellende planning') && str_contains($settingsPanel, 'Oudere resultaten blijven gekoppeld') && str_contains($settingsPanel, 'Bij 0 gewenste boekingen'), 'Het begeleidende informatieveld is onvolledig.');
$assert(str_contains($scenario, 'capacityTargetScenarioStatus') && str_contains($scenario, '"Nog geen officieel target"') && str_contains($scenario, '"Nieuw scenario"') && str_contains($scenario, '"Officieel opgeslagen target"') && str_contains($scenario, '"Niet-opgeslagen scenario"'), 'De drie opslagtoestanden en de eerste nieuwe-scenariotoestand zijn niet expliciet bepaald.');
$assert(str_contains($settingsPanel, 'v-if="hasOfficialTarget"') && str_contains($settingsPanel, 'Terugzetten naar opgeslagen target'), 'Terugzetten wordt ook zonder opgeslagen target aangeboden.');
$assert(str_contains($settingsPanel, '120') === false && str_contains($settingsPanel, 'targetDerivedAverage') && str_contains($settingsPanel, 'Niet beschikbaar: vul meer dan 0 boekingen per dag in.'), 'Liveberekening is hardcoded of deelt bij nul door.');
$assert(str_contains($scenario, 'deriveCapacityTargetAverage') && str_contains($scenario, 'bookings > 0') && str_contains($scenario, 'students / bookings'), 'Afgeleid target heeft geen expliciet geteste nuldelingsbescherming.');
$assert(str_contains($numberControl, ':aria-label="`${label} verlagen`"') && str_contains($numberControl, ':aria-label="`${label} verhogen`"') && str_contains($analyticsChart, ':tabindex="scrollable ? 0 : -1"') && str_contains($analyticsTable, ':tabindex="scrollLabel ? 0 : -1"'), 'Plus/min-knoppen of scrollregio’s missen toegankelijke toetsenbordnamen.');
$assert(str_contains($css, '@media (max-width: 64rem)') && str_contains($css, 'repeat(2, minmax(0, 1fr))') && str_contains($css, '@media (max-width: 42rem)') && str_contains($css, '.admin-capacity-target-form { grid-template-columns: 1fr; }'), 'Targetformulier heeft geen tablet- en mobiele layout.');
$assert(str_contains($component, 'admin-capacity-target-metric__values') && str_contains($component, 'admin-capacity-target-status') && !str_contains($component, 'Percentage niet beschikbaar'), 'Maandkaarten hebben niet de compacte visuele hiërarchie.');
$assert(str_contains($chart, 'buildCapacityChart') && str_contains($chart, 'legend: { ...base.plugins.legend, align: "start" }'), 'De legenda van de horizontaal scrollbare technische grafiek blijft niet aan het zichtbare begin uitgelijnd.');
$assert(str_contains($chart, 'Technische capaciteit') && str_contains($chart, 'Officieel leerlingtarget') && str_contains($chart, 'borderDash'), 'Targetlijn en technische capaciteitslijn zijn niet visueel onderscheiden.');
$assert(str_contains($component, 'Nog geen oordeel') && str_contains($component, 'boven technische capaciteit'), 'Toekomst- of capaciteitsafwijkingspresentatie ontbreekt.');
$assert(str_contains($scenario, 'evaluationIncluded') && str_contains($scenario, 'day.countsForCapacity') && str_contains($scenario, 'bookingsTarget > 0'), 'Scenario gebruikt niet dezelfde daggrondslag- en nuldelingsformules.');

echo "Capacity target frontend contract tests passed.\n";

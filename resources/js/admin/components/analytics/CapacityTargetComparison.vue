<script setup lang="ts">
import { computed } from "vue";
import AnalyticsChart from "./AnalyticsChart.vue";
import { buildCapacityTargetChart, reducedMotionConfig } from "../../analytics/chartBuilders";
import type { CapacityMonthRow, CapacityTargetMetric } from "../../types/bookingAnalytics";
import { filterCapacityTargetResultRows } from "../../utils/capacityTargetScenario";

const props = withDefaults(defineProps<{ rows: CapacityMonthRow[]; scenario?: boolean; reducedMotion?: boolean; visible?: boolean }>(), { scenario: false, reducedMotion: false, visible: true });
const displayRows = computed(() => filterCapacityTargetResultRows(props.rows));
const chartTitles = ["Leerlingen tegenover periodetarget", "Boekingen tegenover periodetarget", "Gemiddelde leerlingen per boeking"];
const charts = computed(() => (["students", "bookings", "average"] as const).map(metric => reducedMotionConfig(buildCapacityTargetChart(displayRows.value, metric), props.reducedMotion)));
const nf = new Intl.NumberFormat("nl-NL", { maximumFractionDigits: 1 });
function status(metric: CapacityTargetMetric, future: boolean): string {
  if (future) return "Nog geen oordeel";
  if (metric.assessment === "unavailable") return "Niet beschikbaar";
  if (metric.target === null) return "Geen target";
  const value = metric.difference ?? 0;
  if (value === 0) return "Target gehaald";
  const difference = `${nf.format(Math.abs(value))} ${value < 0 ? "onder" : "boven"} target`;
  return metric.differencePercentage === null
    ? difference
    : `${difference} · ${nf.format(Math.abs(metric.differencePercentage))}%`;
}
function statusClass(metric: CapacityTargetMetric, future: boolean): string {
  if (future || metric.assessment === "unavailable" || metric.target === null) return "is-neutral";
  return metric.assessment === "below" ? "is-below" : "is-reached";
}
function metrics(row: CapacityMonthRow): Array<{ label: string; targetPrefix: string; metric: CapacityTargetMetric }> {
  return [
    { label: "Leerlingen", targetPrefix: "van", metric: row.studentsTargetComparison },
    { label: "Boekingen", targetPrefix: "van", metric: row.bookingsTargetComparison },
    { label: "Gem. leerlingen / boeking", targetPrefix: "target", metric: row.averageBookingSizeComparison },
  ];
}
</script>

<template>
  <div class="admin-capacity-target-comparison" :class="{ 'is-scenario': scenario }">
    <p v-if="scenario" class="admin-capacity-scenario-label"><strong>Tijdelijk scenario</strong> — deze waarden zijn nog niet opgeslagen.</p>
    <p v-if="displayRows.length === 0" class="admin-analytics-empty admin-capacity-target-empty" role="status"><strong>Nog geen targetresultaten beschikbaar.</strong><span>Targetresultaten verschijnen zodra er vanaf de ingestelde startdatum boekingen zijn.</span></p>
    <template v-else>
      <div class="admin-capacity-target-charts">
      <section v-for="(chart, index) in charts" :key="chartTitles[index]" class="admin-capacity-target-chart">
        <h4>{{ chartTitles[index] }}</h4>
        <AnalyticsChart :config="chart" :label="chartTitles[index] ?? ''" :visibility-hint="visible" allow-zero-data />
      </section>
      </div>
      <div class="admin-capacity-target-results" aria-label="Targetresultaten per maand">
        <article v-for="row in displayRows" :key="row.month">
          <header><strong>{{ row.label }}</strong><span>{{ row.evaluatedAvailableDays }} dagen in grondslag{{ row.periodState === 'current' ? ' t/m vandaag' : '' }}</span></header>
          <dl>
            <div v-for="item in metrics(row)" :key="item.label" class="admin-capacity-target-metric">
              <dt>{{ item.label }}</dt>
              <dd class="admin-capacity-target-metric__values"><strong>{{ item.metric.actual === null ? '—' : nf.format(item.metric.actual) }}</strong><span>{{ item.metric.target === null ? 'Geen target' : `${item.targetPrefix} ${nf.format(item.metric.target)}` }}</span></dd>
              <dd><span class="admin-capacity-target-status" :class="statusClass(item.metric, row.periodState === 'future')">{{ status(item.metric, row.periodState === 'future') }}</span></dd>
            </div>
          </dl>
          <footer><span>Technische capaciteit</span><strong>{{ nf.format(row.technicalCapacity.students) }} leerlingen · {{ nf.format(row.technicalCapacity.bookings) }} boekingsplekken</strong></footer>
          <p v-if="row.studentsTargetComparison.aboveTechnicalCapacity || row.bookingsTargetComparison.aboveTechnicalCapacity" class="admin-analytics-warning">Werkelijk resultaat boven technische capaciteit; dit is een afzonderlijke capaciteitsafwijking.</p>
          <p v-if="row.targetAboveTechnicalCapacity.students || row.targetAboveTechnicalCapacity.bookings" class="admin-analytics-warning">Het target ligt door de gekozen programmafilter of dagoverrides boven de technische capaciteit van deze periode.</p>
        </article>
      </div>
    </template>
  </div>
</template>

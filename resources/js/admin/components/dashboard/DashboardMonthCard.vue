<script setup lang="ts">
import { computed } from "vue";
import { RouterLink } from "vue-router";
import type { DashboardOverviewData } from "../../types/dashboardOverview";
const props = defineProps<{ statistics: DashboardOverviewData["currentMonth"] }>();
const monthKey = computed(() => `${props.statistics.year}-${String(props.statistics.month).padStart(2, "0")}`);
const monthLabel = computed(() => new Intl.DateTimeFormat("nl-NL", { month: "long", year: "numeric", timeZone: "UTC" }).format(new Date(`${monthKey.value}-01T00:00:00Z`)));
</script>

<template>
  <article class="admin-card admin-overview-card">
    <h2>Deze maand</h2><p class="admin-overview-month">{{ monthLabel }}</p>
    <dl class="admin-overview-statistics">
      <div><dt>Totaal aanvragen</dt><dd>{{ statistics.total }}</dd></div>
      <div><dt>Definitief</dt><dd>{{ statistics.confirmed }}</dd></div>
      <div><dt>In optie</dt><dd>{{ statistics.option }}</dd></div>
      <div><dt>Afgewezen</dt><dd>{{ statistics.rejected }}</dd></div>
      <div class="is-wide"><dt>Totaal leerlingen</dt><dd>{{ statistics.students }}</dd></div>
      <div><dt>Leerlingen PO</dt><dd>{{ statistics.studentsPrimary }}</dd></div>
      <div><dt>Leerlingen VO</dt><dd>{{ statistics.studentsSecondary }}</dd></div>
    </dl>
    <RouterLink class="admin-button admin-button--secondary admin-overview-card__cta" :to="{ name: 'calendar', query: { month: monthKey } }">Bekijk in agenda <span aria-hidden="true">→</span></RouterLink>
  </article>
</template>

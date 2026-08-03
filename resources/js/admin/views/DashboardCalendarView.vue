<script setup lang="ts">
import { computed, defineAsyncComponent, KeepAlive, watch } from "vue";
import { useRoute, useRouter } from "vue-router";
import DashboardCalendarModeSwitch from "../components/calendar/DashboardCalendarModeSwitch.vue";
import DashboardCalendarOverview from "../components/calendar/DashboardCalendarOverview.vue";

const DashboardCalendarManagement = defineAsyncComponent(
  () => import("../components/calendar/DashboardCalendarManagement.vue"),
);

type CalendarPageMode = "overview" | "management";
const route = useRoute();
const router = useRouter();
const mode = computed<CalendarPageMode>(() => route.query.mode === "management" ? "management" : "overview");

watch(() => route.query.mode, (value) => {
  if (value === undefined || value === "overview" || value === "management") return;
  const query = { ...route.query };
  delete query.mode;
  void router.replace({ name: "calendar", query });
}, { immediate: true });

function setMode(nextMode: CalendarPageMode): void {
  const query = { ...route.query };
  if (nextMode === "overview") delete query.mode;
  else query.mode = nextMode;
  void router.push({ name: "calendar", query });
}
</script>

<template>
  <section class="admin-calendar-page" aria-labelledby="calendar-title">
    <header class="admin-calendar-page__intro">
      <p class="admin-eyebrow">Planning</p>
      <h1 id="calendar-title">Agenda</h1>
      <p>Bekijk aanvragen per datum of beheer handmatige blokkades.</p>
    </header>

    <DashboardCalendarModeSwitch :model-value="mode" @update:model-value="setMode" />

    <KeepAlive>
      <DashboardCalendarOverview v-if="mode === 'overview'" key="overview" />
      <DashboardCalendarManagement v-else key="management" />
    </KeepAlive>
  </section>
</template>

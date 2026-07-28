<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from "vue";
import { ChevronLeft, ChevronRight } from "lucide-vue-next";
import AdminButton from "../components/form/AdminButton.vue";
import DashboardCalendarDayCell from "../components/calendar/DashboardCalendarDayCell.vue";
import DashboardCalendarDayDetail from "../components/calendar/DashboardCalendarDayDetail.vue";
import { fetchDashboardCalendar } from "../services/dashboardCalendarApi";
import type { DashboardCalendarDay } from "../types/dashboardCalendar";

const visibleMonth = ref(monthStart(new Date()));
const days = ref<DashboardCalendarDay[]>([]);
const selectedDate = ref("");
const loading = ref(false);
const error = ref(false);
let controller: AbortController | undefined;

const range = computed(() => {
  const first = parseDate(visibleMonth.value);
  const mondayOffset = (first.getUTCDay() + 6) % 7;
  const start = addDays(first, -mondayOffset);
  return { start: ymd(start), end: ymd(addDays(start, 41)) };
});
const monthLabel = computed(() => new Intl.DateTimeFormat("nl-NL", {
  month: "long",
  year: "numeric",
  timeZone: "UTC",
}).format(parseDate(visibleMonth.value)));
const selectedDay = computed(() => days.value.find((day) => day.date === selectedDate.value) ?? null);
const today = ymd(new Date());

function parseDate(value: string): Date {
  const [year, month, day] = value.split("-").map(Number);
  return new Date(Date.UTC(year!, month! - 1, day!));
}
function ymd(date: Date): string {
  return `${date.getUTCFullYear()}-${String(date.getUTCMonth() + 1).padStart(2, "0")}-${String(date.getUTCDate()).padStart(2, "0")}`;
}
function addDays(date: Date, count: number): Date {
  const next = new Date(date);
  next.setUTCDate(next.getUTCDate() + count);
  return next;
}
function monthStart(value: Date | string): string {
  const date = typeof value === "string" ? parseDate(value) : new Date(Date.UTC(value.getFullYear(), value.getMonth(), 1));
  date.setUTCDate(1);
  return ymd(date);
}
function changeMonth(delta: number): void {
  const date = parseDate(visibleMonth.value);
  date.setUTCMonth(date.getUTCMonth() + delta);
  visibleMonth.value = monthStart(date);
}
async function load(): Promise<void> {
  controller?.abort();
  controller = new AbortController();
  loading.value = true;
  error.value = false;
  try {
    const result = await fetchDashboardCalendar(range.value.start, range.value.end, controller.signal);
    days.value = result.calendar.days;
    selectedDate.value = days.value.some((day) => day.date === selectedDate.value)
      ? selectedDate.value
      : (days.value.find((day) => day.date === today) ?? days.value.find((day) => !day.isPast) ?? days.value[0])?.date ?? "";
  } catch (caught) {
    if (caught instanceof DOMException && caught.name === "AbortError") return;
    error.value = true;
  } finally {
    loading.value = false;
  }
}

watch(visibleMonth, () => void load(), { immediate: true });
onBeforeUnmount(() => controller?.abort());
</script>

<template>
  <section class="admin-calendar-page" aria-labelledby="calendar-title">
    <header class="admin-calendar-page__intro">
      <p class="admin-eyebrow">Planning</p>
      <h1 id="calendar-title">Agenda</h1>
      <p>Bekijk beschikbaarheid, capaciteit en aanvragen per datum. Dit overzicht is alleen-lezen.</p>
    </header>

    <div class="admin-calendar-layout">
      <section class="admin-card admin-calendar" :aria-busy="loading">
        <header class="admin-calendar__header">
          <AdminButton variant="secondary" aria-label="Vorige maand" @click="changeMonth(-1)">
            <ChevronLeft :size="18" aria-hidden="true" />
          </AdminButton>
          <h2>{{ monthLabel }}</h2>
          <AdminButton variant="secondary" aria-label="Volgende maand" @click="changeMonth(1)">
            <ChevronRight :size="18" aria-hidden="true" />
          </AdminButton>
        </header>

        <div class="admin-calendar__weekdays" aria-hidden="true">
          <span v-for="weekday in ['Ma', 'Di', 'Wo', 'Do', 'Vr', 'Za', 'Zo']" :key="weekday">{{ weekday }}</span>
        </div>

        <div v-if="error" class="admin-calendar__message" role="alert">
          <p>De agenda kon niet worden geladen.</p>
          <AdminButton @click="load">Opnieuw proberen</AdminButton>
        </div>
        <p v-else-if="loading && !days.length" class="admin-calendar__message" role="status">Agenda laden…</p>
        <div v-else class="admin-calendar__grid">
          <DashboardCalendarDayCell
            v-for="day in days"
            :key="day.date"
            :day="day"
            :outside-month="monthStart(day.date) !== visibleMonth"
            :selected="day.date === selectedDate"
            :today="day.date === today"
            @select="selectedDate = $event.date"
          />
        </div>

        <div class="admin-calendar__legend" aria-label="Legenda">
          <span class="is-available">Beschikbaar</span>
          <span class="is-limited">Beperkt</span>
          <span class="is-full">Vol</span>
          <span class="is-blocked">Geblokkeerd</span>
          <span class="is-past">Verleden</span>
        </div>
      </section>

      <DashboardCalendarDayDetail v-if="selectedDay" :day="selectedDay" />
    </div>
  </section>
</template>

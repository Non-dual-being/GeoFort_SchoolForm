<script setup lang="ts">
import { computed, onActivated, onBeforeUnmount, onDeactivated, ref, watch } from "vue";
import { useRoute, useRouter } from "vue-router";
import { ChevronLeft, ChevronRight, RotateCcw } from "lucide-vue-next";
import AdminButton from "../form/AdminButton.vue";
import AdminSelect from "../form/AdminSelect.vue";
import DashboardCalendarOverviewDayCell from "./DashboardCalendarOverviewDayCell.vue";
import DashboardCalendarDayDetail from "./DashboardCalendarDayDetail.vue";
import AdminDialog from "../feedback/AdminDialog.vue";
import { fetchDashboardCalendar } from "../../services/dashboardCalendarApi";
import type { DashboardCalendarDay } from "../../types/dashboardCalendar";
import { fetchDashboardCalendarOverview, getCachedDashboardCalendarOverview, invalidateDashboardCalendarOverviewCache } from "../../services/dashboardCalendarOverviewApi";
import type { CalendarOverviewAggregate, CalendarOverviewDay, CalendarOverviewProgramFilter, CalendarOverviewStatus, CalendarOverviewStatusFilter, DashboardCalendarOverview } from "../../types/dashboardCalendarOverview";
import { effectiveCalendarOverviewCapacity } from "../../utils/calendarOverviewPresentation";

const route = useRoute();
const router = useRouter();
const amsterdamToday = new Intl.DateTimeFormat("sv-SE", { timeZone: "Europe/Amsterdam" }).format(new Date());
const requestedMonth = typeof route.query.month === "string" && /^\d{4}-(?:0[1-9]|1[0-2])$/.test(route.query.month) ? route.query.month : null;
const visibleMonth = ref(requestedMonth ?? amsterdamToday.slice(0, 7));
const calendar = ref<DashboardCalendarOverview | null>(null);
const programFilter = ref<CalendarOverviewProgramFilter>("all");
const statusFilter = ref<CalendarOverviewStatusFilter>("all");
const loading = ref(false);
const error = ref(false);
let controller: AbortController | undefined;
let requestSequence = 0;
const detailDate = ref<string | null>(null);
const detail = ref<DashboardCalendarDay | null>(null);
const detailLoading = ref(false);
const detailError = ref(false);
let detailController: AbortController | undefined;
let detailSequence = 0;

const currentYear = computed(() => Number(visibleMonth.value.slice(0, 4)));
const currentMonth = computed(() => Number(visibleMonth.value.slice(5, 7)));
const monthLabel = computed(() => new Intl.DateTimeFormat("nl-NL", { month: "long", year: "numeric", timeZone: "UTC" }).format(new Date(`${visibleMonth.value}-01T00:00:00Z`)));
const programOptions = computed(() => [{ value: "all", label: "Alle programma’s" }, ...(calendar.value?.filters.programs ?? [])]);
const statusOptions = computed(() => [{ value: "all", label: "Alle statussen" }, ...(calendar.value?.filters.statuses.map(({ value, label }) => ({ value, label })) ?? [])]);

function matchingAggregates(day: CalendarOverviewDay): CalendarOverviewAggregate[] {
  return day.aggregates.filter((item) => (programFilter.value === "all" || item.program === programFilter.value) && (statusFilter.value === "all" || item.status === statusFilter.value));
}
function statusCount(status: CalendarOverviewStatus): number {
  return summaryDays.value.flatMap(matchingAggregates).filter((item) => item.status === status).reduce((sum, item) => sum + item.bookingCount, 0);
}
const summaryDays = computed(() => calendar.value?.days.filter((day) => day.inSelectedMonth) ?? []);
const summaryAggregates = computed(() => summaryDays.value.flatMap(matchingAggregates));
const summaryBookings = computed(() => summaryAggregates.value.reduce((sum, item) => sum + item.bookingCount, 0));
const summaryStudents = computed(() => summaryAggregates.value.reduce((sum, item) => sum + item.studentCount, 0));
const summaryInvalid = computed(() => summaryAggregates.value.reduce((sum, item) => sum + item.unknownStudentCount + item.invalidStudentCount, 0));
const matchingDates = computed(() => summaryDays.value.filter((day) => matchingAggregates(day).some((item) => item.bookingCount > 0)).length);
const activeAggregates = computed(() => summaryDays.value.flatMap(matchingAggregates).filter((item) => item.status === "In optie" || item.status === "Definitief"));
const activeStudents = computed(() => activeAggregates.value.reduce((sum, item) => sum + item.studentCount, 0));
const activeInvalid = computed(() => activeAggregates.value.reduce((sum, item) => sum + item.unknownStudentCount + item.invalidStudentCount, 0));
const activeDates = computed(() => summaryDays.value.filter((day) => matchingAggregates(day).some((item) => item.status !== "Afgewezen" && item.bookingCount > 0)).length);
const blockedCount = computed(() => calendar.value?.days.filter((day) => day.inSelectedMonth && (day.disabled || !day.isBookableWeekday)).length ?? 0);
const filtersActive = computed(() => programFilter.value !== "all" || statusFilter.value !== "all");

function studentText(count: number, invalid: number): string {
  return `${invalid ? "Minimaal " : ""}${new Intl.NumberFormat("nl-NL").format(count)} leerlingen${invalid ? ` · ${invalid} zonder geldig leerlingenaantal` : ""}`;
}
function resetFilters(): void { programFilter.value = "all"; statusFilter.value = "all"; }
function changeMonth(delta: number): void {
  const date = new Date(`${visibleMonth.value}-01T00:00:00Z`);
  date.setUTCMonth(date.getUTCMonth() + delta);
  visibleMonth.value = `${date.getUTCFullYear()}-${String(date.getUTCMonth() + 1).padStart(2, "0")}`;
}
async function load(): Promise<void> {
  const sequence = ++requestSequence;
  controller?.abort();
  controller = new AbortController();
  const cached = getCachedDashboardCalendarOverview(currentYear.value, currentMonth.value);
  if (cached) { calendar.value = cached; error.value = false; loading.value = false; return; }
  loading.value = true; error.value = false;
  try {
    const result = await fetchDashboardCalendarOverview(currentYear.value, currentMonth.value, controller.signal);
    if (sequence === requestSequence) calendar.value = result;
  } catch (caught) {
    if (caught instanceof DOMException && caught.name === "AbortError") return;
    if (sequence === requestSequence) error.value = true;
  } finally {
    if (sequence === requestSequence) loading.value = false;
  }
}
async function showDetails(date: string): Promise<void> {
  const sequence = ++detailSequence;
  detailController?.abort();
  detailController = new AbortController();
  detailDate.value = date;
  detail.value = null;
  detailLoading.value = true;
  detailError.value = false;
  try {
    const result = await fetchDashboardCalendar(date, date, detailController.signal);
    if (sequence !== detailSequence) return;
    detail.value = result.calendar.days.find((day) => day.date === date) ?? null;
    detailError.value = detail.value === null;
  } catch (caught) {
    if (caught instanceof DOMException && caught.name === "AbortError") return;
    if (sequence === detailSequence) detailError.value = true;
  } finally {
    if (sequence === detailSequence) detailLoading.value = false;
  }
}
function closeDetails(): void {
  detailSequence++;
  detailController?.abort();
  detailDate.value = null;
  detail.value = null;
}
function stopRequest(): void { controller?.abort(); closeDetails(); }
watch(visibleMonth, (value) => {
  const query = { ...route.query, month: value };
  void router.replace({ name: "calendar", query });
  void load();
}, { immediate: true });
watch(() => route.query.month, (value) => {
  if (typeof value === "string" && /^\d{4}-(?:0[1-9]|1[0-2])$/.test(value) && value !== visibleMonth.value) visibleMonth.value = value;
});
onActivated(() => {
  if (loading.value && !controller?.signal.aborted) return;
  // Status/date changes elsewhere can affect both source and destination months.
  invalidateDashboardCalendarOverviewCache();
  void load();
});
onDeactivated(stopRequest);
onBeforeUnmount(stopRequest);
</script>

<template>
  <section class="admin-calendar-overview" aria-label="Overzichtsagenda">
    <section class="admin-card admin-calendar-overview__filters" aria-label="Agenda filteren">
      <AdminSelect v-model="programFilter" name="calendar-program" label="Programma" :options="programOptions" :allow-empty="false" />
      <AdminSelect v-model="statusFilter" name="calendar-status" label="Status" :options="statusOptions" :allow-empty="false" />
      <button type="button" class="admin-calendar-overview__reset" :disabled="!filtersActive" @click="resetFilters"><RotateCcw :size="16" aria-hidden="true" />Filters herstellen</button>
    </section>

    <section v-if="calendar" class="admin-calendar-overview__summary" aria-label="Maandsamenvatting">
      <template v-if="statusFilter === 'all'">
        <span><strong>{{ statusCount("Definitief") }}</strong> Definitief</span>
        <span><strong>{{ statusCount("In optie") }}</strong> In optie</span>
        <span><strong>{{ statusCount("Afgewezen") }}</strong> Afgewezen</span>
        <span><strong>{{ studentText(activeStudents, activeInvalid) }}</strong><small>actieve planning</small></span>
        <span><strong>{{ activeDates }}</strong> bezoekdagen</span>
      </template>
      <template v-else>
        <span><strong>{{ summaryBookings }}</strong> aanvragen</span>
        <span><strong>{{ studentText(summaryStudents, summaryInvalid) }}</strong></span>
        <span><strong>{{ matchingDates }}</strong> kalenderdatums</span>
      </template>
      <span v-if="blockedCount"><strong>{{ blockedCount }}</strong> geblokkeerd voor nieuwe aanvragen</span>
    </section>

    <section class="admin-card admin-calendar admin-calendar-overview__calendar" :class="{ 'is-refreshing': loading && Boolean(calendar) }" :aria-busy="loading">
      <header class="admin-calendar__header">
        <AdminButton variant="secondary" aria-label="Vorige maand" @click="changeMonth(-1)"><ChevronLeft :size="18" aria-hidden="true" /></AdminButton>
        <h2 aria-live="polite">{{ monthLabel }}</h2>
        <AdminButton variant="secondary" aria-label="Volgende maand" @click="changeMonth(1)"><ChevronRight :size="18" aria-hidden="true" /></AdminButton>
      </header>
      <p class="admin-calendar-overview__scroll-hint">Veeg horizontaal om de volledige week te bekijken.</p>
      <div v-if="error" class="admin-calendar__message" role="alert"><p>De overzichtsagenda kon niet worden geladen.</p><AdminButton @click="load">Opnieuw proberen</AdminButton></div>
      <p v-else-if="loading && !calendar" class="admin-calendar__message" role="status">Overzichtsagenda laden…</p>
      <template v-else-if="calendar">
        <div class="admin-calendar__weekdays" aria-hidden="true"><span v-for="weekday in ['Ma', 'Di', 'Wo', 'Do', 'Vr', 'Za', 'Zo']" :key="weekday">{{ weekday }}</span></div>
        <div class="admin-calendar__grid admin-calendar-overview__grid" role="grid" :aria-label="`Aanvragen in ${monthLabel}`">
          <DashboardCalendarOverviewDayCell v-for="day in calendar.days" :key="day.date" :day="day" :aggregates="matchingAggregates(day)" :status-filter="statusFilter" :status-options="calendar.filters.statuses" :effective-capacity="effectiveCalendarOverviewCapacity(day.capacity, programFilter)" @details="showDetails" />
        </div>
        <div class="admin-calendar__legend admin-calendar-overview__legend" aria-label="Legenda">
          <template v-if="statusFilter === 'all'"><span class="admin-status admin-status--confirmed">Definitief</span><span class="admin-status admin-status--option">In optie</span><span class="admin-status admin-status--rejected">Afgewezen</span><span>Geen boekingen</span></template>
          <template v-else><span class="admin-status" :class="statusFilter === 'Definitief' ? 'admin-status--confirmed' : statusFilter === 'Afgewezen' ? 'admin-status--rejected' : 'admin-status--option'">{{ statusFilter }}</span><span>Geen resultaten</span></template>
          <span class="is-blocked">Geblokkeerd</span><span class="is-past">Verleden</span>
        </div>
      </template>
    </section>
    <AdminDialog :open="detailDate !== null" title="Boekingen op deze dag" @close="closeDetails">
      <p v-if="detailLoading" role="status">Dagoverzicht laden…</p>
      <div v-else-if="detailError" role="alert">
        <p>Het dagoverzicht kon niet worden geladen.</p>
        <AdminButton v-if="detailDate" @click="showDetails(detailDate)">Opnieuw proberen</AdminButton>
      </div>
      <DashboardCalendarDayDetail v-else-if="detail" :day="detail" />
    </AdminDialog>
  </section>
</template>

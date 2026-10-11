<script setup lang="ts">
import { formatRosterDate } from "../../services/rosterDate";
import { computed, inject, onBeforeUnmount, ref, watch } from "vue";
import AdminButton from "../form/AdminButton.vue";
import { adminBootstrapKey } from "../../types/admin";
import { fetchDashboardBookings } from "../../services/dashboardBookingsApi";
import { createDashboardRoster, fetchDashboardRoster, fetchDashboardRosters } from "../../services/dashboardRosterApi";
import type { DashboardBookingListItem } from "../../types/bookings";
import type { DashboardRosterPlan, DashboardRosterPlanSummary } from "../../types/roster";

const props = defineProps<{ schoolNumber: number; fixedVisitDate?: string; excludedBookingId?: number }>();
const emit = defineEmits<{ selected: [plan: DashboardRosterPlan] }>();
const bootstrap = inject(adminBootstrapKey);
if (!bootstrap) throw new Error("Admin bootstrapdata ontbreekt.");
const items = ref<DashboardBookingListItem[]>([]);
const existing = ref<DashboardRosterPlanSummary[]>([]);
const search = ref("");
const page = ref(1);
const totalPages = ref(0);
const loading = ref(false);
const opening = ref<number | null>(null);
const error = ref<string | null>(null);
let controller: AbortController | undefined;
let timer: ReturnType<typeof setTimeout> | undefined;
let generation = 0;
let autoSelectedKey: string | null = null;
const rosterByBooking = computed(() => new Map(existing.value.filter(p => p.bookingId !== null).map(p => [p.bookingId!, p])));
const days = computed(() => {
  const result = new Map<string, DashboardBookingListItem[]>();
  for (const item of items.value) {
    if (item.id === props.excludedBookingId) continue;
    const group = result.get(item.visitDate) ?? [];
    group.push(item);
    result.set(item.visitDate, group);
  }
  return [...result].map(([date, bookings]) => ({ date, bookings }));
});
const formatDate = formatRosterDate;

async function load(nextPage = 1): Promise<void> {
  let automatic: DashboardBookingListItem | undefined;
  const version = ++generation;
  controller?.abort();
  controller = new AbortController();
  const signal = controller.signal;
  loading.value = true;
  error.value = null;
  items.value = [];
  try {
    const [bookings, rosters] = await Promise.all([
      fetchDashboardBookings({ search: props.schoolNumber === 1 ? search.value : "", status: "", sector: "", program: "", module: "",
        dateFrom: props.fixedVisitDate ?? "", dateTo: props.fixedVisitDate ?? "", page: nextPage, sort: "desc", twoSchoolDaysOnly: true }, signal),
      fetchDashboardRosters(signal),
    ]);
    if (version !== generation) return;
    items.value = bookings.items;
    existing.value = rosters.items;
    page.value = bookings.pagination.currentPage;
    totalPages.value = bookings.pagination.totalPages;
    const candidates = bookings.items.filter(item => item.id !== props.excludedBookingId);
    if (props.schoolNumber === 2 && candidates.length === 1 && bookings.pagination.totalPages <= 1) {
      const key = `${props.fixedVisitDate}:${candidates[0]!.id}`;
      if (autoSelectedKey !== key) {
        autoSelectedKey = key;
        automatic = candidates[0];
      }
    }
  } catch (e) {
    if (!signal.aborted) error.value = e instanceof Error ? e.message : "De aanvragen konden niet worden geladen.";
  } finally {
    if (version === generation) loading.value = false;
  }
  if (automatic && version === generation && !signal.aborted) await select(automatic);
}

async function select(item: DashboardBookingListItem): Promise<void> {
  if (opening.value !== null || loading.value) return;
  opening.value = item.id;
  error.value = null;
  try {
    const saved = rosterByBooking.value.get(item.id);
    // Existing single-school and custom plans are the starting point, never recreated.
    const result = saved ? await fetchDashboardRoster(saved.id) : await createDashboardRoster(item.id, bootstrap!.rosterPlanCsrfToken);
    emit("selected", result.plan);
  } catch (e) {
    error.value = e instanceof Error ? e.message : "Het schoolrooster kon niet worden geopend.";
  } finally {
    opening.value = null;
  }
}

function searchChanged(): void {
  clearTimeout(timer);
  timer = setTimeout(() => { void load(); }, 250);
}
watch(() => [props.fixedVisitDate, props.excludedBookingId], () => { void load(); }, { immediate: true });
onBeforeUnmount(() => { generation++; controller?.abort(); clearTimeout(timer); });
</script>

<template>
  <section class="admin-card admin-roster-day-picker" aria-labelledby="day-booking-picker-title">
    <header>
      <p class="admin-eyebrow">School {{ schoolNumber }} kiezen</p>
      <h2 id="day-booking-picker-title">{{ schoolNumber === 1 ? "Kies de eerste school" : "Kies de tweede school" }}</h2>
      <p v-if="fixedVisitDate">De tweede school komt op {{ formatDate(fixedVisitDate) }}. Na jouw bevestiging starten beide scholen opnieuw vanuit hun aanvragen.</p>
      <p v-else>Je ziet uitsluitend aanvragen op dagen met twee tot vier scholen In optie of Definitief. Afgewezen aanvragen tellen niet mee.</p>
    </header>
    <label v-if="schoolNumber === 1" class="admin-field">
      <span class="admin-field__label">Zoek binnen deze aanvragen</span>
      <input v-model="search" type="search" class="admin-control" maxlength="150" placeholder="School of plaats" :disabled="opening !== null" @input="searchChanged">
    </label>
    <p v-if="error" class="admin-inline-error" role="alert">{{ error }}</p>
    <p v-if="loading" role="status">Passende aanvragen laden…</p>
    <p v-else-if="schoolNumber === 2 && opening !== null" role="status">De tweede school openen…</p>
    <p v-else-if="!days.length">Geen passende aanvragen gevonden{{ fixedVisitDate ? ' op deze bezoekdatum' : '' }}. Er moeten twee actieve aanvragen op dezelfde dag staan.</p>
    <section v-for="day in days" :key="day.date" class="admin-roster-day-picker__day">
      <h3>{{ formatDate(day.date) }} <span>· gezamenlijke bezoekdag</span></h3>
      <button v-for="booking in day.bookings" :key="booking.id" class="admin-roster-day-picker__booking" type="button" :disabled="opening !== null" @click="select(booking)">
        <span><strong>{{ booking.schoolName }}</strong><small>{{ booking.city }} · {{ booking.programLabel }} · {{ booking.status }}</small></span>
        <span class="admin-roster-day-picker__action">
          <strong>{{ opening === booking.id ? 'Openen…' : rosterByBooking.has(booking.id) ? 'School kiezen · bestaand rooster' : 'School kiezen' }}</strong>
          <small>{{ rosterByBooking.has(booking.id) ? 'Bij koppelen opnieuw beginnen, alleen na jouw toestemming.' : `${booking.studentCount ?? 'Onbekend aantal'} leerlingen` }}</small>
        </span>
      </button>
    </section>
    <nav v-if="totalPages > 1" class="admin-roster-day-picker__pagination" aria-label="Paginering schoolkeuze">
      <AdminButton variant="secondary" :disabled="loading || opening !== null || page <= 1" @click="load(page - 1)">Vorige</AdminButton>
      <span>Pagina {{ page }} van {{ totalPages }}</span>
      <AdminButton variant="secondary" :disabled="loading || opening !== null || page >= totalPages" @click="load(page + 1)">Volgende</AdminButton>
    </nav>
  </section>
</template>

<style scoped>
.admin-roster-day-picker input.admin-control { padding-inline: 1rem; }
.admin-roster-day-picker { display: grid; gap: 1rem; }
.admin-roster-day-picker header p { margin: .35rem 0 0; }
.admin-roster-day-picker h2 { margin: 0; font-size: 1.3rem; }
.admin-roster-day-picker__day { display: grid; gap: .6rem; padding: 1rem; border: 1px solid var(--admin-border); border-left: 4px solid var(--color-main-blue); border-radius: .8rem; background: var(--admin-surface-soft); }
.admin-roster-day-picker__day h3 { margin: 0; font-size: 1rem; }
.admin-roster-day-picker__day h3 span { font-weight: normal; color: var(--color-font-muted); }
.admin-roster-day-picker__booking { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; padding: .85rem 1rem; border: 1px solid var(--admin-border); border-radius: .6rem; background: var(--admin-surface); color: inherit; text-align: left; font: inherit; cursor: pointer; }
.admin-roster-day-picker__booking:hover:not(:disabled) { background: var(--admin-surface-soft); border-color: var(--color-main-blue); }
.admin-roster-day-picker__booking:focus-visible { outline: 2px solid var(--color-main-blue); outline-offset: 2px; }
.admin-roster-day-picker__booking:disabled { cursor: wait; opacity: .65; }
.admin-roster-day-picker__booking > span { display: grid; gap: .2rem; }
.admin-roster-day-picker__booking small { color: var(--color-font-muted); }
.admin-roster-day-picker__pagination { display: flex; align-items: center; justify-content: space-between; gap: .75rem; }
@media(max-width:680px) { .admin-roster-day-picker__booking { grid-template-columns: 1fr; gap: .5rem; } }
</style>

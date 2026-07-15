<script setup lang="ts">
import { computed, onBeforeUnmount, reactive, ref, watch } from "vue";
import { useRoute, useRouter, type LocationQueryRaw } from "vue-router";
import { fetchDashboardBookings } from "../services/dashboardBookingsApi";
import type {
  DashboardBookingFilterOptions,
  DashboardBookingFilters,
  DashboardBookingListItem,
  DashboardBookingPagination,
} from "../types/bookings";

const route = useRoute();
const router = useRouter();
const emptyOptions: DashboardBookingFilterOptions = { statuses: [], sectors: [], programs: [], modules: [] };
const emptyPagination: DashboardBookingPagination = { currentPage: 1, perPage: 20, totalItems: 0, totalPages: 0, from: 0, to: 0 };
const filters = reactive<DashboardBookingFilters>({ search: "", status: "", sector: "", program: "", module: "", dateFrom: "", dateTo: "", page: 1 });
const items = ref<DashboardBookingListItem[]>([]);
const pagination = ref<DashboardBookingPagination>(emptyPagination);
const options = ref<DashboardBookingFilterOptions>(emptyOptions);
const loading = ref(false);
const error = ref(false);
let searchTimer: ReturnType<typeof setTimeout> | undefined;
let controller: AbortController | undefined;

const hasActiveFilters = computed(() => [filters.search, filters.status, filters.sector, filters.program, filters.module, filters.dateFrom, filters.dateTo].some(Boolean));
const statusMessage = computed(() => {
  if (error.value) return "De aanvragen konden niet worden geladen.";
  if (loading.value) return "Aanvragen worden geladen.";
  return `${pagination.value.totalItems} aanvragen gevonden.`;
});

function queryString(value: unknown): string {
  return typeof value === "string" ? value : "";
}

function readRoute(): void {
  filters.search = queryString(route.query.search);
  filters.status = queryString(route.query.status);
  filters.sector = queryString(route.query.sector);
  filters.program = queryString(route.query.program);
  filters.module = queryString(route.query.module);
  filters.dateFrom = queryString(route.query.dateFrom);
  filters.dateTo = queryString(route.query.dateTo);
  const page = Number(queryString(route.query.page));
  filters.page = Number.isInteger(page) && page > 0 ? page : 1;
}

async function load(): Promise<void> {
  controller?.abort();
  const requestController = new AbortController();
  controller = requestController;
  loading.value = true;
  error.value = false;
  try {
    const result = await fetchDashboardBookings({ ...filters }, requestController.signal);
    items.value = result.items;
    pagination.value = result.pagination;
    options.value = result.filters;
    if (result.pagination.currentPage !== filters.page) {
      await updateRoute({ page: result.pagination.currentPage }, true);
    }
  } catch (requestError) {
    if (requestError instanceof DOMException && requestError.name === "AbortError") return;
    error.value = true;
  } finally {
    if (!requestController.signal.aborted) loading.value = false;
  }
}

function routeQuery(overrides: Partial<DashboardBookingFilters> = {}): LocationQueryRaw {
  const next = { ...filters, ...overrides };
  const query: LocationQueryRaw = {};
  for (const key of ["search", "status", "sector", "program", "module", "dateFrom", "dateTo"] as const) {
    if (next[key] !== "") query[key] = next[key];
  }
  if (next.page > 1) query.page = String(next.page);
  return query;
}

async function updateRoute(overrides: Partial<DashboardBookingFilters>, replace = false): Promise<void> {
  const location = { name: "bookings", query: routeQuery(overrides) };
  await (replace ? router.replace(location) : router.push(location));
}

function applyFilter(): void {
  void updateRoute({ page: 1 });
}

function scheduleSearch(): void {
  clearTimeout(searchTimer);
  searchTimer = setTimeout(applyFilter, 350);
}

function clearFilters(): void {
  clearTimeout(searchTimer);
  void router.push({ name: "bookings" });
}

function changePage(page: number): void {
  if (page < 1 || page > pagination.value.totalPages || page === pagination.value.currentPage) return;
  void updateRoute({ page }).then(() => document.querySelector(".admin-bookings__results")?.scrollIntoView({ behavior: "smooth", block: "start" }));
}

function formatDate(value: string): string {
  const [year, month, day] = value.split("-");
  return year && month && day ? `${day}-${month}-${year}` : value;
}

function statusClass(status: string): string {
  if (status === "Definitief") return "admin-status--confirmed";
  if (status === "Afgewezen") return "admin-status--rejected";
  return "admin-status--option";
}

watch(() => route.fullPath, () => { readRoute(); void load(); }, { immediate: true });
onBeforeUnmount(() => { clearTimeout(searchTimer); controller?.abort(); });
</script>

<template>
  <section class="admin-bookings" aria-labelledby="bookings-title">
    <header class="admin-bookings__intro">
      <p class="admin-eyebrow">Onderwijsformulier 2.0</p>
      <h1 id="bookings-title">Aanvragen</h1>
      <p>Zoek en filter alle ontvangen onderwijsaanvragen. Dit overzicht is alleen-lezen.</p>
      <p class="admin-bookings__count" aria-live="polite">{{ statusMessage }}</p>
    </header>

    <form class="admin-booking-filters" aria-label="Aanvragen filteren" @submit.prevent="applyFilter">
      <div class="admin-booking-filters__search">
        <label for="booking-search">Zoeken</label>
        <input id="booking-search" v-model="filters.search" type="search" maxlength="150" placeholder="School, plaats, contactpersoon of e-mail" @input="scheduleSearch">
      </div>
      <div><label for="booking-status">Status</label><select id="booking-status" v-model="filters.status" @change="applyFilter"><option value="">Alle statussen</option><option v-for="option in options.statuses" :key="option.value" :value="option.value">{{ option.label }}</option></select></div>
      <div><label for="booking-sector">Onderwijssector</label><select id="booking-sector" v-model="filters.sector" @change="applyFilter"><option value="">Alle sectoren</option><option v-for="option in options.sectors" :key="option.value" :value="option.value">{{ option.label }}</option></select></div>
      <div><label for="booking-program">Programma</label><select id="booking-program" v-model="filters.program" @change="applyFilter"><option value="">Alle programma’s</option><option v-for="option in options.programs" :key="option.value" :value="option.value">{{ option.label }}</option></select></div>
      <div><label for="booking-module">Keuzemodule</label><select id="booking-module" v-model="filters.module" @change="applyFilter"><option value="">Alle keuzemodules</option><option v-for="option in options.modules" :key="option.value" :value="option.value">{{ option.label }}</option></select></div>
      <div><label for="booking-date-from">Bezoekdatum vanaf</label><input id="booking-date-from" v-model="filters.dateFrom" type="date" @change="applyFilter"></div>
      <div><label for="booking-date-to">Bezoekdatum tot</label><input id="booking-date-to" v-model="filters.dateTo" type="date" @change="applyFilter"></div>
      <button class="admin-button admin-button--secondary" type="button" :disabled="!hasActiveFilters" @click="clearFilters">Filters wissen</button>
    </form>

    <div class="admin-bookings__results" :aria-busy="loading">
      <div v-if="error" class="admin-bookings__state" role="alert">
        <p>De aanvragen konden niet worden geladen. Probeer het opnieuw.</p>
        <button class="admin-button admin-button--primary" type="button" @click="load">Opnieuw proberen</button>
      </div>
      <div v-else-if="loading && items.length === 0" class="admin-bookings__state" role="status"><p>Aanvragen laden…</p></div>
      <div v-else-if="items.length === 0" class="admin-bookings__state">
        <p>Geen aanvragen gevonden met deze filters.</p>
        <button v-if="hasActiveFilters" class="admin-button admin-button--secondary" type="button" @click="clearFilters">Filters wissen</button>
      </div>
      <template v-else>
        <div v-if="loading" class="admin-bookings__updating" role="status">Resultaten bijwerken…</div>
        <div class="admin-bookings-table-wrap">
          <table class="admin-bookings-table">
            <thead><tr><th>Status</th><th>Bezoekdatum</th><th>Aanvraag</th><th>School</th><th>Plaats</th><th>Sector</th><th>Programma</th><th>Keuzemodule</th><th>Leerlingen</th><th>Contactpersoon</th></tr></thead>
            <tbody><tr v-for="item in items" :key="item.id"><td><span class="admin-status" :class="statusClass(item.status)">{{ item.status }}</span></td><td>{{ formatDate(item.visitDate) }}</td><td>#{{ item.id }}</td><td>{{ item.schoolName }}</td><td>{{ item.city }}</td><td>{{ item.sectorLabel }}</td><td>{{ item.programLabel }}</td><td>{{ item.moduleLabel }}</td><td>{{ item.studentCount ?? "Onbekend" }}</td><td>{{ item.contactPersonName }}</td></tr></tbody>
          </table>
        </div>
        <div class="admin-booking-cards">
          <article v-for="item in items" :key="item.id" class="admin-booking-card">
            <div class="admin-booking-card__header"><span class="admin-status" :class="statusClass(item.status)">{{ item.status }}</span><strong>#{{ item.id }}</strong></div>
            <h2>{{ item.schoolName }}</h2><p>{{ formatDate(item.visitDate) }} · {{ item.city }}</p>
            <dl><dt>Sector</dt><dd>{{ item.sectorLabel }}</dd><dt>Programma</dt><dd>{{ item.programLabel }}</dd><dt>Keuzemodule</dt><dd>{{ item.moduleLabel }}</dd><dt>Leerlingen</dt><dd>{{ item.studentCount ?? "Onbekend" }}</dd><dt>Contactpersoon</dt><dd>{{ item.contactPersonName }}</dd></dl>
          </article>
        </div>
        <nav v-if="pagination.totalPages > 0" class="admin-pagination" aria-label="Paginering aanvragen">
          <button class="admin-button admin-button--secondary" type="button" :disabled="pagination.currentPage <= 1 || loading" @click="changePage(pagination.currentPage - 1)">Vorige</button>
          <p><strong>{{ pagination.from }}–{{ pagination.to }}</strong> van {{ pagination.totalItems }} · pagina {{ pagination.currentPage }} van {{ pagination.totalPages }}</p>
          <button class="admin-button admin-button--secondary" type="button" :disabled="pagination.currentPage >= pagination.totalPages || loading" @click="changePage(pagination.currentPage + 1)">Volgende</button>
        </nav>
      </template>
    </div>
  </section>
</template>

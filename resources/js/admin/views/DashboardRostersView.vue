<script setup lang="ts">
import {
  computed,
  inject,
  onBeforeUnmount,
  reactive,
  ref,
  watch,
} from "vue";
import {
  RouterLink,
  useRoute,
  useRouter,
  type LocationQueryRaw,
} from "vue-router";
import { Trash2 } from "lucide-vue-next";

import AdminButton from "../components/form/AdminButton.vue";
import AdminConfirmationControl from "../components/form/AdminConfirmationControl.vue";
import AdminDateField from "../components/form/AdminDateField.vue";
import AdminSelect from "../components/form/AdminSelect.vue";
import {
  deleteDashboardRoster,
  fetchDashboardRosters,
} from "../services/dashboardRosterApi";
import { adminBootstrapKey } from "../types/admin";
import type { DashboardRosterPlanSummary } from "../types/roster";

type RosterSort = "desc" | "asc";
type RosterSource = "" | "booking" | "manual";

interface RosterFilters {
  search: string;
  status: string;
  sector: string;
  program: string;
  module: string;
  source: RosterSource;
  dateFrom: string;
  dateTo: string;
  page: number;
  sort: RosterSort;
}

const route = useRoute();
const router = useRouter();
const bootstrap = inject(adminBootstrapKey);

if (!bootstrap) {
  throw new Error("Admin bootstrapdata ontbreekt.");
}

const rosterPlanCsrfToken = bootstrap.rosterPlanCsrfToken;

const filters = reactive<RosterFilters>({
  search: "",
  status: "",
  sector: "",
  program: "",
  module: "",
  source: "",
  dateFrom: "",
  dateTo: "",
  page: 1,
  sort: "desc",
});

const items = ref<DashboardRosterPlanSummary[]>([]);
const loading = ref(false);
const error = ref(false);
const deleteCandidate = ref<DashboardRosterPlanSummary | null>(null);
const deleteConfirmed = ref(false);
const deletingId = ref<number | null>(null);
const deleteError = ref<string | null>(null);
let searchTimer: ReturnType<typeof setTimeout> | undefined;
let controller: AbortController | undefined;

function uniqueOptions(values: Array<string | null | undefined>) {
  return [...new Set(values.filter((value): value is string => Boolean(value)))]
    .sort((left, right) => left.localeCompare(right, "nl"))
    .map((value) => ({ value, label: value }));
}

const options = computed(() => ({
  statuses: [
    { value: "concept", label: "Concept" },
    { value: "checked", label: "Gecontroleerd" },
    { value: "published", label: "Definitief" },
  ].filter((option) => items.value.some((item) => item.status === option.value)),
  sectors: uniqueOptions(items.value.map((item) => item.sectorLabel)),
  programs: uniqueOptions(items.value.map((item) => item.programLabel)),
  modules: uniqueOptions(items.value.map((item) => item.moduleLabel)),
  sources: [
    { value: "booking", label: "Vanuit aanvraag" },
    { value: "manual", label: "Handmatig" },
  ],
}));

const availableDateRange = computed(() => {
  const dates = items.value
    .map((item) => item.visitDate)
    .filter(Boolean)
    .sort();

  return {
    min: dates[0] ?? null,
    max: dates.at(-1) ?? null,
  };
});

const hasActiveFilters = computed(() =>
  Boolean(
    filters.search
    || filters.status
    || filters.sector
    || filters.program
    || filters.module
    || filters.source
    || filters.dateFrom
    || filters.dateTo
    || filters.sort !== "desc"
  ),
);

const filteredItems = computed(() => {
  const search = filters.search.trim().toLocaleLowerCase("nl");

  const result = items.value.filter((item) => {
    const source = item.bookingId === null ? "manual" : "booking";
    const searchable = [
      item.schoolName,
      item.city,
      item.programLabel,
      planningModeLabel(item),
      item.moduleLabel ?? "",
      String(item.id),
    ].join(" ").toLocaleLowerCase("nl");

    return (!search || searchable.includes(search))
      && (!filters.status || item.status === filters.status)
      && (!filters.sector || item.sectorLabel === filters.sector)
      && (!filters.program || item.programLabel === filters.program)
      && (!filters.module || item.moduleLabel === filters.module)
      && (!filters.source || source === filters.source)
      && (!filters.dateFrom || item.visitDate >= filters.dateFrom)
      && (!filters.dateTo || item.visitDate <= filters.dateTo);
  });

  return result.sort((left, right) => {
    const byDate = left.visitDate.localeCompare(right.visitDate);
    const byId = left.id - right.id;
    const comparison = byDate !== 0 ? byDate : byId;
    return filters.sort === "asc" ? comparison : -comparison;
  });
});

const pagination = computed(() => {
  const perPage = 20;
  const totalItems = filteredItems.value.length;
  const totalPages = Math.ceil(totalItems / perPage);
  const currentPage = totalPages === 0
    ? 1
    : Math.min(Math.max(filters.page, 1), totalPages);
  const from = totalItems === 0 ? 0 : ((currentPage - 1) * perPage) + 1;
  const to = Math.min(currentPage * perPage, totalItems);

  return {
    perPage,
    totalItems,
    totalPages,
    currentPage,
    from,
    to,
  };
});

const visibleItems = computed(() => {
  const start = (pagination.value.currentPage - 1) * pagination.value.perPage;
  return filteredItems.value.slice(start, start + pagination.value.perPage);
});

const statusMessage = computed(() => {
  if (error.value) return "Roosters konden niet worden geladen";
  if (loading.value) return "Resultaten bijwerken…";
  if (pagination.value.totalItems === 0) return "Geen roosters gevonden";
  return pagination.value.totalItems === 1
    ? "1 rooster gevonden"
    : `${pagination.value.totalItems} roosters gevonden`;
});

const availableDateRangeLabel = computed(() => {
  const { min, max } = availableDateRange.value;
  if (!min || !max) return "";
  return `Beschikbare bezoekdata: ${formatDutchDate(min)} t/m ${formatDutchDate(max)}`;
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
  filters.source = ["booking", "manual"].includes(queryString(route.query.source))
    ? queryString(route.query.source) as RosterSource
    : "";
  filters.dateFrom = queryString(route.query.dateFrom);
  filters.dateTo = queryString(route.query.dateTo);
  filters.sort = route.query.sort === "asc" ? "asc" : "desc";

  const page = Number(queryString(route.query.page));
  filters.page = Number.isInteger(page) && page > 0 ? page : 1;
}

function routeQuery(overrides: Partial<RosterFilters> = {}): LocationQueryRaw {
  const next = { ...filters, ...overrides };
  const query: LocationQueryRaw = {};

  for (const key of [
    "search",
    "status",
    "sector",
    "program",
    "module",
    "source",
    "dateFrom",
    "dateTo",
  ] as const) {
    if (next[key] !== "") query[key] = next[key];
  }

  if (next.sort === "asc") query.sort = "asc";
  if (next.page > 1) query.page = String(next.page);

  return query;
}

async function updateRoute(
  overrides: Partial<RosterFilters>,
  replace = false,
): Promise<void> {
  const location = {
    name: "rosters",
    query: routeQuery(overrides),
  };

  await (replace ? router.replace(location) : router.push(location));
}

function applyFilter(): void {
  void updateRoute({ page: 1 });
}

function scheduleSearch(): void {
  clearTimeout(searchTimer);
  searchTimer = setTimeout(applyFilter, 350);
}

function changeSort(sort: RosterSort): void {
  clearTimeout(searchTimer);
  filters.sort = sort;
  applyFilter();
}

function clearFilters(): void {
  clearTimeout(searchTimer);
  void router.push({ name: "rosters" });
}

function changePage(page: number): void {
  if (
    page < 1
    || page > pagination.value.totalPages
    || page === pagination.value.currentPage
  ) {
    return;
  }

  void updateRoute({ page }).then(() => {
    document
      .querySelector(".admin-bookings__results")
      ?.scrollIntoView({ behavior: "smooth", block: "start" });
  });
}

function rosterLocation(item: DashboardRosterPlanSummary) {
  return {
    name: "roster-detail",
    params: { id: item.id },
  };
}

function openRoster(item: DashboardRosterPlanSummary): void {
  void router.push(rosterLocation(item));
}

function handleRosterKeydown(
  event: KeyboardEvent,
  item: DashboardRosterPlanSummary,
): void {
  if (event.key !== "Enter" && event.key !== " ") return;
  event.preventDefault();
  openRoster(item);
}

function sourceLabel(item: DashboardRosterPlanSummary): string {
  return item.bookingId === null ? "Handmatig" : "Aanvraag";
}

function planningModeLabel(item: DashboardRosterPlanSummary): string {
  return item.planningMode === "custom" ? "Maatwerk" : "Standaard";
}

function planningModeClass(item: DashboardRosterPlanSummary): string {
  return item.planningMode === "custom"
    ? "admin-roster-mode-badge--custom"
    : "admin-roster-mode-badge--standard";
}

function formatDate(value: string): string {
  const [year, month, day] = value.split("-");
  return year && month && day ? `${day}-${month}-${year}` : value;
}

function formatDutchDate(value: string): string {
  const [year, month, day] = value.split("-").map(Number);
  if (!year || !month || !day) return value;

  return new Intl.DateTimeFormat("nl-NL", {
    day: "numeric",
    month: "long",
    year: "numeric",
    timeZone: "UTC",
  }).format(new Date(Date.UTC(year, month - 1, day)));
}

function statusLabel(status: string): string {
  if (status === "checked") return "Gecontroleerd";
  if (status === "published") return "Definitief";
  return "Concept";
}

function statusClass(status: string): string {
  return status === "published"
    ? "admin-status--confirmed"
    : "admin-status--option";
}

function requestDelete(item: DashboardRosterPlanSummary): void {
  if (item.status !== "concept" || deletingId.value !== null) return;

  deleteCandidate.value = item;
  deleteConfirmed.value = false;
  deleteError.value = null;
}

function closeDeleteDialog(): void {
  if (deletingId.value !== null) return;

  deleteCandidate.value = null;
  deleteConfirmed.value = false;
  deleteError.value = null;
}

async function confirmDelete(): Promise<void> {
  const candidate = deleteCandidate.value;

  if (!candidate || !deleteConfirmed.value || deletingId.value !== null) {
    return;
  }

  deletingId.value = candidate.id;
  deleteError.value = null;

  try {
    await deleteDashboardRoster(candidate.id, rosterPlanCsrfToken);
    items.value = items.value.filter((item) => item.id !== candidate.id);
    deletingId.value = null;
    closeDeleteDialog();
  } catch (requestError) {
    deleteError.value = requestError instanceof Error
      ? requestError.message
      : "Het rooster kon niet worden verwijderd.";
  } finally {
    deletingId.value = null;
  }
}

async function load(): Promise<void> {
  controller?.abort();
  const requestController = new AbortController();
  controller = requestController;
  loading.value = true;
  error.value = false;

  try {
    const result = await fetchDashboardRosters(requestController.signal);
    items.value = result.items;
  } catch (requestError) {
    if (requestError instanceof DOMException && requestError.name === "AbortError") return;
    error.value = true;
  } finally {
    if (!requestController.signal.aborted) loading.value = false;
  }
}

watch(
  () => route.fullPath,
  readRoute,
  { immediate: true },
);

void load();

onBeforeUnmount(() => {
  clearTimeout(searchTimer);
  controller?.abort();
});
</script>

<template>
  <section class="admin-rosters" aria-labelledby="rosters-title">
    <header class="admin-rosters__intro">
      <div>
        <p class="admin-eyebrow">Onderwijsplanning</p>
        <h1 id="rosters-title">Roosters</h1>
        <p>
          Beheer operationele les- en docentenroosters vanuit een aanvraag
          of als rooster op maat.
        </p>
      </div>

      <div class="admin-rosters__actions">
        <RouterLink
          class="admin-button admin-button--primary"
          :to="{ name: 'roster-create' }"
        >
          Rooster maken
        </RouterLink>

        <RouterLink
          class="admin-button admin-button--secondary"
          :to="{ name: 'roster-staff' }"
        >
          Personeel & vaardigheden
        </RouterLink>
      </div>
    </header>

    <form
      class="admin-booking-filters"
      aria-label="Roosters filteren"
      @submit.prevent="applyFilter"
    >
      <div class="admin-field admin-booking-filters__search">
        <label class="admin-field__label" for="roster-search">
          Zoeken
        </label>
        <input
          id="roster-search"
          v-model="filters.search"
          class="admin-control"
          type="search"
          maxlength="150"
          autocomplete="off"
          placeholder="School, plaats, programma of rooster-ID"
          @input="scheduleSearch"
        >
      </div>

      <AdminSelect
        v-model="filters.status"
        name="roster-status"
        label="Status"
        placeholder="Alle statussen"
        :options="options.statuses"
        @update:model-value="applyFilter"
      />

      <AdminSelect
        v-model="filters.source"
        name="roster-source"
        label="Bron"
        placeholder="Alle bronnen"
        :options="options.sources"
        @update:model-value="applyFilter"
      />

      <AdminSelect
        v-model="filters.sector"
        name="roster-sector"
        label="Onderwijssector"
        placeholder="Alle sectoren"
        :options="options.sectors"
        @update:model-value="applyFilter"
      />

      <AdminSelect
        v-model="filters.program"
        name="roster-program"
        label="Programma"
        placeholder="Alle programma’s"
        :options="options.programs"
        @update:model-value="applyFilter"
      />

      <AdminSelect
        v-model="filters.module"
        name="roster-module"
        label="Keuzemodule"
        placeholder="Alle keuzemodules"
        :options="options.modules"
        @update:model-value="applyFilter"
      />

      <AdminDateField
        v-model="filters.dateFrom"
        name="roster-date-from"
        label="Bezoekdatum vanaf"
        :min="availableDateRange.min || undefined"
        :max="filters.dateTo || availableDateRange.max || undefined"
        clearable
        @update:model-value="applyFilter"
      />

      <AdminDateField
        v-model="filters.dateTo"
        name="roster-date-to"
        label="Bezoekdatum tot"
        :min="filters.dateFrom || availableDateRange.min || undefined"
        :max="availableDateRange.max || undefined"
        clearable
        @update:model-value="applyFilter"
      />

      <footer class="admin-booking-filters__footer">
        <div class="admin-booking-filters__sort">
          <span id="roster-sort-label" class="admin-field__label">
            Sorteervolgorde
          </span>
          <div
            class="admin-booking-filters__sort-toggle"
            role="group"
            aria-labelledby="roster-sort-label"
          >
            <button
              type="button"
              :aria-pressed="filters.sort === 'desc'"
              @click="changeSort('desc')"
            >
              Nieuwste eerst
            </button>
            <button
              type="button"
              :aria-pressed="filters.sort === 'asc'"
              @click="changeSort('asc')"
            >
              Oudste eerst
            </button>
          </div>
        </div>

        <div class="admin-booking-filters__summary">
          <p class="admin-booking-filters__result" aria-live="polite">
            {{ statusMessage }}
          </p>
          <p
            v-if="availableDateRangeLabel"
            class="admin-booking-filters__date-range"
          >
            {{ availableDateRangeLabel }}
          </p>
        </div>

        <AdminButton
          variant="secondary"
          :disabled="!hasActiveFilters"
          @click="clearFilters"
        >
          Filters wissen
        </AdminButton>
      </footer>
    </form>

    <div class="admin-bookings__results" :aria-busy="loading">
      <div v-if="error" class="admin-bookings__state" role="alert">
        <p>De roosters konden niet worden geladen.</p>
        <AdminButton :loading="loading" @click="load">Opnieuw proberen</AdminButton>
      </div>

      <div
        v-else-if="loading && items.length === 0"
        class="admin-bookings__state"
        role="status"
      >
        <p>Roosters laden&hellip;</p>
      </div>

      <div v-else-if="filteredItems.length === 0" class="admin-bookings__state">
        <p>
          {{ items.length === 0
            ? "Er zijn nog geen roosters."
            : "Geen roosters gevonden met deze filters." }}
        </p>

        <RouterLink
          v-if="items.length === 0"
          class="admin-button admin-button--primary"
          :to="{ name: 'roster-create' }"
        >
          Eerste rooster maken
        </RouterLink>

        <AdminButton
          v-else-if="hasActiveFilters"
          variant="secondary"
          @click="clearFilters"
        >
          Filters wissen
        </AdminButton>
      </div>

      <template v-else>
        <div v-if="loading" class="admin-bookings__updating" role="status">
          Resultaten bijwerken…
        </div>

        <div class="admin-bookings-table-wrap">
          <table class="admin-bookings-table">
            <thead>
              <tr>
                <th scope="col">Status</th>
                <th scope="col">Bezoekdatum</th>
                <th scope="col">Bron</th>
                <th scope="col">Type</th>
                <th scope="col">School</th>
                <th scope="col">Programma</th>
                <th scope="col">Leerlingen</th>
                <th scope="col">Roostergroepen</th>
                <th scope="col">Acties</th>
              </tr>
            </thead>
            <tbody>
              <tr
                v-for="item in visibleItems"
                :key="item.id"
                class="admin-bookings-table__row-link"
                role="link"
                tabindex="0"
                :aria-label="`Rooster van ${item.schoolName} openen`"
                @click="openRoster(item)"
                @keydown="handleRosterKeydown($event, item)"
              >
                <td>
                  <span class="admin-status" :class="statusClass(item.status)">
                    {{ statusLabel(item.status) }}
                  </span>
                </td>
                <td>{{ formatDate(item.visitDate) }}</td>
                <td>{{ sourceLabel(item) }}</td>
                <td>
                  <span class="admin-roster-mode-badge" :class="planningModeClass(item)">
                    {{ planningModeLabel(item) }}
                  </span>
                </td>
                <td>
                  <strong>{{ item.schoolName }}</strong>
                  <span v-if="item.city"> &middot; {{ item.city }}</span>
                </td>
                <td>
                  {{ item.programLabel }}
                  <template v-if="item.moduleLabel"> &middot; {{ item.moduleLabel }}</template>
                </td>
                <td>{{ item.studentCount ?? "Onbekend" }}</td>
                <td>{{ item.groupCount }}</td>
                <td>
                  <div class="admin-roster-list-actions">
                    <RouterLink
                      class="admin-bookings__view-link"
                      :to="rosterLocation(item)"
                      @click.stop
                      @keydown.stop
                    >
                      Openen <span aria-hidden="true">&rarr;</span>
                    </RouterLink>

                    <button
                      v-if="item.status === 'concept'"
                      class="admin-roster-delete-trigger"
                      type="button"
                      :disabled="deletingId !== null"
                      :aria-label="`Conceptrooster van ${item.schoolName} verwijderen`"
                      title="Rooster verwijderen"
                      @click.stop="requestDelete(item)"
                      @keydown.stop
                    >
                      <Trash2 :size="17" aria-hidden="true" />
                    </button>
                  </div>
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <div class="admin-booking-cards">
          <article
            v-for="item in visibleItems"
            :key="item.id"
            class="admin-booking-card"
            role="link"
            tabindex="0"
            :aria-label="`Rooster van ${item.schoolName} openen`"
            @click="openRoster(item)"
            @keydown="handleRosterKeydown($event, item)"
          >
            <div class="admin-booking-card__header">
              <span class="admin-status" :class="statusClass(item.status)">
                {{ statusLabel(item.status) }}
              </span>
              <div class="admin-roster-card-mode">
                <strong>{{ sourceLabel(item) }}</strong>
                <span class="admin-roster-mode-badge" :class="planningModeClass(item)">
                  {{ planningModeLabel(item) }}
                </span>
              </div>
            </div>

            <h2>{{ item.schoolName }}</h2>
            <p>{{ formatDate(item.visitDate) }} · {{ item.city }}</p>

            <dl>
              <dt>Programma</dt>
              <dd>{{ item.programLabel }}</dd>
              <dt>Keuzemodule</dt>
              <dd>{{ item.moduleLabel ?? "Geen" }}</dd>
              <dt>Leerlingen</dt>
              <dd>{{ item.studentCount ?? "Onbekend" }}</dd>
              <dt>Roostergroepen</dt>
              <dd>{{ item.groupCount }}</dd>
            </dl>

            <div class="admin-roster-card-actions">
              <RouterLink
                class="admin-button admin-button--secondary admin-booking-card__view"
                :to="rosterLocation(item)"
                @click.stop
                @keydown.stop
              >
                Openen <span aria-hidden="true">→</span>
              </RouterLink>

              <AdminButton
                v-if="item.status === 'concept'"
                class="admin-roster-delete-card-trigger"
                variant="secondary"
                :disabled="deletingId !== null"
                @click.stop="requestDelete(item)"
                @keydown.stop
              >
                <Trash2 :size="17" aria-hidden="true" />
                Verwijderen
              </AdminButton>
            </div>
          </article>
        </div>

        <nav
          v-if="pagination.totalPages > 1"
          class="admin-pagination"
          aria-label="Paginering roosters"
        >
          <AdminButton
            variant="secondary"
            :disabled="pagination.currentPage <= 1 || loading"
            @click="changePage(pagination.currentPage - 1)"
          >
            Vorige
          </AdminButton>

          <p>
            <strong>{{ pagination.from }}–{{ pagination.to }}</strong>
            van {{ pagination.totalItems }}
            · pagina {{ pagination.currentPage }} van {{ pagination.totalPages }}
          </p>

          <AdminButton
            variant="secondary"
            :disabled="pagination.currentPage >= pagination.totalPages || loading"
            @click="changePage(pagination.currentPage + 1)"
          >
            Volgende
          </AdminButton>
        </nav>
      </template>
    </div>

    <div
      v-if="deleteCandidate"
      class="admin-roster-delete-dialog"
      role="presentation"
      @click.self="closeDeleteDialog"
    >
      <section
        class="admin-card admin-roster-delete-dialog__panel"
        role="dialog"
        aria-modal="true"
        aria-labelledby="roster-delete-title"
      >
        <header>
          <div>
            <p class="admin-eyebrow">Roosterbeheer</p>
            <h2 id="roster-delete-title">Rooster verwijderen?</h2>
          </div>
          <button
            class="admin-roster-delete-dialog__close"
            type="button"
            :disabled="deletingId !== null"
            aria-label="Venster sluiten"
            @click="closeDeleteDialog"
          >
            &times;
          </button>
        </header>

        <p>
          Je staat op het punt het conceptrooster van
          <strong>{{ deleteCandidate.schoolName }}</strong>
          op <strong>{{ formatDate(deleteCandidate.visitDate) }}</strong>
          permanent te verwijderen.
        </p>

        <p v-if="deleteCandidate.bookingId !== null" class="admin-inline-notice">
          De onderwijsaanvraag blijft bestaan. Alleen het rooster wordt verwijderd.
        </p>

        <AdminConfirmationControl
          v-model="deleteConfirmed"
          label="Ik wil dit conceptrooster verwijderen"
          description="De roosterindeling en personeelskoppelingen van dit rooster worden verwijderd."
          :disabled="deletingId !== null"
        />

        <p v-if="deleteError" class="admin-inline-error" role="alert">
          {{ deleteError }}
        </p>

        <footer>
          <AdminButton
            variant="secondary"
            :disabled="deletingId !== null"
            @click="closeDeleteDialog"
          >
            Annuleren
          </AdminButton>
          <AdminButton
            variant="danger"
            :loading="deletingId === deleteCandidate.id"
            :disabled="!deleteConfirmed"
            @click="confirmDelete"
          >
            Rooster verwijderen
          </AdminButton>
        </footer>
      </section>
    </div>
  </section>
</template>

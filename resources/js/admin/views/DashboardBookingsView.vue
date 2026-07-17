<script setup lang="ts">
import {
  computed,
  onBeforeUnmount,
  reactive,
  ref,
  watch,
} from "vue";
import {
  useRoute,
  useRouter,
  RouterLink,
  type LocationQueryRaw,
} from "vue-router";

import AdminButton from "../components/form/AdminButton.vue";
import AdminDateField from "../components/form/AdminDateField.vue";
import AdminSelect from "../components/form/AdminSelect.vue";
import { fetchDashboardBookings } from "../services/dashboardBookingsApi";

import type {
  DashboardBookingFilterOptions,
  DashboardBookingFilters,
  DashboardBookingListItem,
  DashboardBookingPagination,
} from "../types/bookings";

const route = useRoute();
const router = useRouter();

const emptyOptions: DashboardBookingFilterOptions = {
  statuses: [],
  sectors: [],
  programs: [],
  modules: [],
  dateRange: {
    min: null,
    max: null,
  },
};

const emptyPagination: DashboardBookingPagination = {
  currentPage: 1,
  perPage: 20,
  totalItems: 0,
  totalPages: 0,
  from: 0,
  to: 0,
};

const filters = reactive<DashboardBookingFilters>({
  search: "",
  status: "",
  sector: "",
  program: "",
  module: "",
  dateFrom: "",
  dateTo: "",
  page: 1,
});

const items = ref<DashboardBookingListItem[]>([]);
const pagination = ref<DashboardBookingPagination>(emptyPagination);
const options = ref<DashboardBookingFilterOptions>(emptyOptions);

const loading = ref(false);
const error = ref(false);

let searchTimer: ReturnType<typeof setTimeout> | undefined;
let controller: AbortController | undefined;

const hasActiveFilters = computed(() => {
  return [
    filters.search,
    filters.status,
    filters.sector,
    filters.program,
    filters.module,
    filters.dateFrom,
    filters.dateTo,
  ].some(Boolean);
});

const statusMessage = computed(() => {
  if (error.value) {
    return "Aanvragen konden niet worden geladen";
  }

  if (loading.value) {
    return "Resultaten bijwerken…";
  }

  const totalItems = pagination.value.totalItems;

  if (totalItems === 0) {
    return "Geen aanvragen gevonden";
  }

  return totalItems === 1
    ? "1 aanvraag gevonden"
    : `${totalItems} aanvragen gevonden`;
});

const availableDateRangeLabel = computed(() => {
  const { min, max } = options.value.dateRange;

  if (!min || !max) {
    return "";
  }

  return `Beschikbare aanvraagdata: ${formatDutchDate(min)} t/m ${formatDutchDate(max)}`;
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

  filters.page = Number.isInteger(page) && page > 0
    ? page
    : 1;
}

async function load(): Promise<void> {
  controller?.abort();

  const requestController = new AbortController();
  controller = requestController;

  loading.value = true;
  error.value = false;

  try {
    const result = await fetchDashboardBookings(
      { ...filters },
      requestController.signal,
    );

    items.value = result.items;
    pagination.value = result.pagination;
    options.value = result.filters;

    if (result.pagination.currentPage !== filters.page) {
      await updateRoute(
        {
          page: result.pagination.currentPage,
        },
        true,
      );
    }
  } catch (requestError) {
    if (
      requestError instanceof DOMException
      && requestError.name === "AbortError"
    ) {
      return;
    }

    error.value = true;
  } finally {
    if (!requestController.signal.aborted) {
      loading.value = false;
    }
  }
}

function routeQuery(
  overrides: Partial<DashboardBookingFilters> = {},
): LocationQueryRaw {
  const next = {
    ...filters,
    ...overrides,
  };

  const query: LocationQueryRaw = {};

  for (
    const key of [
      "search",
      "status",
      "sector",
      "program",
      "module",
      "dateFrom",
      "dateTo",
    ] as const
  ) {
    if (next[key] !== "") {
      query[key] = next[key];
    }
  }

  if (next.page > 1) {
    query.page = String(next.page);
  }

  return query;
}

async function updateRoute(
  overrides: Partial<DashboardBookingFilters>,
  replace = false,
): Promise<void> {
  const location = {
    name: "bookings",
    query: routeQuery(overrides),
  };

  await (
    replace
      ? router.replace(location)
      : router.push(location)
  );
}

function applyFilter(): void {
  void updateRoute({
    page: 1,
  });
}

function scheduleSearch(): void {
  clearTimeout(searchTimer);

  searchTimer = setTimeout(() => {
    applyFilter();
  }, 350);
}

function clearFilters(): void {
  clearTimeout(searchTimer);

  void router.push({
    name: "bookings",
  });
}

function changePage(page: number): void {
  if (
    page < 1
    || page > pagination.value.totalPages
    || page === pagination.value.currentPage
  ) {
    return;
  }

  void updateRoute({
    page,
  }).then(() => {
    document
      .querySelector(".admin-bookings__results")
      ?.scrollIntoView({
        behavior: "smooth",
        block: "start",
      });
  });
}

function formatDate(value: string): string {
  const [year, month, day] = value.split("-");

  return year && month && day
    ? `${day}-${month}-${year}`
    : value;
}

function formatDutchDate(value: string): string {
  const [year, month, day] = value.split("-").map(Number);

  if (!year || !month || !day) {
    return value;
  }

  return new Intl.DateTimeFormat("nl-NL", {
    day: "numeric",
    month: "long",
    year: "numeric",
    timeZone: "UTC",
  }).format(new Date(Date.UTC(year, month - 1, day)));
}

function statusClass(status: string): string {
  if (status === "Definitief") {
    return "admin-status--confirmed";
  }

  if (status === "Afgewezen") {
    return "admin-status--rejected";
  }

  return "admin-status--option";
}

watch(
  () => route.fullPath,
  () => {
    readRoute();
    void load();
  },
  {
    immediate: true,
  },
);

onBeforeUnmount(() => {
  clearTimeout(searchTimer);
  controller?.abort();
});
</script>

<template>
  <section
    class="admin-bookings"
    aria-labelledby="bookings-title"
  >
    <header class="admin-bookings__intro">
      <p class="admin-eyebrow">
        Onderwijsformulier 2.0
      </p>

      <h1 id="bookings-title">
        Aanvragen
      </h1>

      <p>
        Zoek en filter alle ontvangen onderwijsaanvragen.
        Dit overzicht is alleen-lezen.
      </p>

    </header>

    <form
      class="admin-booking-filters"
      aria-label="Aanvragen filteren"
      @submit.prevent="applyFilter"
    >
      <div class="admin-field admin-booking-filters__search">
        <label
          class="admin-field__label"
          for="booking-search"
        >
          Zoeken
        </label>

        <input
          id="booking-search"
          v-model="filters.search"
          class="admin-control"
          type="search"
          name="search"
          maxlength="150"
          autocomplete="off"
          placeholder="School, plaats, contactpersoon of e-mail"
          @input="scheduleSearch"
        >
      </div>

      <AdminSelect
        v-model="filters.status"
        name="booking-status"
        label="Status"
        placeholder="Alle statussen"
        :options="options.statuses"
        @update:model-value="applyFilter"
      />

      <AdminSelect
        v-model="filters.sector"
        name="booking-sector"
        label="Onderwijssector"
        placeholder="Alle sectoren"
        :options="options.sectors"
        @update:model-value="applyFilter"
      />

      <AdminSelect
        v-model="filters.program"
        name="booking-program"
        label="Programma"
        placeholder="Alle programma’s"
        :options="options.programs"
        @update:model-value="applyFilter"
      />

      <AdminSelect
        v-model="filters.module"
        name="booking-module"
        label="Keuzemodule"
        placeholder="Alle keuzemodules"
        :options="options.modules"
        @update:model-value="applyFilter"
      />

      <AdminDateField
        v-model="filters.dateFrom"
        name="booking-date-from"
        label="Bezoekdatum vanaf"
        :min="options.dateRange.min || undefined"
        :max="filters.dateTo || options.dateRange.max || undefined"
        clearable
        @update:model-value="applyFilter"
      />

      <AdminDateField
        v-model="filters.dateTo"
        name="booking-date-to"
        label="Bezoekdatum tot"
        :min="filters.dateFrom || options.dateRange.min || undefined"
        :max="options.dateRange.max || undefined"
        clearable
        @update:model-value="applyFilter"
      />

      <footer class="admin-booking-filters__footer">
        <div class="admin-booking-filters__summary">
          <p
            class="admin-booking-filters__result"
            aria-live="polite"
          >
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

    <div
      class="admin-bookings__results"
      :aria-busy="loading"
    >
      <div
        v-if="error"
        class="admin-bookings__state"
        role="alert"
      >
        <p>
          De aanvragen konden niet worden geladen.
          Probeer het opnieuw.
        </p>

        <AdminButton
          :loading="loading"
          @click="load"
        >
          Opnieuw proberen
        </AdminButton>
      </div>

      <div
        v-else-if="loading && items.length === 0"
        class="admin-bookings__state"
        role="status"
      >
        <p>Aanvragen laden…</p>
      </div>

      <div
        v-else-if="items.length === 0"
        class="admin-bookings__state"
      >
        <p>
          Geen aanvragen gevonden met deze filters.
        </p>

        <AdminButton
          v-if="hasActiveFilters"
          variant="secondary"
          @click="clearFilters"
        >
          Filters wissen
        </AdminButton>
      </div>

      <template v-else>
        <div
          v-if="loading"
          class="admin-bookings__updating"
          role="status"
        >
          Resultaten bijwerken…
        </div>

        <div class="admin-bookings-table-wrap">
          <table class="admin-bookings-table">
            <thead>
              <tr>
                <th scope="col">Status</th>
                <th scope="col">Bezoekdatum</th>
                <th scope="col">Aanvraag</th>
                <th scope="col">School</th>
                <th scope="col">Plaats</th>
                <th scope="col">Sector</th>
                <th scope="col">Programma</th>
                <th scope="col">Keuzemodule</th>
                <th scope="col">Leerlingen</th>
                <th scope="col">Contactpersoon</th>
                <th scope="col">Actie</th>
              </tr>
            </thead>

            <tbody>
              <tr
                v-for="item in items"
                :key="item.id"
                :data-booking-id="item.id"
              >
                <td>
                  <span
                    class="admin-status"
                    :class="statusClass(item.status)"
                  >
                    {{ item.status }}
                  </span>
                </td>

                <td>
                  {{ formatDate(item.visitDate) }}
                </td>

                <th
                  class="admin-bookings-table__request-cell"
                  scope="row"
                >
                  #{{ item.id }}
                </th>

                <td>
                  {{ item.schoolName }}
                </td>

                <td>
                  {{ item.city }}
                </td>

                <td>
                  {{ item.sectorLabel }}
                </td>

                <td>
                  {{ item.programLabel }}
                </td>

                <td>
                  {{ item.moduleLabel }}
                </td>

                <td>
                  {{ item.studentCount ?? "Onbekend" }}
                </td>

                <td>
                  {{ item.contactPersonName }}
                </td>

                <td>
                  <RouterLink
                    class="admin-bookings__view-link"
                    :to="{ name: 'booking-detail', params: { id: item.id }, query: route.query }"
                  >
                    Bekijken
                  </RouterLink>
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <div class="admin-booking-cards">
          <article
            v-for="item in items"
            :key="item.id"
            class="admin-booking-card"
            :data-booking-id="item.id"
          >
            <div class="admin-booking-card__header">
              <span
                class="admin-status"
                :class="statusClass(item.status)"
              >
                {{ item.status }}
              </span>

              <strong>
                #{{ item.id }}
              </strong>
            </div>

            <h2>
              {{ item.schoolName }}
            </h2>

            <p>
              {{ formatDate(item.visitDate) }}
              ·
              {{ item.city }}
            </p>

            <dl>
              <dt>Sector</dt>
              <dd>{{ item.sectorLabel }}</dd>

              <dt>Programma</dt>
              <dd>{{ item.programLabel }}</dd>

              <dt>Keuzemodule</dt>
              <dd>{{ item.moduleLabel }}</dd>

              <dt>Leerlingen</dt>
              <dd>{{ item.studentCount ?? "Onbekend" }}</dd>

              <dt>Contactpersoon</dt>
              <dd>{{ item.contactPersonName }}</dd>
            </dl>

            <RouterLink
              class="admin-button admin-button--secondary admin-booking-card__view"
              :to="{ name: 'booking-detail', params: { id: item.id }, query: route.query }"
            >
              Bekijken
            </RouterLink>
          </article>
        </div>

        <nav
          v-if="pagination.totalPages > 0"
          class="admin-pagination"
          aria-label="Paginering aanvragen"
        >
          <AdminButton
            variant="secondary"
            :disabled="
              pagination.currentPage <= 1
              || loading
            "
            @click="changePage(
              pagination.currentPage - 1
            )"
          >
            Vorige
          </AdminButton>

          <p>
            <strong>
              {{ pagination.from }}–{{ pagination.to }}
            </strong>

            van {{ pagination.totalItems }}

            · pagina {{ pagination.currentPage }}
            van {{ pagination.totalPages }}
          </p>

          <AdminButton
            variant="secondary"
            :disabled="
              pagination.currentPage
                >= pagination.totalPages
              || loading
            "
            @click="changePage(
              pagination.currentPage + 1
            )"
          >
            Volgende
          </AdminButton>
        </nav>
      </template>
    </div>
  </section>
</template>

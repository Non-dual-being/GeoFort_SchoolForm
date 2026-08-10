<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref, toRef, useId, watch } from "vue";
import { ArrowDown, ArrowUp, ArrowUpDown, RotateCcw } from "lucide-vue-next";
import AdminSelect from "../form/AdminSelect.vue";
import type { AnalyticsRow } from "../../types/bookingAnalytics";
import { useAnalyticsTable, type AnalyticsSortType, type AnalyticsTableFilter } from "../../composables/useAnalyticsTable";

interface Column {
  key: string;
  label: string;
  displayKey?: string;
  format?: "number" | "percentage" | "date";
  nullLabel?: string;
  sortable?: boolean;
  sortType?: AnalyticsSortType;
}
const props = withDefaults(defineProps<{
  caption: string;
  columns: Column[];
  rows: AnalyticsRow[];
  filters?: AnalyticsTableFilter[];
  selectedKey?: string | number | null;
  emphasizedKey?: string | null;
  monthlyWindow?: boolean;
  initialSortKey?: string | null;
}>(), { filters: () => [], selectedKey: null, emphasizedKey: null, monthlyWindow: false, initialSortKey: null });

const numbers = new Intl.NumberFormat("nl-NL", { maximumFractionDigits: 1 });
const dates = new Intl.DateTimeFormat("nl-NL", { day: "numeric", month: "short", year: "numeric", timeZone: "UTC" });
const types = computed<Record<string, AnalyticsSortType>>(() => Object.fromEntries(props.columns.map((column) => [
  column.key, column.sortType ?? (column.format === "number" || column.format === "percentage" ? "number" : column.format === "date" ? "date" : "text"),
])));
const table = useAnalyticsTable(toRef(props, "rows"), props.filters, types.value);
if (props.initialSortKey) table.sort.value = { key: props.initialSortKey, direction: "asc" };
const activeFilters = computed(() => props.filters.filter((filter) => (table.filterValues.value[filter.key] ?? "") !== ""));
const scrollRegion = ref<HTMLElement | null>(null);
const horizontallyScrollable = ref(false);
const verticallyScrollable = ref(false);
const scrollHintId = `analytics-table-scroll-${useId()}`;
let resizeObserver: ResizeObserver | null = null;
const scrollLabel = computed(() => {
  if (horizontallyScrollable.value && verticallyScrollable.value) return `${props.caption}: horizontaal en verticaal scrollbare tabel`;
  if (horizontallyScrollable.value) return `${props.caption}: horizontaal scrollbare tabel`;
  if (verticallyScrollable.value) return `${props.caption}: verticaal scrollbare tabel`;
  return undefined;
});

function format(value: string | number | null | undefined, type?: Column["format"], nullLabel = "Geen waarde"): string {
  if (value === null) return nullLabel;
  if ((type === "number" || type === "percentage") && typeof value === "string" && !/^[-+]?\d+(?:[.,]\d+)?$/.test(value)) return value;
  if (type === "number") return numbers.format(Number(value) || 0);
  if (type === "percentage") return `${numbers.format(Number(value) || 0)}%`;
  if (type === "date" && typeof value === "string") {
    const [year, month, day] = value.split("-").map(Number);
    return year && month && day ? dates.format(new Date(Date.UTC(year, month - 1, day))) : value;
  }
  return value === undefined ? "0" : String(value);
}
function ariaSort(key: string): "ascending" | "descending" | "none" {
  return table.sort.value?.key === key ? (table.sort.value.direction === "asc" ? "ascending" : "descending") : "none";
}
function sortLabel(column: Column): string {
  const state = ariaSort(column.key);
  return state === "ascending" ? `${column.label}, oplopend gesorteerd; activeer voor aflopend`
    : state === "descending" ? `${column.label}, aflopend gesorteerd; activeer voor standaardvolgorde`
      : `${column.label} oplopend sorteren`;
}
function setFilter(key: string, value: string): void {
  table.filterValues.value[key] = value;
}
function selectName(key: string): string {
  return `analytics-table-${captionSlug.value}-${key.replace(/[^a-z0-9-]/gi, "-")}`;
}
const captionSlug = computed(() => props.caption.toLocaleLowerCase("nl-NL").normalize("NFD").replace(/[\u0300-\u036f]/g, "").replace(/[^a-z0-9]+/g, "-").replace(/^-|-$/g, ""));
function updateScrollability(): void {
  const region = scrollRegion.value;
  horizontallyScrollable.value = Boolean(region && region.scrollWidth > region.clientWidth + 1);
  verticallyScrollable.value = Boolean(region && region.scrollHeight > region.clientHeight + 1);
}
onMounted(() => {
  void nextTick(updateScrollability);
  if (typeof ResizeObserver !== "undefined") {
    resizeObserver = new ResizeObserver(updateScrollability);
    if (scrollRegion.value) {
      resizeObserver.observe(scrollRegion.value);
      const tableElement = scrollRegion.value.querySelector("table");
      if (tableElement) resizeObserver.observe(tableElement);
    }
  }
});
watch([() => props.rows, () => props.columns], () => void nextTick(updateScrollability), { deep: true });
onBeforeUnmount(() => resizeObserver?.disconnect());
</script>

<template>
  <div class="admin-analytics-table-block">
    <div v-if="filters.length" class="admin-analytics-table-toolbar">
      <template v-for="filter in filters" :key="filter.key">
        <AdminSelect v-if="filter.type === 'select'" :model-value="table.filterValues.value[filter.key] ?? ''" :name="selectName(filter.key)" :label="filter.label" :options="filter.options ?? []" placeholder="Alle" @update:model-value="setFilter(filter.key, $event)" />
        <label v-else class="admin-field"><span class="admin-field__label">{{ filter.label }}</span><input v-model="table.filterValues.value[filter.key]" class="admin-control admin-text-input" :type="filter.type === 'minimum' ? 'number' : 'search'" :min="filter.type === 'minimum' ? 0 : undefined"></label>
      </template>
      <button v-if="activeFilters.length || table.sort.value" type="button" class="admin-analytics-table-reset" @click="table.reset"><RotateCcw :size="15" aria-hidden="true" /> Tabel herstellen</button>
    </div>
    <div v-if="filters.length" class="admin-analytics-table-meta">
      <span>{{ table.visibleRows.value.length }} van {{ rows.length }} rijen zichtbaar</span>
      <span v-for="filter in activeFilters" :key="filter.key" class="admin-analytics-filter-chip">{{ filter.label }}: {{ table.filterValues.value[filter.key] }}</span>
    </div>
    <div ref="scrollRegion" class="admin-analytics-table-wrap" :class="{ 'admin-analytics-table-wrap--monthly': monthlyWindow }" :tabindex="scrollLabel ? 0 : -1" :aria-label="scrollLabel" :aria-describedby="horizontallyScrollable ? scrollHintId : undefined">
      <table class="admin-analytics-table">
        <caption class="sr-only">{{ caption }}</caption>
        <thead>
          <tr>
            <th v-for="column in columns" :key="column.key" scope="col" :class="{ 'is-emphasized': emphasizedKey === column.key }" :aria-sort="column.sortable === false ? undefined : ariaSort(column.key)">
              <button v-if="column.sortable !== false" type="button" class="admin-analytics-sort" :aria-label="sortLabel(column)" @click="table.toggleSort(column.key)">
                {{ column.label }}
                <ArrowUp v-if="table.sort.value?.key === column.key && table.sort.value.direction === 'asc'" :size="14" aria-hidden="true" />
                <ArrowDown v-else-if="table.sort.value?.key === column.key" :size="14" aria-hidden="true" />
                <ArrowUpDown v-else :size="14" aria-hidden="true" />
              </button>
              <template v-else>{{ column.label }}</template>
            </th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="(row, index) in table.visibleRows.value" :key="String(row[columns[0]?.key ?? ''] ?? index)" :class="{ 'is-selected': selectedKey !== null && row[columns[0]?.key ?? ''] === selectedKey }">
            <th scope="row">{{ format(row[columns[0]?.displayKey ?? columns[0]?.key ?? ''], columns[0]?.format, columns[0]?.nullLabel) }}</th>
            <td v-for="column in columns.slice(1)" :key="column.key" :class="{ 'is-emphasized': emphasizedKey === column.key }">{{ format(row[column.displayKey ?? column.key], column.format, column.nullLabel) }}</td>
          </tr>
          <tr v-if="table.visibleRows.value.length === 0"><td :colspan="columns.length" class="admin-analytics-table-empty">Geen rijen voldoen aan de lokale filters.</td></tr>
        </tbody>
      </table>
    </div>
    <p v-if="horizontallyScrollable" :id="scrollHintId" class="admin-analytics-table-scroll-hint">Scroll horizontaal om alle kolommen te bekijken.</p>
  </div>
</template>

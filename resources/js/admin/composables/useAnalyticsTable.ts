import { computed, type Ref, ref } from "vue";
import type { AnalyticsRow } from "../types/bookingAnalytics";
import { filterAnalyticsRows, stableSortAnalyticsRows, type AnalyticsSortState, type AnalyticsSortType, type AnalyticsTableFilter } from "../utils/analyticsTableSorting";
export { compareAnalyticsValues, filterAnalyticsRows, stableSortAnalyticsRows } from "../utils/analyticsTableSorting";
export type { AnalyticsSortState, AnalyticsSortType, AnalyticsTableFilter } from "../utils/analyticsTableSorting";


export function useAnalyticsTable(rows: Ref<AnalyticsRow[]>, filters: AnalyticsTableFilter[], types: Record<string, AnalyticsSortType>) {
  const sort = ref<AnalyticsSortState | null>(null);
  const filterValues = ref<Record<string, string>>({});
  const visibleRows = computed(() => stableSortAnalyticsRows(filterAnalyticsRows(rows.value, filters, filterValues.value), sort.value, types));
  function toggleSort(key: string): void {
    sort.value = sort.value?.key !== key ? { key, direction: "asc" }
      : sort.value.direction === "asc" ? { key, direction: "desc" } : null;
  }
  function reset(): void { sort.value = null; filterValues.value = {}; }
  return { sort, filterValues, visibleRows, toggleSort, reset };
}

import { computed, type Ref, ref } from "vue";
import type { AnalyticsRow } from "../types/bookingAnalytics";

export type AnalyticsSortType = "text" | "number" | "date" | "month" | "weekday" | "band";
export interface AnalyticsSortState { key: string; direction: "asc" | "desc" }
export interface AnalyticsTableFilter {
  key: string;
  label: string;
  type: "select" | "minimum" | "search";
  options?: ReadonlyArray<{ value: string; label: string }>;
}
const months = ["januari","februari","maart","april","mei","juni","juli","augustus","september","oktober","november","december"];
const weekdays = ["maandag","dinsdag","woensdag","donderdag","vrijdag","zaterdag","zondag"];

export function compareAnalyticsValues(left: string | number | undefined, right: string | number | undefined, type: AnalyticsSortType): number {
  if (type === "number") return (Number(left) || 0) - (Number(right) || 0);
  if (type === "date") return String(left ?? "").localeCompare(String(right ?? ""));
  if (type === "month") return semanticIndex(String(left ?? ""), months) - semanticIndex(String(right ?? ""), months);
  if (type === "weekday") return semanticIndex(String(left ?? ""), weekdays) - semanticIndex(String(right ?? ""), weekdays);
  if (type === "band") {
    const leftValue = Number(String(left ?? "").match(/\d+/)?.[0]);
    const rightValue = Number(String(right ?? "").match(/\d+/)?.[0]);
    return (Number.isFinite(leftValue) ? leftValue : Number.MAX_SAFE_INTEGER)
      - (Number.isFinite(rightValue) ? rightValue : Number.MAX_SAFE_INTEGER);
  }
  return new Intl.Collator("nl-NL", { numeric: true, sensitivity: "base" }).compare(String(left ?? ""), String(right ?? ""));
}

export function filterAnalyticsRows(rows: AnalyticsRow[], filters: AnalyticsTableFilter[], values: Record<string, string>): AnalyticsRow[] {
  return rows.filter((row) => filters.every((filter) => {
    const selected = values[filter.key] ?? "";
    if (selected === "") return true;
    const value = row[filter.key];
    if (filter.type === "minimum") return Number(value) >= Number(selected);
    if (filter.type === "search") return String(value ?? "").toLocaleLowerCase("nl-NL").includes(selected.toLocaleLowerCase("nl-NL"));
    return String(value ?? "") === selected;
  }));
}

export function stableSortAnalyticsRows(rows: AnalyticsRow[], state: AnalyticsSortState | null, types: Record<string, AnalyticsSortType>): AnalyticsRow[] {
  if (!state) return [...rows];
  return rows.map((row, index) => ({ row, index })).sort((a, b) => {
    const comparison = compareAnalyticsValues(a.row[state.key], b.row[state.key], types[state.key] ?? "text");
    return (comparison === 0 ? a.index - b.index : comparison) * (state.direction === "asc" ? 1 : -1);
  }).map(({ row }) => row);
}

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

function semanticIndex(value: string, labels: string[]): number {
  const normalized = value.toLocaleLowerCase("nl-NL");
  const index = labels.findIndex((label) => normalized.startsWith(label));
  const year = Number(normalized.match(/\d{4}/)?.[0]) || 0;
  return index < 0 ? Number.MAX_SAFE_INTEGER : year * 12 + index;
}

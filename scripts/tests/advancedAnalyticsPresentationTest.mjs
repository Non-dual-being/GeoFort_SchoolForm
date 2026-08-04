import { trimEmptyNewSchoolEdges } from "../../resources/js/admin/utils/advancedAnalyticsPresentation.ts";
import { stableSortAnalyticsRows } from "../../resources/js/admin/utils/analyticsTableSorting.ts";

const assert = (condition, message) => { if (!condition) throw new Error(message); };
const rows = [
  { month: "2025-08", label: "augustus 2025", count: 0 },
  { month: "2025-09", label: "september 2025", count: 4 },
  { month: "2025-10", label: "oktober 2025", count: 0 },
  { month: "2025-11", label: "november 2025", count: 2 },
  { month: "2025-12", label: "december 2025", count: 0 },
];
const trimmed = trimEmptyNewSchoolEdges(rows);
assert(trimmed.map(row => row.month).join(",") === "2025-09,2025-10,2025-11", "Randnullen zijn niet correct begrensd.");
assert(trimEmptyNewSchoolEdges(rows.map(row => ({ ...row, count: 0 }))).length === 0, "Volledig lege reeks blijft zichtbaar.");

const sortable = [
  { month: "2026-01", label: "januari 2026", count: 10, percentage: null },
  { month: "2025-12", label: "december 2025", count: 2, percentage: 9.5 },
  { month: "2026-02", label: "februari 2026", count: 3, percentage: 100 },
];
assert(stableSortAnalyticsRows(sortable, { key: "month", direction: "asc" }, { month: "date" })[0].month === "2025-12", "Maand gebruikt niet YYYY-MM.");
assert(stableSortAnalyticsRows(sortable, { key: "count", direction: "asc" }, { count: "number" })[0].count === 2, "Aantallen sorteren niet numeriek.");
assert(stableSortAnalyticsRows(sortable, { key: "percentage", direction: "desc" }, { percentage: "number" }).map(row => row.percentage).join(",") === "100,9.5,", "Percentages of null-plaatsing zijn onjuist.");
assert(sortable[0].month === "2026-01", "Tabelsortering muteert de grafiekbron.");
console.log("Advanced analytics presentation tests passed.");

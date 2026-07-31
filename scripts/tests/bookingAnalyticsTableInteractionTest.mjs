import { createServer } from "vite";

const server = await createServer({
  configFile: false,
  server: { middlewareMode: true },
  appType: "custom",
});
const assert = (condition, message) => {
  if (!condition) throw new Error(message);
};

try {
  const {
    compareAnalyticsValues,
    filterAnalyticsRows,
    stableSortAnalyticsRows,
  } = await server.ssrLoadModule("/resources/js/admin/composables/useAnalyticsTable.ts");

  const source = [
    { label: "École", count: 10, percentage: 25, month: "februari 2026", weekday: "Dinsdag", date: "2026-02-02", band: "21–40" },
    { label: "Amsterdam", count: 2, percentage: 5, month: "januari 2026", weekday: "Maandag", date: "2026-01-01", band: "1–20" },
    { label: "Amsterdam", count: 2, percentage: 5, month: "maart 2025", weekday: "Vrijdag", date: "2025-03-03", band: "41–80" },
  ];
  const snapshot = JSON.stringify(source);
  assert(compareAnalyticsValues("Amsterdam", "École", "text") < 0, "Nederlandse tekstsortering faalt.");
  assert(compareAnalyticsValues(2, 10, "number") < 0, "Numerieke sortering faalt.");
  assert(compareAnalyticsValues(5, 25, "number") < 0, "Percentagesortering faalt.");
  assert(compareAnalyticsValues("januari 2026", "februari 2026", "month") < 0, "Maandvolgorde faalt.");
  assert(compareAnalyticsValues("Maandag", "Vrijdag", "weekday") < 0, "Weekdagvolgorde faalt.");
  assert(compareAnalyticsValues("2025-03-03", "2026-01-01", "date") < 0, "Datumsortering faalt.");
  assert(compareAnalyticsValues("1–20", "21–40", "band") < 0, "Bandvolgorde faalt.");

  const ascending = stableSortAnalyticsRows(source, { key: "count", direction: "asc" }, { count: "number" });
  const descending = stableSortAnalyticsRows(source, { key: "count", direction: "desc" }, { count: "number" });
  const reset = stableSortAnalyticsRows(source, null, {});
  assert(ascending[0].count === 2 && ascending[2].count === 10, "Oplopend sorteren faalt.");
  assert(descending[0].count === 10, "Aflopend sorteren faalt.");
  assert(reset[0].label === source[0].label, "Resetsortering bewaart bronvolgorde niet.");
  assert(ascending[0].date === "2026-01-01" && ascending[1].date === "2025-03-03", "Stabiele sortering faalt.");

  const filters = [
    { key: "label", label: "Naam", type: "search" },
    { key: "count", label: "Minimum", type: "minimum" },
    { key: "weekday", label: "Weekdag", type: "select" },
  ];
  assert(filterAnalyticsRows(source, filters, { label: "éco" }).length === 1, "Locale zoekfilter faalt.");
  assert(filterAnalyticsRows(source, filters, { count: "3" }).length === 1, "Minimumfilter faalt.");
  assert(filterAnalyticsRows(source, filters, { weekday: "Maandag" }).length === 1, "Selectfilter faalt.");
  assert(filterAnalyticsRows(source, filters, { weekday: "Woensdag" }).length === 0, "Lege filteruitkomst faalt.");
  assert(JSON.stringify(source) === snapshot, "Sorteren/filteren muteert de originele response.");

  console.log("Booking analytics table interaction tests passed.");
} finally {
  await server.close();
}

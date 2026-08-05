import assert from "node:assert/strict";
import * as module from "../../resources/js/admin/utils/revenueDateRange.ts";
const range = { min: "2026-09-01", max: "2027-07-08" };

assert.deepEqual(module.normalizeRevenueDatePeriod("2024-01-01", "2027-08-01", range), { startDate: range.min, endDate: range.max });
assert.deepEqual(module.normalizeRevenueDatePeriod("2024-01-01", "2025-01-01", range), { startDate: range.min, endDate: range.max });
assert.deepEqual(module.normalizeRevenueDatePeriod("2028-01-01", "2028-02-01", range), { startDate: range.min, endDate: range.max });
assert.deepEqual(module.normalizeRevenueDatePeriod("2027-03-01", "2026-10-01", range), { startDate: range.min, endDate: range.max });
assert.deepEqual(module.normalizeRevenueDatePeriod(range.min, range.max, range), { startDate: range.min, endDate: range.max });
assert.equal(module.normalizeRevenueDatePeriod("", "", { min: null, max: null }), null);
assert.equal(module.isIsoCalendarDate("2026-02-29"), false);
assert.equal(module.isIsoCalendarDate("2027-07-08"), true);

console.log("Booking revenue date range interaction tests passed.");

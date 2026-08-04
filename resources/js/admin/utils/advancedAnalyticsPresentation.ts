import type { BookingAnalyticsResponse } from "../types/bookingAnalytics";

export type NewSchoolsMonth = BookingAnalyticsResponse["newSchoolsByMonth"][number];

export function trimEmptyNewSchoolEdges(rows: NewSchoolsMonth[]): NewSchoolsMonth[] {
  const first = rows.findIndex((row) => row.count > 0);
  if (first < 0) return [];
  let last = rows.length - 1;
  while (last > first && (rows[last]?.count ?? 0) === 0) last -= 1;
  return rows.slice(first, last + 1);
}

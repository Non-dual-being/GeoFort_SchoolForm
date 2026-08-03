import { getApiData } from "../../services/http/apiClient";
import type { DashboardCalendarOverview } from "../types/dashboardCalendarOverview";

const cache = new Map<string, DashboardCalendarOverview>();

export function calendarOverviewCacheKey(year: number, month: number): string {
  return `${year}-${String(month).padStart(2, "0")}`;
}

export function getCachedDashboardCalendarOverview(year: number, month: number): DashboardCalendarOverview | undefined {
  return cache.get(calendarOverviewCacheKey(year, month));
}

export async function fetchDashboardCalendarOverview(year: number, month: number, signal?: AbortSignal): Promise<DashboardCalendarOverview> {
  const cached = getCachedDashboardCalendarOverview(year, month);
  if (cached) return cached;
  const query = new URLSearchParams({ year: String(year), month: String(month) });
  const result = await getApiData<{ calendar: DashboardCalendarOverview }>(`/api/admin/calendar/overview.php?${query}`, { method: "GET", signal });
  cache.set(calendarOverviewCacheKey(year, month), result.calendar);
  return result.calendar;
}

export function invalidateDashboardCalendarOverviewCache(startDate?: string, endDate?: string): void {
  if (!startDate || !endDate) {
    cache.clear();
    return;
  }
  const cursor = new Date(`${startDate.slice(0, 7)}-01T00:00:00Z`);
  const last = endDate.slice(0, 7);
  while (`${cursor.getUTCFullYear()}-${String(cursor.getUTCMonth() + 1).padStart(2, "0")}` <= last) {
    cache.delete(calendarOverviewCacheKey(cursor.getUTCFullYear(), cursor.getUTCMonth() + 1));
    cursor.setUTCMonth(cursor.getUTCMonth() + 1);
  }
}

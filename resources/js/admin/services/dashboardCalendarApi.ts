import { getApiData } from "../../services/http/apiClient";
import type { DashboardCalendar } from "../types/dashboardCalendar";

export function fetchDashboardCalendar(
  startDate: string,
  endDate: string,
  signal?: AbortSignal,
): Promise<{ calendar: DashboardCalendar }> {
  const query = new URLSearchParams({ startDate, endDate });
  return getApiData(`/api/admin/calendar/month.php?${query}`, {
    method: "GET",
    signal,
  });
}

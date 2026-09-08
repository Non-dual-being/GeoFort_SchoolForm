import { getApiData } from "../../services/http/apiClient";
import type { DashboardBookingFilters, DashboardBookingPageResponse } from "../types/bookings";

export function fetchDashboardBookings(
  filters: DashboardBookingFilters,
  signal?: AbortSignal,
): Promise<DashboardBookingPageResponse> {
  const params = new URLSearchParams();
  const values: Record<string, string> = {
    search: filters.search.trim(),
    status: filters.status,
    sector: filters.sector,
    program: filters.program,
    module: filters.module,
    dateFrom: filters.dateFrom,
    dateTo: filters.dateTo,
    sort: filters.sort,
  };

  for (const [key, value] of Object.entries(values)) {
    if (value !== "") params.set(key, value);
  }
  if (filters.page > 1) params.set("page", String(filters.page));

  const query = params.toString();
  return getApiData<DashboardBookingPageResponse>(
    `/api/admin/requests/index.php${query === "" ? "" : `?${query}`}`,
    { method: "GET", signal },
  );
}

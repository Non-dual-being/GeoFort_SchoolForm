import { getApiData } from "../../services/http/apiClient";
import type { BookingAnalyticsResponse } from "../types/bookingAnalytics";
import type { PopulationFilter, ProgramFilter, SectorFilter } from "../types/bookingAnalytics";

export function fetchBookingAnalytics(
  startDate?: string,
  endDate?: string,
  signal?: AbortSignal,
  sector: SectorFilter = "all",
  population: PopulationFilter = "planning",
  program: ProgramFilter = "all",
): Promise<BookingAnalyticsResponse> {
  const query = startDate && endDate
    ? `?${new URLSearchParams({ startDate, endDate, sector, population, program })}`
    : "";
  return getApiData(`/api/admin/requests/analytics.php${query}`, {
    method: "GET",
    signal,
  });
}

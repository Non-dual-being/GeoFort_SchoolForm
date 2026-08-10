import { getApiData } from "../../services/http/apiClient";
import type { BookingAnalyticsResponse } from "../types/bookingAnalytics";
import type { PopulationFilter, ProgramFilter, SectorFilter } from "../types/bookingAnalytics";
import type { CapacityTarget } from "../types/bookingAnalytics";

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

export interface CapacityTargetUpdateInput { effectiveDate: string; studentsPerAvailableDay: number; bookingsPerAvailableDay: number; expectedUpdatedAt: string | null }
export interface CapacityTargetIssue { field: string; description: string }
export class CapacityTargetApiError extends Error {
  constructor(public readonly code: string, public readonly issues: CapacityTargetIssue[] = []) { super(code); }
}
export async function updateCapacityTarget(input: CapacityTargetUpdateInput, csrfToken: string): Promise<CapacityTarget> {
  const response = await fetch("/api/admin/requests/update-capacity-target.php", { method: "POST", headers: { Accept: "application/json", "Content-Type": "application/json", "X-CSRF-Token": csrfToken }, body: JSON.stringify(input) });
  const payload = await response.json() as { ok?: boolean; code?: string; data?: { target?: CapacityTarget } | null; issues?: CapacityTargetIssue[] };
  if (!response.ok || !payload.ok || !payload.data?.target) throw new CapacityTargetApiError(payload.code ?? "REQUEST_FAILED", payload.issues ?? []);
  return payload.data.target;
}

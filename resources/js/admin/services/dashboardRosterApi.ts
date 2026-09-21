import { getApiData } from "../../services/http/apiClient";
import type {
  DashboardRosterCreateResponse,
  DashboardRosterDetailResponse,
  DashboardRosterListResponse,
} from "../types/roster";

export function fetchDashboardRosters(
  signal?: AbortSignal,
): Promise<DashboardRosterListResponse> {
  return getApiData<DashboardRosterListResponse>(
    "/api/admin/rosters/index.php",
    { method: "GET", signal },
  );
}

export function fetchDashboardRoster(
  id: number,
  signal?: AbortSignal,
): Promise<DashboardRosterDetailResponse> {
  const params = new URLSearchParams({ id: String(id) });
  return getApiData<DashboardRosterDetailResponse>(
    `/api/admin/rosters/show.php?${params.toString()}`,
    { method: "GET", signal },
  );
}

export function createDashboardRoster(
  bookingId: number,
  token: string,
): Promise<DashboardRosterCreateResponse> {
  return getApiData<DashboardRosterCreateResponse>(
    "/api/admin/rosters/create.php",
    {
      method: "POST",
      headers: {
        "Content-Type": "application/json",
        "X-CSRF-Token": token,
      },
      body: JSON.stringify({ bookingId }),
    },
  );
}
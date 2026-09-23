import { getApiData } from "../../services/http/apiClient";
import type {
  DashboardRosterCreateResponse,
  DashboardRosterDetailResponse,
  DashboardRosterListResponse,
  RosterSessionMutationResponse,
  RosterSessionSaveRequest,
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

export function saveRosterSession(
  request: RosterSessionSaveRequest,
  token: string,
): Promise<RosterSessionMutationResponse> {
  return getApiData<RosterSessionMutationResponse>(
    "/api/admin/rosters/session-save.php",
    {
      method: "POST",
      headers: {
        "Content-Type": "application/json",
        "X-CSRF-Token": token,
      },
      body: JSON.stringify(request),
    },
  );
}

export function deleteRosterSession(
  planId: number,
  sessionId: number,
  expectedRevision: number,
  token: string,
): Promise<RosterSessionMutationResponse> {
  return getApiData<RosterSessionMutationResponse>(
    "/api/admin/rosters/session-delete.php",
    {
      method: "POST",
      headers: {
        "Content-Type": "application/json",
        "X-CSRF-Token": token,
      },
      body: JSON.stringify({ planId, sessionId, expectedRevision }),
    },
  );
}
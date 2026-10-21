import { getApiData } from "../../services/http/apiClient";
import type {
  DashboardRosterCreateResponse,
  DashboardRosterDeleteResponse,
  DashboardRosterDetailResponse,
  DashboardRosterManualCreateRequest,
  DashboardRosterConvertToCustomRequest,
  DashboardRosterConvertToCustomResponse,
  DashboardRosterManualProgramSaveRequest,
  DashboardRosterManualProgramSaveResponse,
  DashboardRosterListResponse,
  RosterSessionMutationResponse,
  RosterSessionResetResponse,
  RosterSessionSaveRequest,
  RosterValidationResult,
  RosterDayPreview,
} from "../types/roster";

export class RosterLifecycleApiError extends Error {
  constructor(public status: number, public code: string, message: string, public validation: RosterValidationResult | null = null) {
    super(message);
  }
}

async function lifecycleRequest<T>(url: string, options?: RequestInit): Promise<T> {
  const response = await fetch(url, { credentials: "same-origin", cache: "no-store", ...options });
  const payload = await response.json();
  if (!response.ok || payload.ok !== true) {
    const message = response.status === 401 ? "Je sessie is verlopen. Log opnieuw in."
      : response.status === 403 ? "De beveiligingscontrole is verlopen. Laad de pagina opnieuw."
      : payload.message ?? "De roosteractie is niet gelukt.";
    throw new RosterLifecycleApiError(response.status, payload.code ?? "REQUEST_FAILED", message, payload.validation ?? null);
  }
  return payload.data as T;
}

export function previewDashboardRosterDay(
  plans: readonly { id: number; revision: number }[],
  signal?: AbortSignal,
): Promise<RosterDayPreview> {
  const query = new URLSearchParams({
    planIds: plans.map((p) => p.id).join(","),
    revisions: plans.map((p) => p.revision).join(","),
  });
  return lifecycleRequest(`/api/admin/rosters/day-preview.php?${query}`, { signal });
}

export function applyDashboardRosterDayLessons(plans: readonly { id: number; revision: number }[], token: string): Promise<RosterDayPreview> {
  return lifecycleRequest("/api/admin/rosters/day-lessons-apply.php", {
    method: "POST", headers: { "Content-Type": "application/json", "X-CSRF-Token": token },
    body: JSON.stringify({ plans: plans.map((p) => ({ id: p.id, revision: p.revision })) }),
  });
}

export function validateDashboardRoster(planId: number, expectedRevision: number): Promise<{ validation: RosterValidationResult }> {
  return lifecycleRequest(`/api/admin/rosters/validate.php?planId=${planId}&expectedRevision=${expectedRevision}`);
}

export function changeDashboardRosterLifecycle(action: "finalize" | "reopen", planId: number, expectedRevision: number, token: string): Promise<DashboardRosterDetailResponse> {
  return lifecycleRequest(`/api/admin/rosters/${action}.php`, {
    method: "POST",
    headers: { "Content-Type": "application/json", "X-CSRF-Token": token },
    body: JSON.stringify({ planId, expectedRevision }),
  });
}

export function resetRosterSessions(planId: number, expectedRevision: number, token: string): Promise<RosterSessionResetResponse> {
  return lifecycleRequest('/api/admin/rosters/reset-sessions.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': token },
    body: JSON.stringify({ planId, expectedRevision }),
  });
}

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

export function createDashboardManualRoster(
  request: DashboardRosterManualCreateRequest,
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
      body: JSON.stringify({
        source: "manual",
        ...request,
      }),
    },
  );
}

export function convertDashboardRosterToCustom(
  request: DashboardRosterConvertToCustomRequest,
  token: string,
): Promise<DashboardRosterConvertToCustomResponse> {
  return getApiData<DashboardRosterConvertToCustomResponse>(
    "/api/admin/rosters/convert-to-custom.php",
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

export function saveDashboardManualRosterProgram(
  request: DashboardRosterManualProgramSaveRequest,
  token: string,
): Promise<DashboardRosterManualProgramSaveResponse> {
  return getApiData<DashboardRosterManualProgramSaveResponse>(
    "/api/admin/rosters/manual-program-save.php",
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

export function deleteDashboardRoster(
  planId: number,
  token: string,
): Promise<DashboardRosterDeleteResponse> {
  return getApiData<DashboardRosterDeleteResponse>(
    "/api/admin/rosters/delete.php",
    {
      method: "POST",
      headers: {
        "Content-Type": "application/json",
        "X-CSRF-Token": token,
      },
      body: JSON.stringify({ planId }),
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

export function saveRosterStaffSelection(planId: number, expectedRevision: number, expectedCatalogRevision: number, staffIds: number[], token: string): Promise<RosterSessionMutationResponse> {
  return getApiData<RosterSessionMutationResponse>("/api/admin/rosters/staff-selection-save.php", {
    method: "POST", headers: { "Content-Type": "application/json", "X-CSRF-Token": token },
    body: JSON.stringify({ planId, expectedRevision, expectedCatalogRevision, staffIds }),
  });
}

export function linkRosterPair(plans: readonly { id: number; revision: number }[], workflow: "lessons_first" | "school_first", token: string): Promise<import("../types/roster").RosterJointPlanning> {
  return lifecycleRequest("/api/admin/rosters/pair-link.php", {
    method: "POST", headers: { "Content-Type": "application/json", "X-CSRF-Token": token },
    body: JSON.stringify({ plans: plans.map(({id, revision}) => ({id, revision})), workflow, confirmRestart: true }),
  });
}
export function archiveRosterPair(plans: readonly { id: number; revision: number }[], pair: import("../types/roster").RosterJointPlanning, token: string): Promise<{ archivedPlanIds: number[] }> {
  return lifecycleRequest("/api/admin/rosters/pair-archive.php", {
    method: "POST", headers: { "Content-Type": "application/json", "X-CSRF-Token": token },
    body: JSON.stringify({ plans: plans.map(({id, revision}) => ({id, revision})), pairId: pair.id, pairRevision: pair.revision, confirmArchiveBoth: true }),
  });
}

export function swapRosterCells(planId: number, expectedRevision: number, mode: "lesson" | "staff", first: {sessionId: number; groupId: number}, second: {sessionId: number; groupId: number}, token: string): Promise<{revision: number; warnings: Array<{code: string; message: string}>}> {
  return getApiData("/api/admin/rosters/session-swap.php", {
    method: "POST",
    headers: {"Content-Type": "application/json", "X-CSRF-Token": token},
    body: JSON.stringify({planId, expectedRevision, mode, first, second}),
  });
}

export function previewRosterBookingRefresh(planId: number, expectedRevision: number, token: string): Promise<{ preview: import("../types/roster").RosterBookingRefreshPreview }> {
  return lifecycleRequest('/api/admin/rosters/booking-refresh.php', {
    method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': token },
    body: JSON.stringify({ mode: 'preview', planId, expectedRevision }),
  });
}
export function applyRosterBookingRefresh(preview: import("../types/roster").RosterBookingRefreshPreview, token: string): Promise<DashboardRosterDetailResponse> {
  return lifecycleRequest('/api/admin/rosters/booking-refresh.php', {
    method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': token },
    body: JSON.stringify({ mode: 'apply', planId: preview.planId, expectedRevision: preview.revision, signature: preview.signature, accepted: true }),
  });
}

import { getApiData } from "../../services/http/apiClient";
import type { RosterStaffCatalogResponse, RosterStaffDetail, RosterStaffImpactPreview, RosterStaffDeactivateRequest } from "../types/rosterStaff";

export function fetchRosterStaffCatalog(
  signal?: AbortSignal,
): Promise<RosterStaffCatalogResponse> {
  return getApiData<RosterStaffCatalogResponse>(
    "/api/admin/rosters/staff/index.php",
    { method: "GET", signal },
  );
}

export function fetchRosterStaff(staffId: number): Promise<RosterStaffDetail> {
  return getApiData<RosterStaffDetail>("/api/admin/rosters/staff/show.php?staffId=" + staffId, { method: "GET", cache: "no-store" });
}
function post<T>(operation: string, payload: object, token: string): Promise<T> {
  return getApiData<T>("/api/admin/rosters/staff/" + operation + ".php", {
    method: "POST", headers: { "Content-Type": "application/json", "X-CSRF-Token": token }, body: JSON.stringify(payload),
  });
}
export const createRosterStaff = (payload: object, token: string) => post<RosterStaffDetail>("create", payload, token);
export const updateRosterStaff = (payload: object, token: string) => post<RosterStaffDetail>("update", payload, token);
export const previewRosterStaffImpact = (payload: object, token: string) => post<RosterStaffImpactPreview>("preview-impact", payload, token);
export const deactivateRosterStaff = (payload: RosterStaffDeactivateRequest, token: string) => post<RosterStaffDetail>("deactivate", payload, token);

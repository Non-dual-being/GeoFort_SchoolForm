import { getApiData } from "../../services/http/apiClient";
import type { RosterStaffCatalogResponse } from "../types/rosterStaff";

export function fetchRosterStaffCatalog(
  signal?: AbortSignal,
): Promise<RosterStaffCatalogResponse> {
  return getApiData<RosterStaffCatalogResponse>(
    "/api/admin/rosters/staff/index.php",
    { method: "GET", signal },
  );
}
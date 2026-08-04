import { getApiData } from "../../services/http/apiClient";
import type { DashboardOverviewData } from "../types/dashboardOverview";

export async function fetchDashboardOverview(signal?: AbortSignal): Promise<DashboardOverviewData> {
  const result = await getApiData<{ dashboard: DashboardOverviewData }>(
    "/api/admin/overview.php",
    { method: "GET", signal },
  );
  return result.dashboard;
}

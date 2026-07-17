import { getApiData } from "../../services/http/apiClient";
import type { DashboardBookingDetailResponse } from "../types/bookingDetail";

export function fetchDashboardBookingDetail(
  id: number,
  signal?: AbortSignal,
): Promise<DashboardBookingDetailResponse> {
  const params = new URLSearchParams({ id: String(id) });

  return getApiData<DashboardBookingDetailResponse>(
    `/api/admin/requests/show.php?${params.toString()}`,
    { method: "GET", signal },
  );
}

import { getApiData } from "../../services/http/apiClient";
import type { BookingRevenueReport } from "../types/bookingRevenue";

export function fetchBookingRevenueReport(startDate: string, endDate: string, signal?: AbortSignal): Promise<BookingRevenueReport> {
  const query = new URLSearchParams({ startDate, endDate });
  return getApiData(`/api/admin/requests/revenue.php?${query}`, { method: "GET", signal });
}

import { getApiData } from "../../services/http/apiClient";
import type { BookingVisitDateCalendar } from "../types/bookingVisitDateCalendar";

export function fetchDashboardBookingVisitDateCalendar(bookingId:number,startDate:string,endDate:string,signal?:AbortSignal):Promise<{calendar:BookingVisitDateCalendar}> {
  const query=new URLSearchParams({bookingId:String(bookingId),startDate,endDate});
  return getApiData(`/api/admin/requests/get-booking-visit-date-calendar.php?${query}`,{method:"GET",signal});
}

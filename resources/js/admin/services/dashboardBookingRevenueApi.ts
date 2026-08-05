import { getApiData } from "../../services/http/apiClient";
import type { BookingRevenuePage, BookingRevenueReport, RevenueScope } from "../types/bookingRevenue";

function query(startDate:string|undefined,endDate:string|undefined,revenueScope:RevenueScope,page?:number):string { const values=new URLSearchParams({revenueScope}); if(startDate&&endDate){values.set("startDate",startDate);values.set("endDate",endDate)} if(page!==undefined)values.set("page",String(page)); return `?${values}`; }
export function fetchBookingRevenueReport(startDate: string|undefined, endDate: string|undefined, revenueScope:RevenueScope, signal?: AbortSignal): Promise<BookingRevenueReport> {
  const queryString=query(startDate,endDate,revenueScope);
  return getApiData(`/api/admin/requests/revenue.php${queryString}`, { method: "GET", signal });
}
export function fetchBookingRevenuePage(startDate:string,endDate:string,revenueScope:RevenueScope,page:number,signal?:AbortSignal):Promise<BookingRevenuePage>{return getApiData(`/api/admin/requests/revenue-bookings.php${query(startDate,endDate,revenueScope,page)}`,{method:"GET",signal});}
export function bookingRevenueExportUrl(startDate:string,endDate:string,revenueScope:RevenueScope):string{return `/api/admin/requests/revenue-export.php${query(startDate,endDate,revenueScope)}`;}

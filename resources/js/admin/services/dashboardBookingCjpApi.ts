import type {BookingCjpRequest,BookingCjpResponse} from "../types/bookingCjp";

export const BOOKING_CJP_UPDATE_ENDPOINT="/api/admin/requests/update-booking-cjp.php";
export class BookingCjpApiError extends Error {
  constructor(public status:number,public result:BookingCjpResponse|null) {
    super("CJP-gegevens wijzigen mislukt");
  }
}
export async function updateDashboardBookingCjp(body:BookingCjpRequest,token:string):Promise<BookingCjpResponse> {
  const response=await fetch(BOOKING_CJP_UPDATE_ENDPOINT,{method:"POST",headers:{"Content-Type":"application/json","X-CSRF-Token":token},credentials:"same-origin",body:JSON.stringify(body)});
  let result:BookingCjpResponse|null=null;
  try { result=await response.json() as BookingCjpResponse; } catch {}
  if(!response.ok||!result?.ok)throw new BookingCjpApiError(response.status,result);
  return result;
}

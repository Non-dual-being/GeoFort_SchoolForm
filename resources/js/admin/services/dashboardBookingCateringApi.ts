import type { BookingCateringRequest,BookingCateringResponse } from "../types/bookingCatering";
export class BookingCateringApiError extends Error {constructor(public status:number,public result:BookingCateringResponse|null){super("Eten en drinken wijzigen mislukt");}}
export const BOOKING_CATERING_UPDATE_ENDPOINT="/api/admin/requests/update-booking-catering.php";
export async function updateDashboardBookingCatering(body:BookingCateringRequest,token:string):Promise<BookingCateringResponse>{
  const response=await fetch(BOOKING_CATERING_UPDATE_ENDPOINT,{method:"POST",headers:{Accept:"application/json","Content-Type":"application/json","X-CSRF-Token":token},body:JSON.stringify(body)});
  let result:BookingCateringResponse|null=null;try{result=await response.json() as BookingCateringResponse;}catch{}
  if(!response.ok||!result?.ok)throw new BookingCateringApiError(response.status,result);
  return result;
}

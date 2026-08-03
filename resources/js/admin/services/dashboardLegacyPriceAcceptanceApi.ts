import type { BookingPriceAmounts } from "../types/bookingDetail";
export class LegacyPriceAcceptanceApiError extends Error{constructor(public status:number,public code:string){super("Prijs vastleggen mislukt");}}
export async function acceptLegacyBookingPrice(bookingId:number,token:string):Promise<BookingPriceAmounts>{
  const response=await fetch("/api/admin/requests/accept-legacy-booking-price.php",{method:"POST",headers:{Accept:"application/json","Content-Type":"application/json","X-CSRF-Token":token},body:JSON.stringify({bookingId,explicitlyAccepted:true})});
  const result=await response.json().catch(()=>null) as {ok?:boolean;code?:string;snapshot?:BookingPriceAmounts}|null;
  if(!response.ok||!result?.ok||!result.snapshot)throw new LegacyPriceAcceptanceApiError(response.status,result?.code??"DATABASE_ERROR");
  return result.snapshot;
}

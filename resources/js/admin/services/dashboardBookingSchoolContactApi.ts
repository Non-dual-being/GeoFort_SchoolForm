import type {BookingSchoolContactRequest,BookingSchoolContactResponse} from "../types/bookingSchoolContact";
export const BOOKING_SCHOOL_CONTACT_UPDATE_ENDPOINT="/api/admin/requests/update-booking-school-contact.php";
export class BookingSchoolContactApiError extends Error{constructor(public status:number,public result:BookingSchoolContactResponse|null){super("School- en contactgegevens wijzigen mislukt");}}
export async function updateDashboardBookingSchoolContact(body:BookingSchoolContactRequest,token:string):Promise<BookingSchoolContactResponse>{
  const response=await fetch(BOOKING_SCHOOL_CONTACT_UPDATE_ENDPOINT,{method:"POST",headers:{"Content-Type":"application/json","X-CSRF-Token":token},credentials:"same-origin",body:JSON.stringify(body)});
  let result:BookingSchoolContactResponse|null=null;try{result=await response.json() as BookingSchoolContactResponse;}catch{}
  if(!response.ok||!result?.ok)throw new BookingSchoolContactApiError(response.status,result);
  return result;
}

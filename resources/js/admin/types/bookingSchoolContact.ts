export interface BookingSchoolContactDetails {
  schoolName:string;country:string;address:string;postalCode:string;city:string;schoolPhone:string;
  contactFirstName:string;contactLastName:string;contactEmail:string;contactPhone:string;
}
export type BookingSchoolContactChangeCode="SUCCESS"|"NO_SCHOOL_CONTACT_CHANGE"|"BOOKING_NOT_FOUND"|"INVALID_REQUEST"|"INVALID_SCHOOL_CONTACT_DETAILS"|"SCHOOL_CONTACT_CONFLICT"|"DATABASE_ERROR";
export interface BookingSchoolContactIssue{code:string;field:keyof BookingSchoolContactDetails;category:string;severity:string;title:string;description:string;metadata:Record<string,unknown>}
export interface BookingSchoolContactRequest{bookingId:number;expected:BookingSchoolContactDetails;proposed:BookingSchoolContactDetails}
export interface BookingSchoolContactResponse{ok:boolean;code:BookingSchoolContactChangeCode;bookingId:number;previous:BookingSchoolContactDetails|null;current:BookingSchoolContactDetails|null;changedFields:Record<string,{before:string;after:string}>;validationIssues:BookingSchoolContactIssue[];changeHistoryId:number|null}

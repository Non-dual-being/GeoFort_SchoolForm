export interface BookingCjpDetails {
  useCjp: string;
  contactName: string | null;
  cardNumber: string | null;
}

export interface BookingCjpIssue {
  code: string;
  field: keyof BookingCjpDetails;
  category: string;
  severity: string;
  title: string;
  description: string;
  metadata: Record<string, unknown>;
}

export interface BookingCjpRequest {
  bookingId: number;
  expected: BookingCjpDetails;
  proposed: BookingCjpDetails;
}

export interface BookingCjpResponse {
  ok: boolean;
  code: "SUCCESS"|"NO_CJP_CHANGE"|"BOOKING_NOT_FOUND"|"INVALID_CJP_DETAILS"|"CJP_CONFLICT"|"DATABASE_ERROR";
  bookingId: number;
  previous: BookingCjpDetails|null;
  current: BookingCjpDetails|null;
  changedFields: Record<string,{before:string|null;after:string|null}>;
  validationIssues: BookingCjpIssue[];
  changeHistoryId: number|null;
}

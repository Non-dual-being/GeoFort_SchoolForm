import type { BookingValidationIssue } from "./bookingStatus";

export type BookingLunchChoice = "remise_lunch" | "eigen_picknick";
export type BookingLunchReadChoice = BookingLunchChoice | "none" | "conflict";
export type BookingCateringChangeCode = "SUCCESS"|"NO_CATERING_CHANGE"|"BOOKING_NOT_FOUND"|"INVALID_REQUEST"|"INVALID_CATERING_SELECTION"|"INVALID_STORED_BOOKING"|"CATERING_CONFLICT"|"DATABASE_ERROR";
export interface BookingCateringExpectedValues {remiseBreak:number;kazerneBreak:number;fortgrachtBreak:number;waterIce:number;lemonade:number;remiseLunch:number;ownPicnic:boolean}
export interface BookingCateringProposedValues {remiseBreak:number;kazerneBreak:number;fortgrachtBreak:number;waterIce:number;lemonade:number;lunchChoice:BookingLunchChoice;remiseLunch:number}
export interface BookingCateringRequest {bookingId:number;expected:BookingCateringExpectedValues;proposed:BookingCateringProposedValues}
export interface BookingCateringResponse {ok:boolean;code:BookingCateringChangeCode;bookingId:number;status:string|null;previous:BookingCateringExpectedValues|null;current:BookingCateringExpectedValues|null;changedFields:Record<string,{before:number|boolean;after:number|boolean}>;validationIssues:BookingValidationIssue[];changeHistoryId:number|null}
export interface BookingCateringOption {key:string;label:string;description:string;min:number;max:number;price:number}
export interface BookingCateringOptions {snacks:Record<string,BookingCateringOption>;lunch:Record<string,BookingCateringOption>}

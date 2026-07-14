
import GeoFormInputField from "../../components/form/GeoFormInputField.vue";
import GeoFormSelectField from "../../components/form/GeoFormSelectFied.vue";
import GeoFormDateField from "../../components/form/GeoFormBookingDateField.vue"
import GeoFormTextareaField from "../../components/form/GeoFormTextareaField.vue";

import {
  bookingFieldNames,
  phoneFieldNames,
  cjpFields,
} from "../../config/booking/BookingFieldConstants";

import {
  type SchoolSectorKey
} from "./BookingProgramConfigTypes"

//indexing the type with a number thx the the readonly tuple
export type BookingField = (typeof bookingFieldNames)[number];
export type PhoneNumberField = (typeof phoneFieldNames)[number];
export type CJPFields = (typeof cjpFields)[number];

export type GeoFortDiscoveryOptions = string;

export type TextBookingField = Exclude<BookingField, "land">;

export type CountryCode = "Nederland" | "België";
export type CjpUsage = "nee" | "ja";

export type InputMode = "text" | "email" | "tel" | "search" | "url" | "none" | "numeric" | "decimal" | undefined;

export type CountryDependentField = PhoneNumberField | "postcode";


export type RadioOptionCjpUsage = {
  value: CjpUsage;
  label: string;
  description?: string;
}

export type SchoolSectorOption = {
    label: string,
    value: SchoolSectorKey
}

export type InputFieldInstance = InstanceType<typeof GeoFormInputField>;
export type SelectFieldInstance = InstanceType<typeof GeoFormSelectField>
export type DateFieldInstance = InstanceType<typeof GeoFormDateField>
export type TextareaFieldInstance = InstanceType<typeof GeoFormTextareaField>;
export type FieldInstance = InputFieldInstance | SelectFieldInstance | DateFieldInstance | TextareaFieldInstance;

export type NonEmptyStringArray = [string, ...string[]];

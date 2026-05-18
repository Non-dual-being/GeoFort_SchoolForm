
import GeoFormInputField from "../../components/form/GeoFormInputField.vue";
import GeoFormSelectField from "../../components/form/GeoFormSelectFied.vue";
import { bookingFieldNames, phoneFieldNames } from "../../config/booking/BookingFields";


//indexing the type with a number thx the the readonly tuple
export type BookingField = (typeof bookingFieldNames)[number];
export type PhoneNumberField = (typeof phoneFieldNames)[number];

export type TextBookingField = Exclude<BookingField, "land">;

export type CountryCode = "Nederland" | "België";

export type InputMode = "text" | "email" | "tel" | "search" | "url" | "none" | "numeric" | "decimal" | undefined;

export type CountryDependentField = PhoneNumberField | "postcode";


export type InputFieldInstance = InstanceType<typeof GeoFormInputField>;
export type SelectFieldInstance = InstanceType<typeof GeoFormSelectField>
export type FieldInstance = InputFieldInstance | SelectFieldInstance;

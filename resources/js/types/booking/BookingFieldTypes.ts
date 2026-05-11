
import GeoFormInputField from "../../components/form/GeoFormInputField.vue";
import GeoFormSelectField from "../../components/form/GeoFormSelectFied.vue"

//as const create readonly tuple instead of string[]
const bookingFieldNames = [
    "schoolnaam", 
    "land",
    "adres",
    "postcode",
    "plaats"
] as const;

//indexing the type with a number thx the the readonly tuple
export type BookingField = (typeof bookingFieldNames)[number];
export type TextBookingField = Exclude<BookingField, "land">;

export type CountryCode = "Nederland" | "België";


export type InputFieldInstance = InstanceType<typeof GeoFormInputField>;
export type SelectFieldInstance = InstanceType<typeof GeoFormSelectField>
export type FieldInstance = InputFieldInstance | SelectFieldInstance;

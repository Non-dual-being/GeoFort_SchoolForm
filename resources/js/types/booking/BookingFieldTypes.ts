
import GeoFormInputField from "../../components/form/GeoFormInputField.vue";

//as const create readonly tuple instead of string[]
const bookingFieldNames = ["schoolnaam", "adres"] as const;

//indexing the type with a number thx the the readonly tuple
export type BookingField = (typeof bookingFieldNames)[number];

export type InputFieldInstance = InstanceType<typeof GeoFormInputField>;

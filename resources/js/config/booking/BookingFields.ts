import GeoFormInputField from "../../components/form/GeoFormInputField.vue";
import type { BookingField } from "../../types/booking/BookingFieldTypes";

export const bookingFieldNames = ["schoolnaam", "adres"] as const;

export type BookingFormValues = Record<BookingField, string>;

export type BookingFieldConfig = {
    id: BookingField;
    label: string;
    type?: string;
    placeholder?: string;
    required?: boolean;
    autocomplete?: string;
};



export const BookingFieldConfig: Record<BookingField, BookingFieldConfig> = {
    schoolnaam: {
        id: "schoolnaam",
        label: "Naam van de school",
        type: "text",
        placeholder: "",
        required: true,
        autocomplete: "",
    },
    adres: {
        id: "adres",
        label: "Adres van de school",
        type: "text",
        placeholder: "",
        required: true,
        autocomplete: "",

    }
};

export function createInitialBookingForm(): BookingFormValues {
    return Object.fromEntries(
        bookingFieldNames.map((field) => [field, ""])
    ) as BookingFormValues
}

/**
 * map creates [
 *      ["schoolnaam", ""],
 *      ["adres", ""] 
 *
 * ]
 * 
 * Object form entries turns into 
 * {
 *      schoolnaam: "",
 *      adres: ""
 * }
 */
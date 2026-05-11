import GeoFormInputField from "../../components/form/GeoFormInputField.vue";
import type { 
    BookingField,
    CountryCode 
} from "../../types/booking/BookingFieldTypes";

export const bookingFieldNames = ["schoolnaam", "land", "postcode", "adres", "plaats"] as const;

export type BookingFormValues = Record<BookingField, string>;

export const CountryOptions: ReadonlyArray<{
    value: CountryCode,
    label: string;
}> = [
    {
        value: "Nederland",
        label: "Nederland"
    },
    {
        value: "België",
        label: "België",
    }
] as const;
  

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
    land: {
        id: "land",
        label: "land",
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

    },
    postcode: {
        id: "postcode",
        label: "Postcode",
        type: "text",
        placeholder: "",
        required: true,
        autocomplete: "",
    },
    plaats: {
        id: "plaats",
        label: "Plaats",
        type: "text",
        placeholder: "Bijvoorbeeld: Herwijnen",
        required: true,
        autocomplete: "",
    }


};

export function createInitialBookingForm(): BookingFormValues {
    return Object.fromEntries(
        bookingFieldNames.map((field) => {
            if (field === "land") return [field, "Nederland"];
            return [field, ""];
        })
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
import type { 
    BookingField,
    CountryCode, 
    PhoneNumberField
} from "../../types/booking/BookingFieldTypes";

export const bookingFieldNames = ["schoolnaam", "land", "adres", "postcode",  "plaats", "schoolTelefoonnummer", "contactpersoonTelefoonnummer"] as const;

export const phoneFieldNames = ["schoolTelefoonnummer", "contactpersoonTelefoonnummer"] as const;

export type BookingFormValues = Record<BookingField, string>;

export const countryOptions: ReadonlyArray<{
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
    inputmode?: string;
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
    },
     schoolTelefoonnummer: {
        id: "schoolTelefoonnummer",
        label: "Telefoonnummer van de school",
        type: "tel",
        placeholder: "Bijvoorbeeld: 06 12345678",
        required: true,
        autocomplete: "tel",
        inputmode: "tel",
    },

    contactpersoonTelefoonnummer: {
        id: "contactpersoonTelefoonnummer",
        label: "Telefoonnummer contactpersoon",
        type: "tel",
        placeholder: "Bijvoorbeeld: +31 6 12345678",
        required: true,
        autocomplete: "tel",
        inputmode: "tel",
    },
};

export function createInitialBookingForm(): BookingFormValues {
    return Object.fromEntries(
        bookingFieldNames.map((field) => {
            if (field === "land") return [field, "Nederland"];
            return [field, ""];
        })
    ) as BookingFormValues
}

export function isPhoneBookingField(
    field: BookingField
): field is PhoneNumberField {
    return phoneFieldNames.includes(field as PhoneNumberField)
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
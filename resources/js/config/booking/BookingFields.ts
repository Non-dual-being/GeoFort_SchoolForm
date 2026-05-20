import type { 
    BookingField,
    CountryCode, 
    CountryDependentField, 
    InputMode, 
    PhoneNumberField
} from "../../types/booking/BookingFieldTypes";
import { isCountryDependentField } from "../validation/booking";

export const bookingFieldNames = [
    "schoolnaam", 
    "land", 
    "adres", 
    "postcode",  
    "plaats", 
    "schoolTelefoonnummer", 
    "contactpersoonTelefoonnummer",
    "contactpersoonVoornaam",
    "contactpersoonAchternaam",
    "email",
    "bezoekdatum"
] as const;



export const phoneFieldNames = ["schoolTelefoonnummer", "contactpersoonTelefoonnummer"] as const;

export type BookingFormValues = Record<BookingField, string>;

export const countryDependentFields = [
    "postcode",
    "schoolTelefoonnummer",
    "contactpersoonTelefoonnummer"] as const;


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
    inputmode: InputMode;
};


export const BookingFieldConfig: Record<BookingField, BookingFieldConfig> = {
    schoolnaam: {
        id: "schoolnaam",
        label: "Naam van de school",
        type: "text",
        placeholder: "",
        required: true,
        autocomplete: "organization",
        inputmode:"text" 
    },
    land: {
        id: "land",
        label: "land",
        required: true,
        autocomplete: "country-name",
        inputmode: "text"
    },
    adres: {
        id: "adres",
        label: "Adres van de school",
        type: "text",
        placeholder: "",
        required: true,
        autocomplete: "address-line1",
        inputmode: "text"

    },
    postcode: {
        id: "postcode",
        label: "Postcode",
        type: "text",
        placeholder: "",
        required: true,
        autocomplete: "postal-code",
        inputmode: "text"
    },
    plaats: {
        id: "plaats",
        label: "Plaats",
        type: "text",
        placeholder: "Bijvoorbeeld: Herwijnen",
        required: true,
        autocomplete: "address-level2",
        inputmode: "text"
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
    contactpersoonVoornaam: {
        id: "contactpersoonVoornaam",
        label: "Voornaam van de contactpersoon",
        type: "text",
        placeholder: "",
        required: true,
        autocomplete: "given-name",
        inputmode: "text"
    },
    contactpersoonAchternaam: {
        id: "contactpersoonAchternaam",
        label: "Achternaam van de contactpersoon",
        type: "text",
        placeholder: "",
        required: true,
        autocomplete: "family-name",
        inputmode: "text"
    },
    email: {
        id: "email",
        label: "Email",
        type: "email",
        placeholder: "E-mailadres: info@dalton.nl",
        required: true,
        autocomplete: "email",
        inputmode: "email"
    },
    bezoekdatum: {
        id: "bezoekdatum",
        label: "Datum van het bezoek",
        placeholder: "",
        required: true,
        autocomplete: "booking date",
        inputmode: "text"
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

export function isPhoneBookingField(
    field: BookingField
): field is PhoneNumberField {
    return phoneFieldNames.includes(field as PhoneNumberField)
}

export const countryDependentPlaceholders: Record<CountryDependentField, Record<CountryCode, string>> & Record<"plaats", Record<CountryCode, string>> = {
    postcode: {
        Nederland: "4171 KG",
        België: "9700"
    },
    contactpersoonTelefoonnummer: {
        Nederland: "voorbeeldnummer: 06-38005182",
        België: "voorbeeldnummer: +32 586 28 14 40"
    },
    schoolTelefoonnummer: {
        Nederland: "+31 20 123 4567",
        België: "+32 2 555 12 34"
    },
    plaats: 
    {
        Nederland: "Herwijnen",
        België: "Oudenaarde"
    }
}

export function getPlaceHolder(field: BookingField, country: CountryCode): string {
    if (!isCountryDependentField(field)){
        if (field === "plaats") return countryDependentPlaceholders["plaats"][country]
        return BookingFieldConfig[field].placeholder ?? ""
    }

 

    return countryDependentPlaceholders[field][country];

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
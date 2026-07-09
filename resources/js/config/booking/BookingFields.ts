import type { 
    BookingField,
    CountryCode, 
    CountryDependentField, 
    InputMode, 
    PhoneNumberField
} from "../../types/booking/BookingFieldTypes.ts";

import {
    bookingFieldNames,
    phoneFieldNames,
    countryDependentFields,
} from "./BookingFieldConstants.ts"


import type {
  LunchKey,
  PriceType,
  ProgramKey,
  SchoolSectorKey,
  SnackKey,
  Weekday,
  SchoolLevelSelectionState,
  EducationModuleKey
} from "./../../types/booking/BookingProgramConfigTypes";


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
        label: "Land van de school",
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
        label: "Postcode van de school",
        type: "text",
        placeholder: "",
        required: true,
        autocomplete: "postal-code",
        inputmode: "text"
    },
    plaats: {
        id: "plaats",
        label: "Plaats van de school",
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
        label: "Telefoonnummer van de contactpersoon",
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
        label: "E-mailadres voor het contact",
        type: "email",
        placeholder: "E-mailadres: info@dalton.nl",
        required: true,
        autocomplete: "email",
        inputmode: "email"
    },
    bezoekdatum: {
        id: "bezoekdatum",
        label: "Datum van het bezoek",
        placeholder: "Kies een bezoekdatum",
        required: true,
        autocomplete: "off",
        inputmode: "text"
    },
    hoeKentUGeoFort: {
        id: "hoeKentUGeoFort",
        label: "Hoe kent u GeoFort",
        required: false,
        autocomplete: "off",
        inputmode: "text"
        
    },
    cjpPasGebruik: {
        id: "cjpPasGebruik",
        label: "Maak uw school gebruik van cjp-Korting?",
        required: true,
        autocomplete: "off",
        inputmode: "text"
    },
    cjpContactpersoonNaam: {
        id: "cjpContactpersoonNaam",
        label: "Naam contactpersoon CJP-pas",
        type: "text",
        placeholder: "Bijvoorbeeld: Jan de Vries",
        required: true,
        autocomplete: "name",
        inputmode: "text",
    },
    cjpPasnummer: {
        id: "cjpPasnummer",
        label: "CJP-pasnummer",
        type: "text",
        placeholder: "Bijvoorbeeld: 12345678",
        required: true,
        autocomplete: "off",
        inputmode: "numeric",
  },
  onderwijsSector: {
        id: "onderwijsSector",
        label: "Selecteer de toepasselijke onderwijssector",
        type: "text",
        placeholder: "",
        autocomplete: undefined,
        inputmode: "text",
        required: true,
  }
};

export type BaseBookingFormValues = {
    [K in BookingField]: K  extends "onderwijsSector"
        ? SchoolSectorKey | ""
        : string; 
} 

export type BookingFormValues = BaseBookingFormValues & {
    
    programma: ProgramKey | "";
    levelSelection: SchoolLevelSelectionState;
    keuzemodule: EducationModuleKey | "";
    aantalLeerlingen: string;
    aantalBegeleiders: string;
    remiseBreak: string;
    kazerneBreak: string;
    fortgrachtBreak: string;
    waterijsje: string;
    glasLimonade: string;
    lunchChoice: "" | "remise_lunch" | "eigen_picknick";
    remiseLunch: string;
    voorwaardenAkkoord: boolean;

}

export function createEmptyLevelSelection(): SchoolLevelSelectionState {
    return {
        selectedLevels: {
            primairOnderwijs: [],
            voortgezetOnderbouw: [],
            voortgezetBovenbouw: [],
        },
        selectedGroupsByLevel: {
            primairOnderwijs: {
                regulier: [],
                speciaal: [],
            },
            voortgezetOnderbouw: {
                vmboBasisKader: [],
                vmboGemengdTheoretisch: [],
                havo: [],
                vwo: [],
                praktijkOnderwijs: [],
            },
            voortgezetBovenbouw: {
                vmbo: [],
                havo: [],
                vwo: [],
                praktijkOnderwijs: [],
            },
        },
    };
}

export function createInitialBookingForm(): BookingFormValues {
    const baseValues = Object.fromEntries(
        bookingFieldNames.map((field) => {
            if (field === "land") return [field, "Nederland"];
            if (field === "cjpPasGebruik") return [field, "nee"];
            return [field, ""];
        }),
    ) as BaseBookingFormValues

    return {
        ...baseValues,
        programma: "",
        levelSelection: createEmptyLevelSelection(),
        keuzemodule: "",
        aantalLeerlingen: "",
        aantalBegeleiders: "",
        remiseBreak: "0",
        kazerneBreak: "0",
        fortgrachtBreak: "0",
        waterijsje: "0",
        glasLimonade: "0",
        lunchChoice: "eigen_picknick",
        remiseLunch: "0",
        voorwaardenAkkoord: false,
    };
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



export function isCountryDependentField(
  field: BookingField,
): field is CountryDependentField {
  return (countryDependentFields as readonly string[]).includes(field);
}

export function getPlaceHolder(field: BookingField, country: CountryCode): string {
    if (!isCountryDependentField(field)){
        if (field === "plaats") return countryDependentPlaceholders["plaats"][country]
        return BookingFieldConfig[field].placeholder ?? ""
    }

 

    return countryDependentPlaceholders[field][country];

}

/**
 *   rule: { allowedValues?: unknown }
 *  ik stuur een object door met mogelijk allowedValues en die waarden kunnen vanalles zijn
 */

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


export const schoolSectorOrder: SchoolSectorKey[] = [
  "primairOnderwijs",
  "voortgezetOnderbouw",
  "voortgezetBovenbouw",
];


export const programOrder: ProgramKey[] = ["ochtend", "dag"];


export const priceTypeLabels: Record<PriceType, string> = {
  basis: "Primair Onderwijs",
  voortgezet: "Voortgezet Onderwijs",
};

export const weekdayLabels: Record<Weekday, string> = {
  1: "maandag",
  2: "dinsdag",
  3: "woensdag",
  4: "donderdag",
  5: "vrijdag",
};

export const snackLabels: Record<SnackKey, string> = {
  remise_break: "Remise break",
  kazerne_break: "Kazerne break",
  fortgracht_break: "Fortgracht break",
  glas_limonade: "Glas limonade",
  waterijsje: "Waterijsje",
};

export const lunchLabels: Record<LunchKey, string> = {
  remise_lunch: "Remise lunch",
  eigen_picknick: "Eigen picknick",
};



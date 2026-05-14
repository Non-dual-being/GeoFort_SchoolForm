import type RULES from "../../types/global"

import { 
    bookingFieldNames,
    BookingFormValues,
    countryDependentFields,
    isPhoneBookingField
} from "../booking/BookingFields.ts"

import type { BookingField, CountryCode, CountryDependentField, PhoneNumberField } from "../../types/booking/BookingFieldTypes.ts";

import { type ValidationShape } from "../../types/validation/FieldErrorTypes.ts";


const serverRuleRaw = window.FORM_RULES || {};

export type FieldError = Partial<Record<BookingField, string>>;


export type Rule = {
    min: number;
    max: number;
    required: boolean;
    regex: RegExp;
    minDigits?: number;
    maxDigits?: number;

};


type RawRuleDto = {
    min: number;
    max: number;
    required: boolean;
    pattern: string;
    flags: string;
    minDigits?: number;
    maxDigits?: number;

}

type FrontendFormRules = Record<
    Exclude<BookingField, "postcode" | "schoolTelefoonnummer" | "contactpersoonTelefoonnummer">,
    RawRuleDto
> & {
    postcode: Record<CountryCode, RawRuleDto>,
    schoolTelefoonnummer: Record<CountryCode, RawRuleDto>;
    contactpersoonTelefoonnummer: Record<CountryCode, RawRuleDto>;
};

type CompiledRules = Record<
    Exclude<BookingField, "postcode" | "schoolTelefoonnummer" | "contactpersoonTelefoonnummer">,
    Rule
> & {
    postcode: Record<CountryCode, Rule>;
    schoolTelefoonnummer: Record<CountryCode, Rule>;
    contactpersoonTelefoonnummer: Record<CountryCode, Rule>;
};

type InvalidPatternMessages = {
    [k in Exclude<BookingField, "postcode" | "schoolTelefoonnummer" | "contactpersoonTelefoonnummer">]: string;
} & {
    postcode: Record<CountryCode, string>;
    schoolTelefoonnummer: Record<CountryCode, string>;
    contactpersoonTelefoonnummer: Record<CountryCode, string>;
};

function isCountryDependentField(field: BookingField): field is CountryDependentField {
    return (countryDependentFields as readonly string[]).includes(field)
}

function isObject(value: any): value is Record<string, any> {
    return typeof value === "object" && value !== null;
}


function compileRule(field: string, dto: any): Rule {
    if (!isObject(dto)) throw new Error(`Validation rule missing for field: ${field}`);

    const raw = dto as RawRuleDto;

    return {
        min: raw.min,
        max: raw.max,
        required: raw.required,
        regex: new RegExp(raw.pattern, raw.flags),
        minDigits: raw.minDigits,
        maxDigits: raw.maxDigits,
    } 
}

function compileRules(): CompiledRules {
  return {
    schoolnaam: compileRule("schoolnaam", serverRuleRaw.schoolnaam),
    land: compileRule("land", serverRuleRaw.land),
    adres: compileRule("adres", serverRuleRaw.adres),
    plaats: compileRule("plaats", serverRuleRaw.plaats),
    postcode: {
      Nederland: compileRule(
        "postcode.Nederland",
        serverRuleRaw.postcode?.Nederland,
      ),
      België: compileRule("postcode.België", serverRuleRaw.postcode?.België),
    },
    schoolTelefoonnummer: {
        Nederland: compileRule(
            "schoolTelefoonnummer.Nederland",
            serverRuleRaw.schoolTelefoonnummer?.Nederland
        ),
        België: compileRule(
            "schoolTelefoonnummer.België",
            serverRuleRaw.schoolTelefoonnummer?.België
        )
    },
    contactpersoonTelefoonnummer: {
        Nederland: compileRule(
            "contactpersoonTelefoonnummer.Nederland",
            serverRuleRaw.contactpersoonTelefoonnummer?.Nederland
        ),
        België: compileRule(
            "contactpersoonTelefoonnummer.België",
            serverRuleRaw.contactpersoonTelefoonnummer?.België
        )
    }
  };
}

export const rules = compileRules();

const invalidPatternMessages: InvalidPatternMessages = {
    schoolnaam:
    "De naam van de school bevat ongeldige tekens. Gebruik letters, cijfers, spaties en eenvoudige leestekens.",
    land: "Kies een geldig land.",
    adres:
    "Het adres bevat ongeldige tekens. Gebruik letters, cijfers, spaties en gangbare adresleestekens.",
    postcode:
        {
            Nederland: "Gebruik bijvoorbeeld 4175 LD",
            België: "Voer 4 cijfers in zoals 9700 voor Oudenaarde"
        },
    plaats:
    "De plaatsnaam bevat ongeldige tekens. Gebruik alleen letters, spaties, koppeltekens en apostrofs.",
    schoolTelefoonnummer: 
        {
            Nederland: "Gebruik een geldig Nederlands bijvoorbeeld 06 12345678 of +31 612345678",
            België: "Gebruik een geldig Nederland +32 4 12 34 56 78."
        },
    contactpersoonTelefoonnummer:
        {
            Nederland:"Gebruik een geldig mobiel nummer (06), bijvoorbeeld 06 12345678 of +31 6 12345678.",
            België:"Gebruik een geldig mobiel nummer (04xx), bijvoorbeeld 0471 12 34 56 of +32 471 12 34 56.",
        }
};

const requiredMessages: Record<BookingField, string> = {
    schoolnaam: "Vul de naam van de school in.",
    land: "Vul het land in.",
    postcode: "Vul de postcode in van de school.",
    adres: "Vul het adres van de school in.",
    plaats: "Vul de plaats van de school in",
    schoolTelefoonnummer: "Vul het telefoonnummer van de school in",
    contactpersoonTelefoonnummer: "vul het telefoonnummer van de contactpersoon in"
}


function getRuleFromField(
    field: BookingField, 
    values: BookingFormValues
): Rule {
    if (isCountryDependentField(field)){
        if (!isCountryCode(values.land)) {
            throw new Error(`Geen geldig gekozen land voor veld ${field}`)
        }
        const land = values.land as CountryCode
        return rules[field][land]
    }

    return rules[field]
};

export function isCountryCode(value: string): value is CountryCode {
    return value === "België" || value === "Nederland"
};

function getInvalidPatternMessage(
    field: BookingField,
    values: BookingFormValues
): string {
    if (isCountryDependentField(field)){
        if (!isCountryCode(values.land)){
            throw new Error(`${values.land} is not a valid land property`)
        }
        const land = values.land;
        return invalidPatternMessages[field][land];
    }

    return invalidPatternMessages[field]
};



export function validateField(
    field: BookingField, 
    value: string,
    values: BookingFormValues
): ValidationShape {
    const rule = getRuleFromField(field, values)
    const v = (value ?? "").trim();
    
    if (rule.required && v.length === 0){
        return { warning: requiredMessages[field]};
    }

    if ((isCountryDependentField(field)) && (!isCountryCode(values.land))){
        return {
            error: "kies een geldig land"
        };

    }

    if (v.length > 0 && v.length < rule.min){
        return {error: `Beschrijf dit veld met minimaal ${rule.min} tekens`};
    }

    if (v.length > rule.max) {
        return {error: `Maximaal ${rule.max} tekens`};
    }

    if (isPhoneBookingField(field)){
        const digitCount = countPhoneDigits(value);
        const countryText = values.land === "Nederland"
            ? "Nederlandse"
            : "Belgische";
        if (rule.minDigits !== undefined && digitCount < rule.minDigits){
            return {
                error: `Het ${countryText} nummer moet minimaal ${rule.minDigits} bevatten`
            }
        }

        if (rule.maxDigits !== undefined && rule.maxDigits < digitCount){
            return {
                error: `Het ${countryText} nummer moet minimaal ${rule.maxDigits} bevatten`
            }
        }
    }

    rule.regex.lastIndex = 0;

    if (v.length > 0 && !rule.regex.test(v)) {
        return {
            error: getInvalidPatternMessage(field, values)
        }

    }
        
    return {}

}

export function validateAll(
    values: BookingFormValues
): {
    issues: Record<BookingField, ValidationShape>;
    firstError: BookingField | null;
} {
    const issues = {} as Record<BookingField, ValidationShape>
    let firstError: BookingField | null = null;

    
    for (const field of bookingFieldNames){
        const result = validateField(field, values[field] ?? "", values);
        issues[field] = result;
        if (!firstError && result.error){
            firstError = field as BookingField;
        }
    }

    return { issues, firstError }
}


export function normalizePostcode(
  value: string,
  land: CountryCode,
): string {
  const raw = (value ?? "").trim().toUpperCase();

  if (land === "Nederland") {
    const compact = raw.replace(/\s+/g, "");
    const match = compact.match(/^(\d{4})([A-Z]{2})$/);

    if (match) {
      return `${match[1]} ${match[2]}`;
    }

    return raw.replace(/\s+/g, " ");
  }

  if (land === "België") {
    return raw.replace(/\s+/g, "");
  }

  return raw.replace(/\s+/g, " ");
}

export function normalizePhoneNumber(value: string): string {
    return (value ?? "")
        .trim()
        .replace(/\u00a0/g, " ")
        .replace(/[ \t]+/g, " ")
        .replace(/\s*-\s*/g, "-")
        .replace(/^\+\s+/, "+");
}

export function countPhoneDigits(value: string): number {
    return (value.match(/\d/g) ?? []).length;
}






















/**
 * *Record utility type keys van type K and values of type V
 * -> autocompletion
 * 
 * positive lookahead (?=.[1,60]$) total length of 1 to 60, . staat voor elk teken behalve newline
 * negative lookahead (?!.*\d\s+\d) forbids patrons of 1 2  .* staat voor 0 of meer tekens
 * 
 * -----> (?:[ .,'&-][\p{L}0-9]+)* 
 *  - [ .,'&-]: een scheidingsteken (spatie, punt, komma, apostrof, ampersand, koppelteken)
	- [\p{L}0-9]+: gevolgd door weer letters/cijfers
    - u-flag: verplicht bij \p{L} om Unicode lettercategorieën te gebruiken

 */
import type RULES from "../../types/global"

import { 
    bookingFieldNames,
    BookingFormValues
} from "../booking/BookingFields.ts"

import type { BookingField, CountryCode } from "../../types/booking/BookingFieldTypes.ts";

import { type ValidationShape } from "../../types/validation/FieldErrorTypes.ts";


const serverRuleRaw = window.FORM_RULES || {};

export type FieldError = Partial<Record<BookingField, string>>;


export type Rule = {
    min: number;
    max: number;
    required: boolean;
    regex: RegExp;

};


type RawRuleDto = {
    min: number;
    max: number;
    required: boolean;
    pattern: string;
    flags: string;

}

type FrontendFormRules = Record<
    Exclude<BookingField, "postcode">,
    RawRuleDto
> & {
    postcode: Record<CountryCode, RawRuleDto>
};

type CompiledRules = Record<
    Exclude<BookingField, "postcode">,
    Rule
> & {
    postcode: Record<CountryCode, Rule>
};

type InvalidPatternMessages = {
    [k in Exclude<BookingField, "postcode">]: string;
} & {
    postcode: Record<CountryCode, string>
};

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
        regex: new RegExp(raw.pattern, raw.flags)
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
};

const requiredMessages: Record<BookingField, string> = {
    schoolnaam: "Vul de naam van de school in.",
    land: "Vul het land in.",
    postcode: "Vul de postcode in van de school.",
    adres: "Vul het adres van de school in.",
    plaats: "Vul de plaats van de school in"
}


function getRuleFromField(
    field: BookingField, 
    values: BookingFormValues
): Rule {
    if (field === "postcode") {
        const Country = values.land as CountryCode;

        if (!["Nederland", "België"].includes(Country)){
            return rules.postcode.Nederland
        }
        
        return rules.postcode[Country];

    }

    return rules[field]
};

function isCountryCode(value: string): value is CountryCode {
    return value === "België" || value === "Nederland"
};

function getInvalidPatternMessage(
    field: BookingField,
    values: BookingFormValues
): string {
    if (field === "postcode"){
        if (!isCountryCode(values.land)) return "vul eerst een correct land in";
        return invalidPatternMessages[field][values.land]
    }

    return invalidPatternMessages[field]
}


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

    if (v.length > 0 && v.length < rule.min){
        return {error: `Beschrijf dit veld met minimaal ${rule.min} tekens`};
    }

    if (v.length > rule.max) {
        return {error: `Maximaal ${rule.max} tekens`};
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
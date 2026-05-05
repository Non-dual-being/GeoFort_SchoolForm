import type RULES from "../../types/global"
import { 
    bookingFieldNames,
    BookingFormValues 
} from "../booking/BookingFields.ts"

import type { BookingField } from "../../types/booking/BookingFieldTypes.ts";


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

function compileRule(field: BookingField, dto: any): Rule {
    if (!dto || dto !== "object"){
        throw new Error(`Validation rule missing for field: ${field}`)
    };

    const raw = dto as RawRuleDto;


    return {
        min: raw.min,
        max: raw.max,
        required: raw.required,
        regex: new RegExp(raw.pattern, raw.flags)
    } 
}


export const rules = Object.fromEntries(
    bookingFieldNames.map((field) => [
        field,
        compileRule(field, serverRuleRaw[field])
    ])
) as Record<BookingField, Rule>;


const invalidPatternMessages: Record<BookingField, string> = {
    schoolnaam:
        "De naam van de school mag alleen letters, cijfers en punten of koppeltekens bevatten.",
    adres:
        "Het adres mag alleen letters, cijfers, spaties en gebruikelijke leestekens bevatten.",
};

const requiredMessages: Record<BookingField, string> = {
    schoolnaam: "Vul de naam van de school in.",
    adres: "Vul het adres van de school in.",
}



export function validateField(field: BookingField, value: string): ValidationShape {
    const rule = rules[field];
    const v = (value ?? "").trim();

    const regex5digits = /\d{4,}/

    if (rule.required && v.length === 0){
        return { warning: requiredMessages[field]};
    }

    if (v.length > 0 && v.length < rule.min){
        return {error: `Beschrijf dit veld met minimaal ${rule.min} tekens`};
    }

    if (v.length > rule.max) {
        return {error: `Maximaal ${rule.max} tekens`};
    }

    if (regex5digits.test(v)) {
        return {error: "Gebruik niet meer dan 4 cijfers achter elkaar."};
    }

    if (v.length > 0 && !rule.regex.test(v))
        return {error: invalidPatternMessages[field]};

    return {}

}

export function validateAll(
    values: Partial<Record<BookingField, string>>
): {
    issues: Record<BookingField, ValidationShape>;
    firstError: BookingField | null;
} {
    const issues = {} as Record<BookingField, ValidationShape>
    let firstError: BookingField | null = null;

    
    for (const key of Object.keys(values) as BookingField[]){
        const result = validateField(key, values[key] ?? "");
        issues[key] = result;
        if (!firstError && result.error){
            firstError = key as BookingField;
        }
    }

    return { issues, firstError }
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
import type RULES from "../types/global"

export type BookingField = "schoolnaam";

export type FieldError = Partial<Record<BookingField, string>>;

export type SubmitSuccess = {
    ok: true
}

export type SubmitValidationError = {
    ok: false;
    type: "validation";
    fieldErrors: FieldError;
}

export type SubmitServerError = {
    ok: false;
    type: "server";
    code: number;
}

export type SubmitRateLimitError = {
    ok: false;
    type: "rate-limit";
    retryAfter: number;
};


export type SubmitResult = 
    | SubmitSuccess 
    | SubmitValidationError 
    | SubmitServerError
    | SubmitRateLimitError;

export type Rule = {
    min: number;
    max: number;
    required: boolean;
    regex: RegExp;

};

export type Issue = string | null;

type Exact<T, X extends T> = T & {
    [K in Exclude<keyof X, keyof T>]: never
}


/**
 * Type Exact met type parameter X en T
 * 
 * X exentds T -> alles wat in x zit moet compatible met type T zijn
 * 
 * T & extra objectype (kruising)
 * 
 * Alle keys uit X die niet in T zitten verzamelen we in K en geven de never property
 * 
 * 
 */

export type ValidationShape = {

error?: Issue;

warning?: Issue;

} 

export type ValidationObj = Exact<ValidationShape, ValidationShape>;

function compileRule(dto: any): Rule {
    return {
        min: dto.min,
        max: dto.max,
        required: dto.required,
        regex: new RegExp(dto.pattern, dto.flags)
    }
}

const serverRuleRaw = window.FORM_RULES || {};

export const rules: Record<BookingField, Rule> = {
    schoolnaam: compileRule(serverRuleRaw.schoolnaam)
}




export function validateField(field: BookingField, value: string): ValidationShape {
    const rule = rules[field];
    const v = (value ?? "").trim();

    const regex5digits = /\d{4,}/

    if (rule.required && v.length === 0){
        return { warning: "Dit veld mag niet leeg blijven"};
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
        return {error: "De naam van de school mag alleen letters, cijfers en punten of koppeltekens bevatten"};

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
import type RULES from "../../types/global"

import {
    bookingFieldNames,
    cjpFields,
} from "./../booking/BookingFieldConstants.ts"


import { 
    BookingFormValues,
    isPhoneBookingField,
    isCountryDependentField,
} from "../booking/BookingFields.ts"

import type { 
    BookingField, 
    CountryCode,
    CountryDependentField,
    NonEmptyStringArray,
    CJPFields
} from "../../types/booking/BookingFieldTypes.ts";

import { type ValidationShape } from "../../types/validation/FieldErrorTypes.ts";

import type { ValidatorName } from "../../types/validation/ValidationTypes.ts";

import { fetchFormValidationRules } from "../../services/api/formValidationRulesApi.ts";

const serverRuleRaw = await fetchFormValidationRules() as FrontendFormRules;

const cjpFieldSet: ReadonlySet<BookingField> = new Set(cjpFields);

type CountryCodeParameter = CountryCode | undefined;

export type FieldError = Partial<Record<BookingField, string>>;

export type ValidationRulesByBookingField = Exclude<
    BookingField,
    CountryDependentField | "bezoekdatum" 
>;

type RuleValidatedField = Exclude<
  BookingField,
  "bezoekdatum" | "hoeKentUGeoFort"
>;


export type Rule = {
    min: number;
    max: number;
    required: boolean;
    regex: RegExp;
    validator?: ValidatorName
    minDigits?: number;
    maxDigits?: number;
    customMin?: number;
    customMax?: number;
    allowedValues?: readonly string[];
    otherOption?: string;

};


export type RawRuleDto = {
    min: number;
    max: number;
    required: boolean;
    pattern?: string;
    flags?: string;
    validator?: ValidatorName;
    minDigits?: number;
    maxDigits?: number;
    customMin?: number;
    customMax?: number;
    allowedValues?: readonly string[];
    otherOption?: string;

}

export type GeoFortDiscoveryRule = Rule & {
    allowedValues: NonEmptyStringArray;
    otherOption: string;
};




const validatorRegexes: Record<ValidatorName, RegExp> = {
    email: /^[^\s@]+@[^\s@]+\.[^\s@]+$/,
}

export type FrontendFormRules = Record<
    ValidationRulesByBookingField,
    RawRuleDto
> & {
    postcode: Record<CountryCode, RawRuleDto>,
    schoolTelefoonnummer: Record<CountryCode, RawRuleDto>;
    contactpersoonTelefoonnummer: Record<CountryCode, RawRuleDto>;
};

type CompiledRules = Omit<
    Record<ValidationRulesByBookingField, Rule>,
    "hoeKentUGeoFort"
> & {
    postcode: Record<CountryCode, Rule>;
    schoolTelefoonnummer: Record<CountryCode, Rule>;
    contactpersoonTelefoonnummer: Record<CountryCode, Rule>;
    hoeKentUGeoFort: GeoFortDiscoveryRule;
};

type InvalidPatternMessages = {
    [k in ValidationRulesByBookingField]: string;
} & {
    postcode: Record<CountryCode, string>;
    schoolTelefoonnummer: Record<CountryCode, string>;
    contactpersoonTelefoonnummer: Record<CountryCode, string>;
};

function compileRegex(field: Exclude<BookingField, "bezoekdatum">, raw: RawRuleDto): RegExp {
    if (raw.pattern) {
        return new RegExp(raw.pattern, raw.flags);
    }

    if (!raw.validator) {
        throw new Error(`Validation rule for ${field} must have either pattern or validator`)
    }

    const regex = validatorRegexes[raw.validator];

    if (!regex) {
        throw new Error(
            `Unsupported validator "${raw.validator}" for field: ${field}`
        )
    }

    return regex
};

function isObject(value: any): value is Record<string, any> {
    return typeof value === "object" && value !== null;
}

function compileGeoFortDiscoveryRule(dto: unknown): GeoFortDiscoveryRule {
  const rule = compileRule("hoeKentUGeoFort", dto);

  if (
    !Array.isArray(rule.allowedValues) ||
    rule.allowedValues.length === 0 ||
    !rule.allowedValues.every((value) => typeof value === "string")
  ) {
    throw new Error(
      "hoeKentUGeoFort.allowedValues moet een niet-lege string array zijn",
    );
  }

  if (typeof rule.otherOption !== "string" || rule.otherOption.length === 0) {
    throw new Error("hoeKentUGeoFort.otherOption moet een string zijn");
  }

  if (!rule.allowedValues.includes(rule.otherOption)) {
    throw new Error(
      `hoeKentUGeoFort.allowedValues moet "${rule.otherOption}" bevatten`,
    );
  }

  return {
    ...rule,
    allowedValues: rule.allowedValues as NonEmptyStringArray,
    otherOption: rule.otherOption,
  };
}

function compileRule(
    field: Exclude<BookingField, "bezoekdatum">,  
    dto: any, 
    country: CountryCodeParameter = undefined
): Rule {
    if (!isObject(dto)) throw new Error(`Validation rule missing for field: ${field}`);

    let countryText: string = "";
    const raw = dto as RawRuleDto;
 
    if (isCountryDependentField(field) && country !== undefined) {
        countryText = `(${country})`
    }
        

    if (typeof raw.min !== "number") {
        throw new Error(`Validation rule min missing for field: ${field}${countryText}`);
    }

    if (typeof raw.max !== "number") {
        throw new Error(`Validation rule max missing for field: ${field}${countryText}`);
    }

    if (typeof raw.required !== "boolean") {
        throw new Error(`Validation rule required missing for field: ${field}${countryText}`);
    }

    if ("minDigits" in raw && typeof raw.minDigits !== "number") {
        throw new Error(
            `Validation rule minDigits must be a number for field: ${field}${countryText}`,
        );
    }

    if ("maxDigits" in raw && typeof raw.maxDigits !== "number") {
        throw new Error(
            `Validation rule maxDigits must be a number for field: ${field}${countryText}`,
        );
    }

    const regex = compileRegex(field, raw);

    const rule: Rule = {
        min: raw.min,
        max: raw.max,
        required: raw.required,
        regex,
    }

    if ("minDigits" in raw) {
        rule.minDigits = raw.minDigits;
    }

    if ("maxDigits" in raw) {
        rule.maxDigits = raw.maxDigits;
    }

    if ("customMin" in raw) {
        if (typeof raw.customMin !== "number") {
            throw new Error(
                `Validation rule customMin must be a number for field: ${field}${countryText}`,
            );
        }

        rule.customMin = raw.customMin;
    }

    if ("customMax" in raw) {
        if (typeof raw.customMax !== "number") {
            throw new Error(
                `Validation rule customMax must be a number for field: ${field}${countryText}`,
            );
        }

        rule.customMax = raw.customMax;
    }

    if ("allowedValues" in raw) {
        if (
        !Array.isArray(raw.allowedValues) ||
        !raw.allowedValues.every((value) => typeof value === "string")
        ) {
            throw new Error(
                `Validation rule allowedValues must be a string array for field: ${field}${countryText}`,
            );
        }

        rule.allowedValues = raw.allowedValues;
    }

    if ("otherOption" in raw) {
        if (typeof raw.otherOption !== "string") {
            throw new Error(
            `Validation rule otherOption must be a string for field: ${field}${countryText}`,
            );
        }

        rule.otherOption = raw.otherOption;
    }

    return rule
}

function compileRules(): CompiledRules {
  return {
    schoolnaam: compileRule("schoolnaam", serverRuleRaw.schoolnaam),
    land: compileRule("land", serverRuleRaw.land),
    adres: compileRule("adres", serverRuleRaw.adres),
    plaats: compileRule("plaats", serverRuleRaw.plaats),
    postcode: {
      Nederland: compileRule(
        "postcode",
        serverRuleRaw.postcode?.Nederland,
        "Nederland"
      ),
      België: compileRule("postcode", serverRuleRaw.postcode?.België, "België"),
    },
    schoolTelefoonnummer: {
        Nederland: compileRule(
            "schoolTelefoonnummer",
            serverRuleRaw.schoolTelefoonnummer?.Nederland,
            "Nederland"
        ),
        België: compileRule(
            "schoolTelefoonnummer",
            serverRuleRaw.schoolTelefoonnummer?.België,
            "België"
        )
    },
    contactpersoonTelefoonnummer: {
        Nederland: compileRule(
            "contactpersoonTelefoonnummer",
            serverRuleRaw.contactpersoonTelefoonnummer?.Nederland,
            "Nederland"
        ),
        België: compileRule(
            "contactpersoonTelefoonnummer",
            serverRuleRaw.contactpersoonTelefoonnummer?.België,
            "België"
        )
    },
    contactpersoonVoornaam: compileRule("contactpersoonVoornaam", serverRuleRaw.contactpersoonVoornaam),
    contactpersoonAchternaam: compileRule("contactpersoonAchternaam", serverRuleRaw.contactpersoonAchternaam),
    email: compileRule("email", serverRuleRaw.email),
    hoeKentUGeoFort: compileGeoFortDiscoveryRule(serverRuleRaw.hoeKentUGeoFort),
    cjpPasGebruik: compileRule("cjpPasGebruik", serverRuleRaw.cjpPasGebruik),
    cjpContactpersoonNaam: compileRule("cjpContactpersoonNaam", serverRuleRaw.cjpContactpersoonNaam),
    cjpPasnummer: compileRule("cjpPasnummer", serverRuleRaw.cjpPasnummer),
    onderwijsSector: compileRule("onderwijsSector", serverRuleRaw.onderwijsSector),
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
            Nederland: "Gebruik een geldig Nederlands nummer, bijvoorbeeld: 06 12345678 of +31 612345678",
            België: "Gebruik een geldig Belgisch mobiel of vast nummer, bijvoorbeeld: +32 4 12 34 56 78."
        },
    contactpersoonTelefoonnummer:
        {
            Nederland:"Gebruik een geldig mobiel nummer (06), bijvoorbeeld 06 12345678 of +31 6 12345678.",
            België:"Gebruik een geldig mobiel nummer (04xx), bijvoorbeeld 0471 12 34 56 of +32 471 12 34 56.",
        },
    contactpersoonVoornaam: "De voornaam bevat ongeldige tekens.",
    contactpersoonAchternaam: "De achternaam bevat ongeldige tekens.",
    email: "Ongeldige email doorgegeven",
    hoeKentUGeoFort: "Gebruik alleen letters, cijfers, spaties en eenvoudige leestekens.",
    cjpPasGebruik: "Kies of uw school gebruikmaakt van CJP-korting.",
    cjpContactpersoonNaam:"De naam van de CJP-contactpersoon bevat ongeldige tekens.",
    cjpPasnummer: "Het CJP-pasnummer moet uit 8 of 9 cijfers bestaan.",
    onderwijsSector: "Kies een geldige onderwijssector uit de lijst"
    
 
};

const requiredMessages: Record<Exclude<BookingField, "bezoekdatum" >, string> = {
    schoolnaam: "Vul de naam van de school in.",
    land: "Vul het land in.",
    postcode: "Vul de postcode in van de school.",
    adres: "Vul het adres van de school in.",
    plaats: "Vul de plaats van de school in",
    schoolTelefoonnummer: "Vul het telefoonnummer van de school in",
    contactpersoonTelefoonnummer: "vul het nnummer van de contactpersoon in",
    contactpersoonVoornaam: "Vul de voornaam van de contactpersoon in",
    contactpersoonAchternaam: "Vul de achternaam van de contactpersoon in.",
    email: "Vul het e-mailadres in",
    hoeKentUGeoFort: "Maak een selectie of vul in hoe u GeoFort kent.",
    cjpPasGebruik: "Kies of uw school gebruikmaakt van CJP-korting.",
    cjpContactpersoonNaam:"Vul de naam van de CJP-contactpersoon in.",
    cjpPasnummer: "Vul het CJP-pasnummer in.",
    onderwijsSector: "De onderwijssector die van toepassing is moet gekozen worden."
}


function getRuleFromField(
    field: Exclude<BookingField, "bezoekdatum">, 
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
    field: Exclude<BookingField, "bezoekdatum">, 
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

export function validateVisitDate(value: string): ValidationShape {
  const raw = value.trim();

  if (raw.length === 0) {
    return {
      error: "Kies een bezoekdatum.",
    };
  }

  if (!/^\d{4}-\d{2}-\d{2}$/.test(raw)) {
    return {
      error: "Ongeldige bezoekdatum.",
    };
  }

  const date = new Date(`${raw}T00:00:00`);
  const today = new Date();

  today.setHours(0, 0, 0, 0);

  if (Number.isNaN(date.getTime())) {
    return {
      error: "Ongeldige bezoekdatum.",
    };
  }

  if (date < today) {
    return {
      error: "Kies geen datum in het verleden.",
    };
  }

  const day = date.getDay();

  if (day === 0 || day === 6) {
    return {
      error: "In het weekend zijn geen onderwijsbezoeken mogelijk.",
    };
  }

  return {};
}

export function validateGeoFortDiscovery(
  value: string,
  rule: GeoFortDiscoveryRule,
): ValidationShape {
  const raw = normalizeGeoFortDiscovery(value);

  if (raw.length === 0) {
    if (rule.required) {
      return {
        warning: requiredMessages.hoeKentUGeoFort,
      };
    }

    return {};
  }

  const { allowedValues, otherOption } = rule;

  if (allowedValues.includes(raw)) {
    return {};
  }
  
  // include : for exact match with prefix
  const customPrefix = `${otherOption}:`;

  if (!raw.startsWith(customPrefix)) {
    return {
      error: "Kies een optie uit de lijst of gebruik Anders / onbekend.",
    };
  }

  const customMin = rule.customMin ?? 2;
  const customMax = rule.customMax ?? 80;

  const customText = normalizeGeoFortDiscovery(
    raw.slice(customPrefix.length),
  );

  if (customText.length === 0) {
    return {};
  }

  if (customText.length < customMin) {
    return {
      error: `De toelichting moet minimaal ${customMin} tekens bevatten.`,
    };
  }

  if (customText.length > customMax) {
    return {
      error: `De toelichting mag maximaal ${customMax} tekens bevatten.`,
    };
  }

  rule.regex.lastIndex = 0;

  if (!rule.regex.test(customText)) {
    return {
      error: invalidPatternMessages.hoeKentUGeoFort,
    };
  }

  return {};
}

function validateFieldByRule(
  field: RuleValidatedField,
  value: string,
  values: BookingFormValues,
): ValidationShape {
  const v = (value ?? "").trim();

  if (isCountryDependentField(field) && !isCountryCode(values.land)) {
    return {
      error: "Kies een geldig land",
    };
  }

  const rule = getRuleFromField(field, values);

  if (rule.required && v.length === 0) {
    return {
      warning: requiredMessages[field],
    };
  }

  if (v.length > 0 && v.length < rule.min) {
    return {
      error: `Beschrijf dit veld met minimaal ${rule.min} tekens`,
    };
  }

  if (v.length > rule.max) {
    return {
      error: `Maximaal ${rule.max} tekens`,
    };
  }

  if (v.length > 0 && isPhoneBookingField(field)) {
    const digitCount = countPhoneDigits(value);
    const countryText =
      values.land === "Nederland" ? "Nederlandse" : "Belgische";

    if (rule.minDigits !== undefined && digitCount < rule.minDigits) {
      return {
        error: `Het ${countryText} nummer moet minimaal ${rule.minDigits} cijfers bevatten`,
      };
    }

    if (rule.maxDigits !== undefined && digitCount > rule.maxDigits) {
      return {
        error: `Het ${countryText} nummer mag maximaal ${rule.maxDigits} cijfers bevatten`,
      };
    }
  }

  rule.regex.lastIndex = 0;

  if (v.length > 0 && !rule.regex.test(v)) {
    return {
      error: getInvalidPatternMessage(field, values),
    };
  }

  return {};
}


function validateCJPField(
  field: CJPFields,
  value: string,
  values: BookingFormValues,
): ValidationShape {
  if (field === "cjpPasGebruik") {
    return validateFieldByRule(field, value, values);
  }

  const cjpUsageValidation = validateFieldByRule(
    "cjpPasGebruik",
    values.cjpPasGebruik ?? "",
    values,
  );

  if (cjpUsageValidation.error || cjpUsageValidation.warning) {
    return {};
  }

  const usesCjpDiscount = values.cjpPasGebruik === "ja";
  const v = (value ?? "").trim();

  if (!usesCjpDiscount) {
    if (v.length > 0) {
      return {
        error:
          "CJP-gegevens mogen alleen ingevuld zijn als CJP-korting op ja staat.",
      };
    }

    return {};
  }

  return validateFieldByRule(field, value, values);
}

export function validateField(
  field: BookingField,
  value: string,
  values: BookingFormValues,
): ValidationShape {
  if (field === "bezoekdatum") {
    return validateVisitDate(value);
  }

  if (field === "hoeKentUGeoFort") {
    return validateGeoFortDiscovery(value, rules.hoeKentUGeoFort);
  }

  if (isCJPField(field)) {
    return validateCJPField(field, value, values);
  }

  return validateFieldByRule(field, value, values);
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
        if (!firstError && (result.error || result.warning)){
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

export function normalizeEmail(value: string): string {
    return (value ?? "")
        .trim()
        .replace(/\u00a0/g, " ");
}

export function normalizeGeoFortDiscovery(value: string): string {
    return (value ?? "")
        .trim()
        .replace(/\u00a0/g, " ")
        .replace(/\s+/g, " ");
}

export function normalizeCjpPersonName(value: string): string {
  return value.trim().replace(/\u00a0/g, " ").replace(/\s+/g, " ");
}

export function normalizeCjpPasnumber(value: string): string {
  return value.trim().replace(/\s+/g, "");
}


export function countPhoneDigits(value: string): number {
    return (value.match(/\d/g) ?? []).length;
}

export function isGeoFortDiscoveryOption(value: string): boolean {
  return rules.hoeKentUGeoFort.allowedValues.includes(value);
}

export function isCJPField(field: BookingField): field is CJPFields {
    return cjpFieldSet.has(field);
}




export const geofortDiscoveryOptions = rules.hoeKentUGeoFort.allowedValues;
export const otherOption = rules.hoeKentUGeoFort.otherOption;

export const geofortDiscoverySelectOptions: ReadonlyArray<{
    value: string,
    label: string
}> = geofortDiscoveryOptions.map((option) => ({
    value: option,
    label: option
}));


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
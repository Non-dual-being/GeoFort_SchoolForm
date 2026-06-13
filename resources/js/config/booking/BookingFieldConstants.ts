export const basisFieldNames = [
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
  "bezoekdatum",
  "hoeKentUGeoFort",
  "cjpPasGebruik",
  "cjpContactpersoonNaam",
  "cjpPasnummer",
] as const;

export const programFieldNames = [
  "onderwijsSector",
] as const;

export const bookingFieldNames = [
  ...basisFieldNames,
  ...programFieldNames,
] as const;

export const phoneFieldNames = [
  "schoolTelefoonnummer",
  "contactpersoonTelefoonnummer",
] as const;

export const countryDependentFields = [
  "postcode",
  "schoolTelefoonnummer",
  "contactpersoonTelefoonnummer",
] as const;

export const cjpUsageOptions: ReadonlyArray<{
    value: "nee" | "ja",
    label: string;
    description?: string;
}> = [
      {
    value: "nee",
    label: "Nee",
    description: "Wij gebruiken geen CJP-korting.",
  },
  {
    value: "ja",
    label: "Ja",
    description: "Wij willen gebruikmaken van CJP-korting.",
  },
] as const;

export const cjpFields = [
    "cjpContactpersoonNaam",
    "cjpPasnummer",
    "cjpPasGebruik"
] as const;



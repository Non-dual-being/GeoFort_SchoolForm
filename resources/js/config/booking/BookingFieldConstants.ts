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
  "bezoekdatum",
  "hoeKentUGeoFort",
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


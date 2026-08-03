export type BookingPriceLineDto = {
  key: string;
  label: string;
  quantity: number;
  unitPriceInclVatCents: number;
  totalInclVatCents: number;
};

export type BookingPriceVisitDto = {
  studentCount: number;
  supervisorCount: number;
  freeSupervisors: number;
  paidSupervisors: number;
  pricePerVisitorInclVatCents: number;
  lines: BookingPriceLineDto[];
  amountInclVatCents: number;
  amountExclVatCents: number;
};

export type BookingPriceFoodAndDrinkDto = {
  lines: BookingPriceLineDto[];
  amountInclVatCents: number;
  amountExclVatCents: number;
};

export type BookingPriceTotalDto = {
  amountInclVatCents: number;
  amountExclVatCents: number;
  vatAmountCents: number;
};

export type BookingPriceQuoteDto = {
  calculationState: "complete";
  pricingVersion: string;
  currencyCode: "EUR";
  vatBasisPoints: number;
  visit: BookingPriceVisitDto;
  foodAndDrink: BookingPriceFoodAndDrinkDto;
  total: BookingPriceTotalDto;
};

export type BookingPriceQuoteRequest = {
  bezoekdatum: string;
  onderwijsSector: string;
  programma: string;
  aantalLeerlingen: string;
  aantalBegeleiders: string;
  remiseBreak: string;
  kazerneBreak: string;
  fortgrachtBreak: string;
  waterijsje: string;
  glasLimonade: string;
  lunchChoice: "" | "remise_lunch" | "eigen_picknick";
  remiseLunch: string;
};

export type BookingPriceQuoteApiResponse = {
  ok: boolean;
  data?: BookingPriceQuoteDto;
};

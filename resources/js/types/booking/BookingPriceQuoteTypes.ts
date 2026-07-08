export type BookingPriceLineDto = {
  key: string;
  label: string;
  quantity: number;
  unitPriceInclVat: number;
  totalInclVat: number;
};

export type BookingPriceVisitDto = {
  studentCount: number;
  supervisorCount: number;
  freeSupervisors: number;
  paidSupervisors: number;
  pricePerVisitorInclVat: number;
  lines: BookingPriceLineDto[];
  totalInclVat: number;
  totalExclVat: number;
};

export type BookingPriceFoodAndDrinkDto = {
  lines: BookingPriceLineDto[];
  totalInclVat: number;
  totalExclVat: number;
};

export type BookingPriceTotalDto = {
  totalInclVat: number;
  totalExclVat: number;
};

export type BookingPriceQuoteDto = {
  vatPercentage: number;
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

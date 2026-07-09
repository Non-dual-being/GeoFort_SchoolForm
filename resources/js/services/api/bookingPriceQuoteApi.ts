import type {
  BookingPriceQuoteDto,
  BookingPriceQuoteRequest,
} from "../../types/booking/BookingPriceQuoteTypes";

import { getApiData } from "../http/apiClient";

const BOOKING_PRICE_QUOTE_URL = "/booking/getBookingPriceQuote.php";

export async function fetchBookingPriceQuote(
  request: BookingPriceQuoteRequest,
  signal?: AbortSignal,
): Promise<BookingPriceQuoteDto> {
  const formData = new FormData();

  formData.append("bezoekdatum", request.bezoekdatum);
  formData.append("onderwijsSector", request.onderwijsSector);
  formData.append("programma", request.programma);
  formData.append("aantalLeerlingen", request.aantalLeerlingen);
  formData.append("aantalBegeleiders", request.aantalBegeleiders);
  formData.append("remiseBreak", request.remiseBreak);
  formData.append("kazerneBreak", request.kazerneBreak);
  formData.append("fortgrachtBreak", request.fortgrachtBreak);
  formData.append("waterijsje", request.waterijsje);
  formData.append("glasLimonade", request.glasLimonade);
  formData.append("lunchChoice", request.lunchChoice);
  formData.append("remiseLunch", request.remiseLunch);

  return getApiData<BookingPriceQuoteDto>(BOOKING_PRICE_QUOTE_URL, {
    method: "POST",
    body: formData,
    signal,
  });
}

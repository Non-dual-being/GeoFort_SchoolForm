import type {
  BookingRosterRequest,
  BookingRosterResult,
} from "../../types/booking/BookingRosterTypes";

import { getApiData } from "../http/apiClient";

const BOOKING_ROSTER_URL = "/booking/getBookingRoster.php";

export async function fetchBookingRoster(
  request: BookingRosterRequest,
  signal?: AbortSignal,
): Promise<BookingRosterResult> {
  const formData = new FormData();

  formData.append("bezoekdatum", request.bezoekdatum);
  formData.append("onderwijsSector", request.onderwijsSector);
  formData.append("programma", request.programma);
  formData.append(
    "educationSelection",
    JSON.stringify(request.educationSelection),
  );
  formData.append("keuzemodule", request.keuzemodule);
  formData.append("aantalLeerlingen", request.aantalLeerlingen);
  formData.append("aantalBegeleiders", request.aantalBegeleiders);

  return getApiData<BookingRosterResult>(BOOKING_ROSTER_URL, {
    method: "POST",
    body: formData,
    signal,
  });
}

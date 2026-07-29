import type { fullDatesInfo } from "../../types/booking/BookingDateType";
import { getApiData } from "../http/apiClient";

let disabledDatesPromise: Promise<fullDatesInfo> | null = null;

/**
 * Deelt uitsluitend een gelijktijdig lopend request. Resultaten worden bewust
 * niet gecachet, zodat iedere nieuwe publieke kalenderinstantie de actuele
 * autoritatieve disabled_dates-bron leest.
 */
export async function fetchDisabledDates(): Promise<fullDatesInfo> {
  if (disabledDatesPromise) return disabledDatesPromise;
  disabledDatesPromise = getApiData<fullDatesInfo>("api/getDisabledDates.php", {
    method: "GET",
  }).finally(() => {
    disabledDatesPromise = null;
  });
  return disabledDatesPromise;
}

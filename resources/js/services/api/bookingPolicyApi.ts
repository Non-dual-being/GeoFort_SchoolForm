import type { BoekingBeleidApiResponse } from "../../types/booking/BookingPolicyTypes";
import { getApiData } from "../http/apiClient";

let configValuesPromise: Promise<BoekingBeleidApiResponse> | null = null;
let configValuesCache: BoekingBeleidApiResponse | null = null;
let configValuesCacheTime: number = 0;

const CACHE_TTL_MS = 15 * 60 * 1000; // 15 min

export async function fetchBookingPolicyRules(): Promise<BoekingBeleidApiResponse> {
    const now = Date.now();
    if (configValuesCache && ((now - configValuesCacheTime) < CACHE_TTL_MS))
        return configValuesCache;

    if (configValuesPromise) return configValuesPromise;

    configValuesPromise = getApiData<BoekingBeleidApiResponse>(
    "/api/getBookingConfigValues.php", {
        method: "GET"})
        .then((data) => {
            configValuesCache = data;
            configValuesCacheTime = Date.now();
            return data;
        })
        .finally(() => {
            configValuesPromise = null;
        })

   return configValuesPromise;
}
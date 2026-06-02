import type { BookingProgramConfigData } from "../../types/booking/BookingProgramConfigTypes";
import { getApiData } from "../http/apiClient";

let bookingProgramConfigPromise: Promise<BookingProgramConfigData> | null = null;
let bookingProgramConfigCache: BookingProgramConfigData | null = null;
let bookingProgramConfigCacheTime: number = 0;

const CACHE_TTL_MS = 2 * 60 * 60 * 1000; // 2 uur;

export async function fetchBookingProgramConfigValues(): Promise<BookingProgramConfigData> {
    const now = Date.now();
    const url = "/api/getBookingProgramConfig.php"
    if (bookingProgramConfigCache && ((now - bookingProgramConfigCacheTime) < CACHE_TTL_MS))
        return bookingProgramConfigCache;
    
    if (bookingProgramConfigPromise) 
        return bookingProgramConfigPromise;
    bookingProgramConfigPromise = getApiData<BookingProgramConfigData>(
        url,
        { method: "GET" })
    .then((data) => {
        bookingProgramConfigCache = data;
        bookingProgramConfigCacheTime = Date.now();
        return data
    }).finally(() => {
        bookingProgramConfigPromise = null;
    });

    return bookingProgramConfigPromise;

}
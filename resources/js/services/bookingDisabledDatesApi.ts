import type { fullDatesInfo } from "../types/booking/BookingDateType";
import type { ApiDataFetch } from "../types/http/ApiResponse";

export async function fetchDisabledDates(): Promise<fullDatesInfo> {
    const response = await fetch("/api/getDisabledDates.php", {
        method: "GET",
        headers: {
            Accept: "application/json",
        },
    })

    if (!response.ok) {
        throw new Error("Geblokkeerde datums konden niet worden opgehaald");
    }

    const body = await response.json() as ApiDataFetch<fullDatesInfo>;


    if (!body.ok) throw new Error("Geblokkeerde datums konden niet worden opgehaald");

    return body.data as fullDatesInfo
}
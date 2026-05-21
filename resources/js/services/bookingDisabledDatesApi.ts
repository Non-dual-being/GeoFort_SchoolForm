import type { DisabledDatesApiResponse } from "../types/booking/BookingDateType";

export async function fetchDisabledDates(): Promise<DisabledDatesApiResponse> {
    const response = await fetch("/api/disabledDates.php", {
        method: "GET",
        headers: {
            Accept: "Application/json",
        },
    })

    if (!response.ok) {
        throw new Error("Geblokkeerde datums konden niet worden opgehaald");
    }

    return (await response.json()) as DisabledDatesApiResponse;
}
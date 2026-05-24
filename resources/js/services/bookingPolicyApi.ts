import type { BoekingBeleidApiResponse } from "../types/booking/BookingPolicyTypes";
import type { ApiDataFetch } from "../types/http/ApiResponse";

export async function fetchBookingPolicyRules(): Promise<BoekingBeleidApiResponse> {
    const response = await fetch("/api/getBookingConfigValues.php", {
        method: "GET",
        headers: {
            Accept: "Application/json",
        },
    }) 

    if (!response.ok) {
        throw new Error("Validatie regels kunnen niet worden opgehaald");
    }

    const body = await response.json() as ApiDataFetch<BoekingBeleidApiResponse>;

    if (!body.ok) throw new Error("Validatie regels kunnen niet worden opgehaald");

    return body.data
}
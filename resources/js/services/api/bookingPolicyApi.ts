import type { BoekingBeleidApiResponse } from "../../types/booking/BookingPolicyTypes";
import { getApiData } from "../http/apiClient";

export async function fetchBookingPolicyRules(): Promise<BoekingBeleidApiResponse> {
   return getApiData<BoekingBeleidApiResponse>(
    "/api/getBookingConfigValues.php",
    {
        method: "GET"
    }
   )
}
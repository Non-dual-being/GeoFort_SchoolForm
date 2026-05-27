import type { fullDatesInfo } from "../../types/booking/BookingDateType";
import { getApiData } from "../http/apiClient";

export async function fetchDisabledDates(): Promise<fullDatesInfo> {
    return getApiData<fullDatesInfo>(
        "api/getDisabledDates.php",
        {
            method: "GET"
        }
    )
}
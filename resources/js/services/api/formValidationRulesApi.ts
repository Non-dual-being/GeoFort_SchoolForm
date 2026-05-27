
import type { FrontendFormRules } from "./../../config/validation/booking";
import { getApiData } from "../http/apiClient";

export function fetchFormValidationRules(): Promise<FrontendFormRules> {
    return getApiData<FrontendFormRules>(
        "/api/getFormValidationRules.php",
        {
            method: "GET"
        }
    )
}
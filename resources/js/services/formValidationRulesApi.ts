
import type { FrontendFormRules } from "../config/validation/booking";
import type { ApiDataFetch } from "../types/http/ApiResponse";

export async function fetchFormValidationRules(): Promise<FrontendFormRules> {
    const response = await fetch("/api/getFormValidationRules.php", {
        method: "GET",
        headers: {
            Accept: "Application/json",
        },
    }) 

    if (!response.ok) {
        throw new Error("Validatie regels kunnen niet worden opgehaald");
    }

    const body = await response.json() as ApiDataFetch<FrontendFormRules>;

    if (!body.ok) throw new Error("Validatie regels kunnen niet worden opgehaald");

    return body.data
}
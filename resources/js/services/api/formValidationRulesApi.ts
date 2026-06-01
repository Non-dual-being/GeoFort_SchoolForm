import type { FrontendFormRules } from "./../../config/validation/booking";
import { getApiData } from "../http/apiClient";

let promise: Promise<FrontendFormRules> | null = null;
let cache: FrontendFormRules | null = null;
let cacheTime: number = 0;

const CACHE_TTL_TIME_MS = 15 * 60 * 1000;

export async function fetchFormValidationRules(): Promise<FrontendFormRules> {
    const now = Date.now();

    if (cache && ((now - cacheTime) < CACHE_TTL_TIME_MS))
        return cache;

    if (promise) return promise;

    promise = getApiData<FrontendFormRules>(
        "/api/getFormValidationRules.php", {
            method: "GET"})
        .then((data) => {
            cache = data;
            cacheTime = Date.now();
            return data;
        }).finally(() => {
            promise = null;
        })
    
    return promise;
}
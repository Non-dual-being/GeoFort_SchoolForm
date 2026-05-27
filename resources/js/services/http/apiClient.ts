import { json } from "stream/consumers";
import type { ApiDataFetch } from "../../types/http/ApiResponse";

export class ApiError extends Error {
    constructor(
        message: string,
        public readonly status: number,
    ) {
        super(message);
        this.name = "ApiError";
    }
}

export async function getApiData<T>(
    url: string,
    init: RequestInit = {}
): Promise<T> {
    const headers = new Headers(init.headers);

    if (!headers.has("Accept")) headers.set("Accept", "application/json");

    const response = await fetch(url, {
        ...init,
        headers
    })

    const body = await readJson<ApiDataFetch<T>>(response);

    if (!response.ok) throw new ApiError(
        "Ongeldige server response", 
        response.status
    )

    if (!body) throw new ApiError(
        "Ongeldige server response",
        response.status
    )

    if (!body.ok) throw new ApiError(
        getBodyMessage(body),
        response.status
    )

    return body.data;
}

async function readJson<T>(response: Response): Promise<T | null> {
    const text = await response.text();

    if (!text) return null;

    try {
        return JSON.parse(text) as T;

    } catch {
        return null
    }
}

function getBodyMessage(body: unknown): string {
    if ( body 
            && typeof body === "object" 
            && "message" in body
            && typeof body.message === "string"
    ) return body.message

    return "Request mislukt";
}
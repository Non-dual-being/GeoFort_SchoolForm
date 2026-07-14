import type { BookingField } from  "./../../types/booking/BookingFieldTypes.ts"

export type ApiOk = { ok: true };

export type ApiDataFetch<T> = {
    ok: boolean,
    data: T
};

export type ApiValidationError = {
    ok: false,
    type: "validation";
    fieldErrors: Partial<Record<BookingField, string>>;
}

export type ApiServerError = {
    ok: false;
    type: "server";
    code: number;
}

export type ApiRateLimitError = {
    ok: false;
    type: "rate-limit";
    retryAfter: number;
}

export type ApiResponse = 
 | ApiOk 
 | ApiValidationError
 | ApiServerError
 | ApiRateLimitError;



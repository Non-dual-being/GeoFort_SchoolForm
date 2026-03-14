import type { BookingField } from  "./../form/shared.ts"

export type ApiOk = { ok: true };

export type ApiValidationError = {
    ok: false;
    type: "validation";
    fieldErrors: Partial<Record<BookingField, string>>
}

export type ApiServerError = {
    ok: false;
    type: "server";
    code: number;
}

export type ApiResponse = ApiOk |  ApiValidationError | ApiServerError;


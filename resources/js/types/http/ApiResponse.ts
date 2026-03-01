import type { BookingField } from  "./../form/shared.ts"

export type ApiOk = { oke: true; redirectTo: string };

export type ApiValidationError = {
    ok: false;
    type: "validation";
    fieldErrors: Partial<Record<BookingField, string>>
}

export type ApiServerError = {
    ok: false;
    type: "server";
    code: number;
    redirectTo: string;
}

export type ApiResponse = ApiOk |  ApiValidationError | ApiServerError;


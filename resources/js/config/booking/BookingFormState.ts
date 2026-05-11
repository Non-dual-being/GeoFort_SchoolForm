import { bookingFieldNames } from "./BookingFields";
import type { BookingField, InputFieldInstance, FieldInstance } from "../../types/booking/BookingFieldTypes.ts";
import type { ValidationShape } from "./../../types/validation/FieldErrorTypes.ts"

export function createInitialIssues(): Record<BookingField, ValidationShape> {
    return Object.fromEntries(
        bookingFieldNames.map((field) => [field, {}])
    ) as Record<BookingField, ValidationShape>
}

export function createInitialFlashTriggers(): Record<BookingField, number> {
    return Object.fromEntries(
        bookingFieldNames.map((field) => [field, 0])
    ) as Record<BookingField, number>
}

export function createInitialFieldRefs(): Record<
    BookingField,
    FieldInstance | null
> {
    return Object.fromEntries(
        bookingFieldNames.map((field) => [
            field,
            null
        ])
    ) as Record<BookingField, FieldInstance | null>
}
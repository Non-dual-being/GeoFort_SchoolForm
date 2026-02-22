import { ref, type Ref } from "vue";
import type { BookingField, SubmitResult, FieldError } from "./../validation/booking";

const SUBMIT_URL = "./../../booking/validatie.php";
const SLOW_TRESHOLD_MS = 4000;

export type SubmitState = "idle" | "pending" | "slow" | "error" | "success";
type serverError = string | null;

export type UseFormSubmitReturn = {
    state: Ref<SubmitState>
    serverError: Ref<serverError>;
    submit: (formData: FormData) => Promise<SubmitResult>
    reset: () => void;
}

export function useFormSubmit(): UseFormSubmitReturn {
    const state = ref<SubmitState>("idle")
    const serverError = ref<serverError>(null);

    function reset(): void {
        state.value = "idle";
        serverError.value = null;
    }

    async function submit(formData: FormData): Promise<SubmitResult> {
        state.value = "pending";
        serverError.value = null;

        const slowTimer = setTimeout(() => {
            if (state.value === "pending") state.value = "slow";

        }, SLOW_TRESHOLD_MS);

        try {
            const response = await fetch(SUBMIT_URL, {
                method: "POST",
                body: formData
            });

            clearTimeout(slowTimer);

            if (!response.ok) {
                throw new Error("De aanvraag kan niet worden vewerkt door een netwerkprobleem")
            }

            const serverRes = await response.json();

            if (serverRes.success) {
                state.value = "success";
                return { ok: true }
            } 

            if (serverRes.errors) {
                state.value = "error"
                return {
                    ok: false,
                    fieldErrors: serverRes.errors as FieldError
                } 
            }

            if (serverRes.serverError) {
                throw new Error(serverRes.serverError ?? "De aanvraag kan niet worden vewerkt door een serverprobleem")
            }

            throw new Error("De aanvraag kan niet worden vewerkt door een onbekend probleem")

        } catch (err) {
            state.value = "error";
            const message = err instanceof Error 
                ? err.message ?? "Onbekende fout opgetreden"
                : "Er is een fout opgetreden bij het verwerken van de aanvraag!"
            serverError.value = message;
            return {
                ok: false,
                serverError: message
            }
        }
    }

    return { state, serverError, submit, reset}
}
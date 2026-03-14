import { ref, type Ref } from "vue";
import type {  SubmitResult } from "./../validation/booking";
import type { ApiResponse } from "./../types/http/ApiResponse";


const SUBMIT_URL = "./booking/validatie.php"; // GECORRIGEERD: relatief pad
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
                body: formData,
                headers: {
                    'X-Requested-With': 'XMLHttpRequest' // TOEGEVOEGD: voor PHP detectie
                }
            });

            clearTimeout(slowTimer);

            if (!response.ok) {
                throw new Error(`HTTP ${response.status}: De aanvraag kan niet worden verwerkt`);
            }

            const data = (await response.json()) as ApiResponse;

            // GECORRIGEERD: Betere response parsing
            if (data.ok === true) {
                state.value = "success";
                return { ok: true }
            } 


            // SCENARIO 2: VALIDATIE FOUTEN (PHP: type = "validation")
            if (!data.ok && data.type === "validation") {
                state.value = "idle"; // Terug naar idle zodat gebruiker kan typen
                return {
                    ok: false,
                    fieldErrors: data.fieldErrors,
                };
            }

            // SCENARIO 3: SERVER FOUT (PHP: type = "server")
            if (!data.ok && data.type === "server") {
                throw new Error("Het online GeoFort bookingsformulier loopt tegen een kritieke fout aan.");
            }

            throw new Error("Onverwachte server response");

        } catch (err) {
            clearTimeout(slowTimer);
            state.value = "error";
            
            const message: string = err instanceof Error 
                ? err.message 
                : "Er is een fout opgetreden bij het verwerken van de aanvraag!";
                
            serverError.value = message;
            return {
                ok: false,
                serverError: message
            }
        }
    }

    return { state, serverError, submit, reset}
}
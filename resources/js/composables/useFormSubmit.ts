import { ref, type Ref } from "vue";
import type { ApiResponse, ApiRateLimitError, ApiOk, ApiServerError } from "./../types/http/ApiResponse";

const SUBMIT_URL = "./booking/validatie.php"; // GECORRIGEERD: relatief pad
const SLOW_TRESHOLD_MS: number = 4000;

export type SubmitState = "idle" | "pending" | "slow" | "error" | "success";
type serverError = string | null;

export type UseFormSubmitReturn = {
    state: Ref<SubmitState>
    formError: Ref<serverError>;
    submit: (formData: FormData) => Promise<ApiResponse>
    reset: () => void;
    clearFormError: () => void;
}

export function useFormSubmit(): UseFormSubmitReturn {
    const state = ref<SubmitState>("idle")
    const formError = ref<serverError>(null);

    function reset(): void {
        state.value = "idle";
        formError.value = null;
    }

    function clearFormError(): void {
        formError.value = null;
    }


    async function submit(formData: FormData): Promise<ApiResponse> {
        state.value = "pending";
        formError.value = null;

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

            /**
             * validation response is ok or not ok
             * not ok consist of a validation error or a server error
             * Both are json
             */


            const data = (await response.json()) as ApiResponse;
            clearTimeout(slowTimer);

        
            // GECORRIGEERD: Betere response parsing
            if (data.ok === true) {
                state.value = "success";
                return data as ApiOk;
            } 

            switch (data.type) {
                case "validation":
                    state.value = "idle";
                    return data;
                
                case "rate-limit":
                    state.value = "idle";
                    formError.value = `Een nieuwe aanvraag opsturen kan na ${data.retryAfter} seconden`;
                    return data as ApiRateLimitError;

                case "server":
                    state.value = "error";
                    return data as ApiServerError;

                default:
                    throw new Error("Onverwachte server response");


            };

      
        } catch (err) {
            clearTimeout(slowTimer);
            state.value = "idle";
            /**
             * state is idle preveting the navigation to error page and ensure the flash trigger above btn
             */
            
            const message: string = err instanceof Error 
                ? err.message 
                : "Er is een fout opgetreden bij het verwerken van de aanvraag!";
                
            formError.value = message;

            const networkError: ApiServerError = {
                ok: false,
                code: 502,
                type: "server"
            }
            
            return networkError;
        }
    }

    return { state, formError, submit, reset, clearFormError}
}
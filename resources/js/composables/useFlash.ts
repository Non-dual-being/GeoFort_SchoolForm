import { ref, Ref } from 'vue';

type FlashType = "success" | "error";

export type UseFlashReturn = {
    flashMessage: Ref<string | null>;
    flashType: Ref<FlashType | null>;
    isVisible: Ref<boolean>
    showFlash: (message: string | null, type: FlashType | null, duration: number | null) => void;
    hideFlash: () => void;
}

const flashMessage = ref<string | null>(null);
const flashType = ref<'success' | 'error' | null>(null);
const isVisible = ref(false);

export function useFlash(): UseFlashReturn {
    const showFlash = (
        message: string | null, 
        type: FlashType | null = 'success',
        duration: number | null = 5000

    ) => {

        if (message === null) return;
        if (type === null) type = 'success';
        if (duration === null) duration = 5000;
        

        flashMessage.value = message;
        flashType.value = type;
        isVisible.value = true;

        if (duration > 0){
            setTimeout(() => {
                hideFlash();
            }, duration)
        }
    }

    const hideFlash = () => {
        isVisible.value = false;
        setTimeout(() => {
            flashMessage.value = null;
            flashType.value = null;
        }, 500);
    };

    return {
        flashMessage,
        flashType,
        isVisible,
        showFlash,
        hideFlash
    }

}

































/**
 * * ---------[REF METHOD]------------
 * Takes an inner value and returns a reactive and mutable ref object
 * The returned object has 1 prop .value that returns the inner value
 * 
 */
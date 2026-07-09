import { computed, ref, watch, type ComputedRef, type Ref } from "vue";

import type { BookingFormValues } from "../config/booking/BookingFields";
import { fetchBookingPriceQuote } from "../services/api/bookingPriceQuoteApi";
import type { BookingPriceQuoteDto } from "../types/booking/BookingPriceQuoteTypes";

type UseBookingPriceQuoteParams = {
  formValues: Ref<BookingFormValues>;
  canLoadPriceQuote: ComputedRef<boolean>;
};

export function useBookingPriceQuote(params: UseBookingPriceQuoteParams) {
  const priceQuote = ref<BookingPriceQuoteDto | null>(null);
  const isLoading = ref(false);
  const errorMessage = ref<string | null>(null);

  let requestId = 0;
  let abortController: AbortController | null = null;
  let debounceTimer: ReturnType<typeof window.setTimeout> | null = null;

  const requestSignature = computed(() => {
    const values = params.formValues.value;

    return JSON.stringify({
      bezoekdatum: values.bezoekdatum,
      onderwijsSector: values.onderwijsSector,
      programma: values.programma,
      aantalLeerlingen: values.aantalLeerlingen,
      aantalBegeleiders: values.aantalBegeleiders,
      remiseBreak: values.remiseBreak,
      kazerneBreak: values.kazerneBreak,
      fortgrachtBreak: values.fortgrachtBreak,
      waterijsje: values.waterijsje,
      glasLimonade: values.glasLimonade,
      lunchChoice: values.lunchChoice,
      remiseLunch: values.remiseLunch,
    });
  });

  async function loadPriceQuote(): Promise<void> {
    if (!params.canLoadPriceQuote.value) {
      clearPriceQuote();
      return;
    }

    abortController?.abort();
    abortController = new AbortController();

    const currentRequestId = ++requestId;
    isLoading.value = true;
    errorMessage.value = null;

    try {
      const values = params.formValues.value;
      const result = await fetchBookingPriceQuote(
        {
          bezoekdatum: values.bezoekdatum,
          onderwijsSector: values.onderwijsSector,
          programma: values.programma,
          aantalLeerlingen: values.aantalLeerlingen,
          aantalBegeleiders: values.aantalBegeleiders,
          remiseBreak: values.remiseBreak,
          kazerneBreak: values.kazerneBreak,
          fortgrachtBreak: values.fortgrachtBreak,
          waterijsje: values.waterijsje,
          glasLimonade: values.glasLimonade,
          lunchChoice: values.lunchChoice,
          remiseLunch: values.remiseLunch,
        },
        abortController.signal,
      );

      if (currentRequestId !== requestId) {
        return;
      }

      priceQuote.value = result;
    } catch (error) {
      if (abortController.signal.aborted || currentRequestId !== requestId) {
        return;
      }

      priceQuote.value = null;
      errorMessage.value =
        error instanceof Error
          ? error.message
          : "De prijsopgave kan niet worden opgehaald.";
    } finally {
      if (currentRequestId === requestId) {
        isLoading.value = false;
      }
    }
  }

  function scheduleLoadPriceQuote(): void {
    if (debounceTimer !== null) {
      window.clearTimeout(debounceTimer);
    }

    if (!params.canLoadPriceQuote.value) {
      clearPriceQuote();
      return;
    }

    debounceTimer = window.setTimeout(() => {
      debounceTimer = null;
      void loadPriceQuote();
    }, 250);
  }

  function clearPriceQuote(): void {
    requestId++;
    abortController?.abort();
    abortController = null;

    if (debounceTimer !== null) {
      window.clearTimeout(debounceTimer);
      debounceTimer = null;
    }

    priceQuote.value = null;
    isLoading.value = false;
    errorMessage.value = null;
  }

  function formatCurrency(amount: number): string {
    return new Intl.NumberFormat("nl-NL", {
      style: "currency",
      currency: "EUR",
    }).format(amount);
  }

  watch(
    [() => params.canLoadPriceQuote.value, requestSignature],
    () => {
      scheduleLoadPriceQuote();
    },
    { immediate: true },
  );

  return {
    priceQuote,
    isLoading,
    errorMessage,
    formatCurrency,
    reloadPriceQuote: loadPriceQuote,
    clearPriceQuote,
  };
}

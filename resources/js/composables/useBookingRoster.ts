import { computed, ref, watch, type ComputedRef, type Ref } from "vue";

import type { BookingFormValues } from "../config/booking/BookingFields";
import { fetchBookingRoster } from "../services/api/bookingRosterApi";
import type {
  BookingRosterEducationSelectionPayload,
  BookingRosterResult,
} from "../types/booking/BookingRosterTypes";

type UseBookingRosterParams = {
  formValues: Ref<BookingFormValues>;
  canLoadRoster: ComputedRef<boolean>;
  getEducationSelectionPayload: () => BookingRosterEducationSelectionPayload | null;
};

export function useBookingRoster(params: UseBookingRosterParams) {
  const roster = ref<BookingRosterResult | null>(null);
  const isLoading = ref(false);
  const errorMessage = ref<string | null>(null);

  let requestId = 0;
  let abortController: AbortController | null = null;

  const requestSignature = computed(() => {
    const values = params.formValues.value;

    return JSON.stringify({
      bezoekdatum: values.bezoekdatum,
      onderwijsSector: values.onderwijsSector,
      programma: values.programma,
      levelSelection: values.levelSelection,
      keuzemodule: values.keuzemodule,
      aantalLeerlingen: values.aantalLeerlingen,
      aantalBegeleiders: values.aantalBegeleiders,
    });
  });

  async function loadRoster(): Promise<void> {
    if (!params.canLoadRoster.value) {
      clearRoster();
      return;
    }

    const educationSelection = params.getEducationSelectionPayload();

    if (!educationSelection) {
      clearRoster();
      return;
    }

    abortController?.abort();
    abortController = new AbortController();

    const currentRequestId = ++requestId;
    isLoading.value = true;
    errorMessage.value = null;

    try {
      const values = params.formValues.value;
      const result = await fetchBookingRoster(
        {
          bezoekdatum: values.bezoekdatum,
          onderwijsSector: values.onderwijsSector,
          programma: values.programma,
          educationSelection,
          keuzemodule: values.keuzemodule,
          aantalLeerlingen: values.aantalLeerlingen,
          aantalBegeleiders: values.aantalBegeleiders,
        },
        abortController.signal,
      );

      if (currentRequestId !== requestId) {
        return;
      }

      roster.value = result;
    } catch (error) {
      if (abortController.signal.aborted || currentRequestId !== requestId) {
        return;
      }

      roster.value = null;
      errorMessage.value =
        error instanceof Error
          ? error.message
          : "Het conceptrooster kan niet worden opgehaald.";
    } finally {
      if (currentRequestId === requestId) {
        isLoading.value = false;
      }
    }
  }

  function clearRoster(): void {
    requestId++;
    abortController?.abort();
    abortController = null;
    roster.value = null;
    isLoading.value = false;
    errorMessage.value = null;
  }

  watch(
    [() => params.canLoadRoster.value, requestSignature],
    () => {
      void loadRoster();
    },
    { immediate: true },
  );

  return {
    roster,
    isLoading,
    errorMessage,
    reloadRoster: loadRoster,
    clearRoster,
  };
}

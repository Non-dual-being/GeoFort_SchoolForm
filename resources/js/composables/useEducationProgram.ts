import { computed, type ComputedRef, type Ref } from "vue";

import type { BookingFormValues } from "../config/booking/BookingFields";

import {
  getAvailableProgramOptions,
  isSelectedProgramStillAvailable,
} from "../config/booking/educationProgramHelpers";

import type {
  BookingProgramConfigData,
  ProgramKey,
  SchoolSectorKey,
} from "../types/booking/BookingProgramConfigTypes";

type UseEducationProgramParams = {
  formValues: Ref<BookingFormValues>;
  bookingProgramConfig: Ref<BookingProgramConfigData | null>;
  currentSchoolSector: ComputedRef<SchoolSectorKey | null>;
  hasValidVisitDate: ComputedRef<boolean>;
};

export function useEducationProgram(params: UseEducationProgramParams) {
  /**
   * Programma-opties voor de huidige datum + sector.
   *
   * Voorbeelden:
   * - PO + woensdag → ochtend en dag
   * - PO + maandag → dag
   * - VO onderbouw + woensdag → dag
   */
  const availableProgramOptions = computed(() => {
    const config = params.bookingProgramConfig.value;
    const sector = params.currentSchoolSector.value;

    if (!config || !sector || !params.hasValidVisitDate.value) {
      return [];
    }

    return getAvailableProgramOptions({
      config,
      visitDate: params.formValues.value.bezoekdatum,
      sector,
    });
  });

  /**
   * True wanneer de gebruiker een geldige programmawaarde heeft gekozen.
   */
  const hasValidProgramSelection = computed(() => {
    const selectedProgram = params.formValues.value.programma;

    if (selectedProgram === "") {
      return false;
    }

    return availableProgramOptions.value.some(
      (program) => program.key === selectedProgram,
    );
  });

  /**
   * Melding voor de gebruiker.
   */
  const programSelectionIssue = computed<string | null>(() => {
    const config = params.bookingProgramConfig.value;
    const sector = params.currentSchoolSector.value;

    if (!config || !params.hasValidVisitDate.value || !sector) {
      return null;
    }

    if (availableProgramOptions.value.length === 0) {
      return "Er is geen passend programma beschikbaar voor deze datum en onderwijssector.";
    }

    if (params.formValues.value.programma === "") {
      return "Kies een programma voor het bezoek.";
    }

    if (!hasValidProgramSelection.value) {
      return "Het gekozen programma past niet meer bij de datum of onderwijssector.";
    }

    return null;
  });

  /**
   * Defensieve check voor watchers.
   *
   * Bijvoorbeeld:
   * - gebruiker kiest PO + woensdag + ochtend
   * - gebruiker verandert datum naar donderdag
   * - ochtend is dan mogelijk niet meer geldig
   */
  const selectedProgramIsStillAvailable = computed(() => {
    const config = params.bookingProgramConfig.value;
    const sector = params.currentSchoolSector.value;

    if (!config || !sector || !params.hasValidVisitDate.value) {
      return params.formValues.value.programma === "";
    }

    return isSelectedProgramStillAvailable({
      config,
      visitDate: params.formValues.value.bezoekdatum,
      sector,
      selectedProgram: params.formValues.value.programma,
    });
  });

  function setSelectedProgram(value: ProgramKey | ""): void {
    if (value === "") {
      params.formValues.value.programma = "";
      return;
    }

    const isAllowed = availableProgramOptions.value.some(
      (program) => program.key === value,
    );

    params.formValues.value.programma = isAllowed ? value : "";
  }

  function resetSelectedProgram(): void {
    params.formValues.value.programma = "";
  }

  return {
    availableProgramOptions,
    hasValidProgramSelection,
    programSelectionIssue,
    selectedProgramIsStillAvailable,

    setSelectedProgram,
    resetSelectedProgram,
  };
}
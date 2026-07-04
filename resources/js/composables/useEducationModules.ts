import { computed, type ComputedRef, type Ref } from "vue";

import type { BookingFormValues } from "../config/booking/BookingFields";

import {
  getAvailableEducationModuleOptions,
  isSelectedEducationModuleStillAvailable,
} from "../config/booking/educationModuleHelpers";

import type {
  AnyLevelKey,
  BookingProgramConfigData,
  EducationModuleKey,
  SchoolSectorKey,
} from "../types/booking/BookingProgramConfigTypes";

type UseEducationModulesParams = {
  formValues: Ref<BookingFormValues>;
  bookingProgramConfig: Ref<BookingProgramConfigData | null>;
  currentSchoolSector: ComputedRef<SchoolSectorKey | null>;
  selectedLevelsForCurrentSector: ComputedRef<AnyLevelKey[]>;
  selectedGroupsForCurrentSector: ComputedRef<Record<string, string[]>>;
  canShowModuleSelect: ComputedRef<boolean>;
};

export function useEducationModules(params: UseEducationModulesParams) {
  /**
   * Standaardmodules voor de actuele keuze.
   *
   * Deze hoeft de gebruiker niet te kiezen.
   * We tonen ze alleen als informatie.
   */
  const standardModuleOptions = computed(() => {
    const config = params.bookingProgramConfig.value;
    const sector = params.currentSchoolSector.value;
    const program = params.formValues.value.programma;

    if (!config || !sector || program === "") {
      return [];
    }

    return getAvailableEducationModuleOptions({
      config,
      sector,
      program,
      groupType: "standaard",
      selectedLevels: params.selectedLevelsForCurrentSector.value,
      selectedGroupsByLevel: params.selectedGroupsForCurrentSector.value,
    });
  });

  /**
   * Keuzemodules voor de actuele keuze.
   *
   * Hieruit kiest de gebruiker exact één module wanneer er opties zijn.
   */
  const choiceModuleOptions = computed(() => {
    const config = params.bookingProgramConfig.value;
    const sector = params.currentSchoolSector.value;
    const program = params.formValues.value.programma;

    if (!config || !sector || program === "") {
      return [];
    }

    return getAvailableEducationModuleOptions({
      config,
      sector,
      program,
      groupType: "keuze",
      selectedLevels: params.selectedLevelsForCurrentSector.value,
      selectedGroupsByLevel: params.selectedGroupsForCurrentSector.value,
    });
  });

  /**
   * True wanneer de huidige combinatie een keuzemodule vereist.
   */
  const requiresChoiceModule = computed(() => {
    return choiceModuleOptions.value.length > 0;
  });

  /**
   * True wanneer de gebruiker een geldige module heeft gekozen.
   *
   * Als er geen keuzemodules zijn, is de modulekeuze automatisch geldig.
   */
  const hasValidModuleSelection = computed(() => {
    if (!params.canShowModuleSelect.value) {
      return false;
    }

    if (!requiresChoiceModule.value) {
      return true;
    }

    const selectedModule = params.formValues.value.keuzemodule;

    if (selectedModule === "") {
      return false;
    }

    return choiceModuleOptions.value.some(
      (module) => module.key === selectedModule,
    );
  });

  /**
   * Melding voor de module-selectiestap.
   */
  const moduleSelectionIssue = computed<string | null>(() => {
    if (!params.canShowModuleSelect.value) {
      return null;
    }

    if (!requiresChoiceModule.value) {
      return null;
    }

    const selectedModule = params.formValues.value.keuzemodule;

    if (selectedModule === "") {
      return "Kies een keuzemodule voor het bezoek.";
    }

    if (
      !choiceModuleOptions.value.some((module) => module.key === selectedModule)
    ) {
      return "De gekozen keuzemodule past niet meer bij de gekozen onderwijsniveaus of groepen.";
    }

    return null;
  });

  /**
   * Defensieve check voor watchers.
   */
  const selectedModuleIsStillAvailable = computed(() => {
    return isSelectedEducationModuleStillAvailable({
      selectedModule: params.formValues.value.keuzemodule,
      availableChoiceModules: choiceModuleOptions.value,
    });
  });

  function setSelectedModule(value: EducationModuleKey | ""): void {
    if (value === "") {
      params.formValues.value.keuzemodule = "";
      return;
    }

    const isAllowed = choiceModuleOptions.value.some(
      (module) => module.key === value,
    );

    params.formValues.value.keuzemodule = isAllowed ? value : "";
  }

  function resetSelectedModule(): void {
    params.formValues.value.keuzemodule = "";
  }

  return {
    standardModuleOptions,
    choiceModuleOptions,
    requiresChoiceModule,

    hasValidModuleSelection,
    moduleSelectionIssue,
    selectedModuleIsStillAvailable,

    setSelectedModule,
    resetSelectedModule,
  };
}
import { computed, type ComputedRef, type Ref } from "vue";

import type { BookingFormValues } from "../config/booking/BookingFields";
import type { BoekingBeleidApiResponse } from "../types/booking/BookingPolicyTypes";

type UseSupervisorCountParams = {
  formValues: Ref<BookingFormValues>;
  bookingPolicy: Ref<BoekingBeleidApiResponse | null>;
  canShowSupervisorCountBase: ComputedRef<boolean>;
};

function parsePositiveInteger(value: string): number | null {
  const trimmed = value.trim();

  if (trimmed === "") {
    return null;
  }

  if (!/^[0-9]+$/.test(trimmed)) {
    return null;
  }

  const parsed = Number(trimmed);

  if (!Number.isInteger(parsed) || parsed <= 0) {
    return null;
  }

  return parsed;
}

export function useSupervisorCount(params: UseSupervisorCountParams) {
  const canShowSupervisorCount = computed(() => {
    return params.canShowSupervisorCountBase.value;
  });

  const studentCount = computed(() => {
    return parsePositiveInteger(params.formValues.value.aantalLeerlingen);
  });

  const studentsPerFreeSupervisor = computed(() => {
    return params.bookingPolicy.value?.limieten.leerlingenPerGratisBegeleider ?? 8;
  });

  const studentsPerRequiredSupervisor = computed(() => {
    return params.bookingPolicy.value?.limieten.leerlingenPerVerplichteBegeleider ?? 16;
  });

  const maxSupervisors = computed(() => {
    return params.bookingPolicy.value?.limieten.maxBegeleidersPerBoeking ?? null;
  });

  const freeSupervisorCount = computed(() => {
    const students = studentCount.value;
    const ratio = studentsPerFreeSupervisor.value;

    if (students === null || ratio <= 0) {
      return null;
    }

    return Math.ceil(students / ratio);
  });

  const minSupervisorCount = computed(() => {
    const students = studentCount.value;
    const ratio = studentsPerRequiredSupervisor.value;

    if (students === null || ratio <= 0) {
      return null;
    }

    return Math.ceil(students / ratio);
  });

  const supervisorCountValue = computed(() => {
    return parsePositiveInteger(params.formValues.value.aantalBegeleiders);
  });

  const supervisorCountIssue = computed<string | null>(() => {
    if (!canShowSupervisorCount.value) {
      return null;
    }

    if (minSupervisorCount.value === null || maxSupervisors.value === null) {
      return "De begeleidersregels konden niet worden bepaald.";
    }

    if (params.formValues.value.aantalBegeleiders.trim() === "") {
      return "Vul het aantal begeleiders in.";
    }

    if (!/^[0-9]+$/.test(params.formValues.value.aantalBegeleiders.trim())) {
      return "Gebruik alleen cijfers voor het aantal begeleiders.";
    }

    if (supervisorCountValue.value === null) {
      return "Het aantal begeleiders moet minimaal 1 zijn.";
    }

    if (supervisorCountValue.value < minSupervisorCount.value) {
      return `Bij ${studentCount.value} leerlingen zijn minimaal ${minSupervisorCount.value} begeleiders nodig.`;
    }

    if (supervisorCountValue.value > maxSupervisors.value) {
      return `Er kunnen maximaal ${maxSupervisors.value} begeleiders worden opgegeven.`;
    }

    return null;
  });

  const hasValidSupervisorCount = computed(() => {
    return canShowSupervisorCount.value && supervisorCountIssue.value === null;
  });

  const supervisorCountPlaceholder = computed(() => {
    if (freeSupervisorCount.value === null) {
      return "Bijv. 5";
    }

    return `Bijv. ${freeSupervisorCount.value}`;
  });

  const supervisorCountHelpText = computed(() => {
    if (
      studentCount.value === null ||
      minSupervisorCount.value === null ||
      freeSupervisorCount.value === null
    ) {
      return "";
    }

    return `Bij ${studentCount.value} leerlingen zijn minimaal ${minSupervisorCount.value} begeleiders nodig. ${freeSupervisorCount.value} begeleiders zijn gratis inbegrepen.`;
  });

  const supervisorCountLimitText = computed(() => {
    if (minSupervisorCount.value === null || maxSupervisors.value === null) {
      return "";
    }

    return `Minimaal ${minSupervisorCount.value}, maximaal ${maxSupervisors.value} begeleiders.`;
  });

  return {
    canShowSupervisorCount,
    maxSupervisors,
    freeSupervisorCount,
    minSupervisorCount,
    supervisorCountIssue,
    hasValidSupervisorCount,
    supervisorCountPlaceholder,
    supervisorCountHelpText,
    supervisorCountLimitText,
  };
}

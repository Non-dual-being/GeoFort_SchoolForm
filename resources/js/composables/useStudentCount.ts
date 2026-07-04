import { computed, type ComputedRef, type Ref } from "vue";

import { DEFAULT_AGENDA_CAPACITY } from "../config/booking/calendar/helpers";

import type { BookingFormValues } from "../config/booking/BookingFields";

import type {
  BookingProgramConfigData,
  PriceType,
  ProgramKey,
  SchoolSectorKey,
} from "../types/booking/BookingProgramConfigTypes";

import type {
  AgendaAvailabilityDetail,
  fullDatesInfo,
} from "../types/booking/BookingDateType";

type UseStudentCountParams = {
  formValues: Ref<BookingFormValues>;
  bookingProgramConfig: Ref<BookingProgramConfigData | null>;
  currentSchoolSector: ComputedRef<SchoolSectorKey | null>;
  canShowStudentCountBase: ComputedRef<boolean>;
  agendaData: Ref<fullDatesInfo | null>;
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

function getMinStudentsForProgram(params: {
  config: BookingProgramConfigData;
  sector: SchoolSectorKey;
  program: ProgramKey;
}): number | null {
  const { config, sector, program } = params;

  const priceType = config.schoolTypesByKey[sector]?.priceType;

  if (!priceType) {
    return null;
  }

  if (program === "ochtend") {
    return config.studentLimits.min.ochtend.basis;
  }

  return config.studentLimits.min.dag[priceType as PriceType] ?? null;
}

export function useStudentCount(params: UseStudentCountParams) {
  const selectedDate = computed(() => {
    return params.formValues.value.bezoekdatum;
  });

  const selectedProgram = computed<ProgramKey | "">(() => {
    return params.formValues.value.programma;
  });

  const availabilityDetailForDate = computed<AgendaAvailabilityDetail | null>(() => {
    const date = selectedDate.value;

    if (!date || !params.agendaData.value?.availabilityDetails) {
      return null;
    }

    return (
      params.agendaData.value.availabilityDetails.find(
        (detail) => detail.datum === date,
      ) ?? null
    );
  });

  const capacity = computed(() => {
    return params.agendaData.value?.capacity ?? DEFAULT_AGENDA_CAPACITY;
  });

  const availableStudentsForDate = computed(() => {
    return (
      availabilityDetailForDate.value?.availableStudents ??
      capacity.value.maxStudentsTotal
    );
  });

  const remainingSchoolSlotsForDate = computed(() => {
    return (
      availabilityDetailForDate.value?.remainingSchoolSlots ??
      capacity.value.maxSchoolsPerDay
    );
  });

  const minStudents = computed<number | null>(() => {
    const config = params.bookingProgramConfig.value;
    const sector = params.currentSchoolSector.value;
    const program = selectedProgram.value;

    if (!config || !sector || program === "") {
      return null;
    }

    return getMinStudentsForProgram({
      config,
      sector,
      program,
    });
  });

  const programMaxStudents = computed<number | null>(() => {
    const config = params.bookingProgramConfig.value;
    const program = selectedProgram.value;

    if (!config || program === "") {
      return null;
    }

    return config.studentLimits.max[program];
  });

  const effectiveMaxStudents = computed<number | null>(() => {
    if (programMaxStudents.value === null) {
      return null;
    }

    return Math.min(
      programMaxStudents.value,
      availableStudentsForDate.value,
    );
  });

  const canShowStudentCount = computed(() => {
    return params.canShowStudentCountBase.value;
  });

  const studentCountValue = computed(() => {
    return parsePositiveInteger(params.formValues.value.aantalLeerlingen);
  });

  const studentCountIssue = computed<string | null>(() => {
    if (!canShowStudentCount.value) {
      return null;
    }

    if (remainingSchoolSlotsForDate.value <= 0) {
      return "Deze datum is volgeboekt. Er staan al maximaal twee scholen ingepland.";
    }

    if (availableStudentsForDate.value <= 0) {
      return "Deze datum is volgeboekt. Er zijn geen leerlingplaatsen meer beschikbaar.";
    }

    if (minStudents.value === null || effectiveMaxStudents.value === null) {
      return "De leerlinglimieten konden niet worden bepaald. Controleer de gekozen datum, sector en het programma.";
    }

    if (minStudents.value > effectiveMaxStudents.value) {
      return `Op deze datum zijn nog maximaal ${effectiveMaxStudents.value} plaatsen beschikbaar. Voor dit programma zijn minimaal ${minStudents.value} leerlingen nodig. Kies een andere datum of neem contact op.`;
    }

    if (params.formValues.value.aantalLeerlingen.trim() === "") {
      return "Vul het aantal leerlingen in.";
    }

    if (studentCountValue.value === null) {
      return "Gebruik alleen cijfers voor het aantal leerlingen.";
    }

    if (studentCountValue.value < minStudents.value) {
      return `Voor dit programma geldt een minimum van ${minStudents.value} leerlingen.`;
    }

    if (studentCountValue.value > effectiveMaxStudents.value) {
      return `Voor deze datum en dit programma kunt u maximaal ${effectiveMaxStudents.value} leerlingen aanmelden.`;
    }

    return null;
  });

  const hasValidStudentCount = computed(() => {
    return canShowStudentCount.value && studentCountIssue.value === null;
  });

  const studentCountLimitText = computed(() => {
    if (minStudents.value === null || effectiveMaxStudents.value === null) {
      return "";
    }

    return `Minimaal ${minStudents.value}, maximaal ${effectiveMaxStudents.value} leerlingen.`;
  });

  const studentCountHelpText = computed(() => {
    if (!canShowStudentCount.value) {
      return "";
    }

    if (availableStudentsForDate.value >= capacity.value.maxStudentsTotal) {
      return `Er is nog volledige capaciteit voor maximaal ${capacity.value.maxStudentsTotal} leerlingen op deze datum.`;
    }

    return `Op deze datum zijn nog maximaal ${availableStudentsForDate.value} leerlingplaatsen beschikbaar.`;
  });

  const studentCountPlaceholder = computed(() => {
  if (effectiveMaxStudents.value === null) {
    return "Bijv. 160";
  }

  return `Bijv. ${effectiveMaxStudents.value}`;
});

  

  return {
    canShowStudentCount,

    minStudents,
    programMaxStudents,
    availableStudentsForDate,
    remainingSchoolSlotsForDate,
    effectiveMaxStudents,

    studentCountIssue,
    hasValidStudentCount,

    studentCountLimitText,
    studentCountHelpText,
    studentCountPlaceholder
  };
}
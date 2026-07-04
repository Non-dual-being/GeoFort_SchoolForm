import type {
  BookingProgramConfigData,
  ProgramKey,
  ProgramOption,
  SchoolSectorKey,
  Weekday,
} from "../../types/booking/BookingProgramConfigTypes";

import {
  getIsoWeekdayFromYmd,
  isWeekday,
} from "./calendar/helpers";

import { programOrder } from "./BookingFields";

type GetAvailableProgramOptionsParams = {
  config: BookingProgramConfigData;
  visitDate: string;
  sector: SchoolSectorKey;
};

/**
 * Geeft beschikbare programma's terug voor:
 * - bezoekdatum
 * - onderwijssector
 * - backendconfig
 *
 * Voorbeeld:
 * - primairOnderwijs + woensdag → ochtend + dag
 * - primairOnderwijs + maandag → dag
 * - voortgezetOnderbouw + woensdag → dag
 */
export function getAvailableProgramOptions({
  config,
  visitDate,
  sector,
}: GetAvailableProgramOptionsParams): ProgramOption[] {
  const selectedWeekday = getIsoWeekdayFromYmd(visitDate);

  if (selectedWeekday === null || !isWeekday(selectedWeekday)) {
    return [];
  }

  return programOrder
    .filter((programKey) =>
      isProgramAvailableForSelection({
        config,
        programKey,
        sector,
        weekday: selectedWeekday,
      }),
    )
    .map((programKey) => ({
      key: programKey,
      ...config.programs[programKey],
    }));
}

/**
 * Controleert of één programma beschikbaar is
 * voor de gekozen sector en weekdag.
 */
export function isProgramAvailableForSelection(params: {
  config: BookingProgramConfigData;
  programKey: ProgramKey;
  sector: SchoolSectorKey;
  weekday: Weekday;
}): boolean {
  const {
    config,
    programKey,
    sector,
    weekday,
  } = params;

  const program = config.programs[programKey];

  if (!program.allowedSchoolTypes.includes(sector)) {
    return false;
  }

  if (!program.allowedWeekdays.includes(weekday)) {
    return false;
  }

  return true;
}

/**
 * Controleert of de huidige programma-keuze nog geldig is.
 *
 * Dit gebruik je bij:
 * - datumwijziging
 * - sectorwijziging
 * - defensieve resetlogica
 */
export function isSelectedProgramStillAvailable(params: {
  config: BookingProgramConfigData;
  visitDate: string;
  sector: SchoolSectorKey;
  selectedProgram: ProgramKey | "";
}): boolean {
  const {
    config,
    visitDate,
    sector,
    selectedProgram,
  } = params;

  if (selectedProgram === "") {
    return true;
  }

  const availablePrograms = getAvailableProgramOptions({
    config,
    visitDate,
    sector,
  });

  return availablePrograms.some((program) => program.key === selectedProgram);
}

/**
 * Meta-tekst voor de programma-select.
 */
export function formatProgramMeta(program: ProgramOption): string {
  return `${program.beginTijd}–${program.eindTijd} · ${program.duurLesmodule}`;
}

/**
 * Tekst wanneer er geen programma beschikbaar is.
 */
export function getNoProgramAvailableText(): string {
  return "Er is geen passend programma beschikbaar voor deze datum en onderwijssector.";
}
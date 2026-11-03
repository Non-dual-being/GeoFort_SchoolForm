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
    .filter((programKey) => programKey !== "dag" || !config.availableFrom || visitDate >= config.availableFrom)
    .filter((programKey) =>
      isProgramAvailableForSelection({
        config,
        programKey,
        sector,
        weekday: selectedWeekday,
        visitDate,
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
  visitDate?: string;
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

  if (params.visitDate && config.availability) {
    return isProgramOpenOnDate(config, programKey, params.visitDate);
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

/** Resolve the offer for each calendar date, independently of the current selection. */
export function isPublicOfferDateAvailable(source: BookingProgramConfigData, variant: "auto" | "four" | "five", date: string): boolean {
  const four = variant !== "five" && (variant === "four" || date >= (source.fourModuleConfig?.availableFrom ?? "2027-01-04"));
  const config = four ? source.fourModuleConfig : source;
  return Boolean(config && (isProgramOpenOnDate(config, "ochtend", date) || isProgramOpenOnDate(config, "dag", date)));
}

export function isProgramOpenOnDate(config: Pick<BookingProgramConfigData, "availability" | "programVersion" | "availableFrom" | "fiveModuleOpen">, program: ProgramKey, date: string): boolean {
  const weekday = getIsoWeekdayFromYmd(date);
  if (!weekday || weekday > 5) return false;
  if (program === "dag" && config.programVersion === "day-four-2027") return date >= (config.availableFrom ?? "2027-01-04");
  const availability = config.availability;
  if (!availability) return program === "ochtend" ? weekday === 3 : config.fiveModuleOpen !== false;
  const key = program === "ochtend" ? "morning" : "five";
  const rule = availability.periods.find(period => period.program === key && date >= period.from && date <= period.until && period.weekdays.includes(weekday));
  return rule ? rule.open : program === "ochtend" ? weekday === 3 : availability.fiveModuleOpen;
}

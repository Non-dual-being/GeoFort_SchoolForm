import { isProgramOpenOnDate } from "../../config/booking/educationProgramHelpers";

export type ProgramKind = "morning" | "five";
export type PeriodKind = "day" | "months" | "year" | "custom";
export type ProgramPeriod = { program: ProgramKind; from: string; until: string; weekdays: number[]; open: boolean; note: string };
export type ProgramConfiguration = { fiveModuleOpen: boolean; revision: number; periods: ProgramPeriod[]; updatedAt: string | null };
export type PeriodSelection = { kind: PeriodKind; year: number; firstMonth: number; lastMonth: number; from: string; until: string };
export const weekdays = ["Maandag", "Dinsdag", "Woensdag", "Donderdag", "Vrijdag"];
export const months = ["Januari", "Februari", "Maart", "April", "Mei", "Juni", "Juli", "Augustus", "September", "Oktober", "November", "December"];
export const programLabels = { morning: "Ochtendprogramma", five: "Dagprogramma · vijf modules" };
export const programRoutes = { morning: "/configuratie/ochtend", five: "/configuratie/vijf-modules" };

export function validDate(value: string): boolean {
  if (!/^\d{4}-\d{2}-\d{2}$/.test(value)) return false;
  const [year, month, day] = value.split("-").map(Number);
  const date = new Date(`${value}T12:00:00`);
  return date.getFullYear() === year && date.getMonth() + 1 === month && date.getDate() === day;
}
export function formatDate(value: string): string {
  return validDate(value) ? new Intl.DateTimeFormat("nl-NL", { day: "numeric", month: "long", year: "numeric" }).format(new Date(`${value}T12:00:00`)) : "";
}
export function periodBounds(selection: PeriodSelection): { from: string; until: string } | null {
  if (selection.kind === "day" || selection.kind === "custom") {
    const until = selection.kind === "day" ? selection.from : selection.until;
    return validDate(selection.from) && validDate(until) && selection.from <= until ? { from: selection.from, until } : null;
  }
  if (!Number.isInteger(selection.year) || selection.year < 1900 || selection.year > 9999) return null;
  const first = selection.kind === "year" ? 1 : selection.firstMonth;
  const last = selection.kind === "year" ? 12 : selection.lastMonth;
  if (![first, last].every(month => Number.isInteger(month) && month >= 1 && month <= 12) || first > last) return null;
  const endDay = new Date(selection.year, last, 0).getDate();
  return { from: `${selection.year}-${String(first).padStart(2,"0")}-01`, until: `${selection.year}-${String(last).padStart(2,"0")}-${endDay}` };
}
export function inferPeriodSelection(period: ProgramPeriod): PeriodSelection {
  const year = Number(period.from.slice(0, 4));
  const firstMonth = Number(period.from.slice(5, 7));
  const lastMonth = Number(period.until.slice(5, 7));
  const base: PeriodSelection = { kind: "custom", year, firstMonth, lastMonth, from: period.from, until: period.until };
  if (period.from === period.until) return { ...base, kind: "day" };
  if (year === Number(period.until.slice(0, 4))) {
    const bounds = periodBounds({ ...base, kind: "months" });
    if (bounds?.from === period.from && bounds.until === period.until) return { ...base, kind: firstMonth === 1 && lastMonth === 12 ? "year" : "months" };
  }
  return base;
}
export function periodSummary(period: ProgramPeriod): string {
  const days = period.weekdays.slice().sort().map(day => weekdays[day - 1]?.toLowerCase()).filter(Boolean).join(", ");
  const range = period.from === period.until ? ` ${formatDate(period.from)}` : `, van ${formatDate(period.from)} tot en met ${formatDate(period.until)}`;
  return `${period.open ? "Open" : "Gesloten"} op ${days}${range}.`;
}
export function periodHasWeekday(period: ProgramPeriod): boolean {
  if (!validDate(period.from) || !validDate(period.until)) return false;
  const start = new Date(`${period.from}T12:00:00`);
  const startDay = start.getDay() || 7;
  return period.weekdays.some(day => {
    const candidate = new Date(start);
    candidate.setDate(start.getDate() + (day - startDay + 7) % 7);
    return candidate <= new Date(`${period.until}T12:00:00`);
  });
}
export function programDateExplanation(config: ProgramConfiguration, program: ProgramKind | "four", date: string) {
  const morning = program === "morning";
  const open = isProgramOpenOnDate({ availability: config, ...(program === "four" ? { programVersion: "day-four-2027" as const, availableFrom: "2027-01-04" } : {}) }, morning ? "ochtend" : "dag", date);
  if (!validDate(date)) return { open: false, explanation: "Kies een geldige datum." };
  const weekday = new Date(`${date}T12:00:00`).getDay() || 7;
  if (weekday > 5) return { open, explanation: "In het weekend is geen onderwijsprogramma opengesteld." };
  if (program === "four") return { open, explanation: "Maandag tot en met vrijdag, vanaf 4 januari 2027." };
  const rule = config.periods.find(period => period.program === program && date >= period.from && date <= period.until && period.weekdays.includes(weekday));
  return { open, explanation: rule ? `Ingestelde periode: ${periodSummary(rule)}` : morning ? "Standaard op woensdag, voor PO en speciaal basisonderwijs." : `Standaardinstelling: ${config.fiveModuleOpen ? "open" : "gesloten"}.` };
}

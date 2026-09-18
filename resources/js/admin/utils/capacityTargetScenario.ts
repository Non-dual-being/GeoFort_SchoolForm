import type { CapacityAssessment, CapacityMonthRow, CapacityTarget, CapacityTargetMetric, CapacityTargetContext } from "../types/bookingAnalytics";

export interface CapacityTargetScenarioInput { effectiveDate: string; studentsPerAvailableDay: number | null; bookingsPerAvailableDay: number | null }
export type CapacityTargetScenarioStatus = "Nog geen officieel target" | "Nieuw scenario" | "Officieel opgeslagen target" | "Niet-opgeslagen scenario";

export function capacityTargetScenarioChanged(input: CapacityTargetScenarioInput, baseline: CapacityTargetScenarioInput | null): boolean {
  return baseline !== null && (input.effectiveDate !== baseline.effectiveDate
    || input.studentsPerAvailableDay !== baseline.studentsPerAvailableDay
    || input.bookingsPerAvailableDay !== baseline.bookingsPerAvailableDay);
}

export function capacityTargetScenarioStatus(hasOfficialTarget: boolean, changed: boolean): CapacityTargetScenarioStatus {
  if (!hasOfficialTarget) return changed ? "Nieuw scenario" : "Nog geen officieel target";
  return changed ? "Niet-opgeslagen scenario" : "Officieel opgeslagen target";
}

export function deriveCapacityTargetAverage(students: number | null, bookings: number | null): number | null {
  return students !== null && bookings !== null && bookings > 0 ? students / bookings : null;
}

/** Official result months only: an effective target period and at least one counted booking record. */
export function filterCapacityTargetResultRows(rows: CapacityMonthRow[]): CapacityMonthRow[] {
  return rows.filter((row) => row.targetAvailableDays > 0
    && row.bookingsTargetComparison.target !== null
    && (row.bookingsTargetComparison.actual ?? 0) > 0);
}

export function validateCapacityTargetScenario(input: CapacityTargetScenarioInput, today: string): Record<string, string> {
  const errors: Record<string, string> = {};
  if (!/^\d{4}-\d{2}-\d{2}$/.test(input.effectiveDate) || input.effectiveDate < today) errors.effectiveDate = "Kies vandaag of een toekomstige ingangsdatum.";
  if (input.studentsPerAvailableDay === null || !Number.isInteger(input.studentsPerAvailableDay) || input.studentsPerAvailableDay < 0 || input.studentsPerAvailableDay > 160) errors.studentsPerAvailableDay = "Vul een geheel aantal van 0 tot en met 160 in.";
  const bookings = input.bookingsPerAvailableDay;
  if (bookings === null || !Number.isFinite(bookings) || bookings < 0 || bookings > 2 || Math.abs(bookings * 10 - Math.round(bookings * 10)) > 1e-8) errors.bookingsPerAvailableDay = "Vul 0 tot en met 2 in, in stappen van 0,1.";
  return errors;
}

export function scenarioCapacityRows(officialRows: CapacityMonthRow[], context: CapacityTargetContext, input: CapacityTargetScenarioInput): CapacityMonthRow[] {
  if (Object.keys(validateCapacityTargetScenario(input, context.today)).length || input.studentsPerAvailableDay === null || input.bookingsPerAvailableDay === null) return officialRows;
  const scenario = { students: input.studentsPerAvailableDay, bookings: input.bookingsPerAvailableDay };
  return officialRows.map((row) => {
    const days = context.daySnapshots.filter((day) => day.month === row.month && day.evaluationIncluded);
    let studentsTarget = 0; let bookingsTarget = 0; let targetDays = 0; let studentsActual = 0; let bookingsActual = 0;
    for (const day of days) {
      const target = day.date >= input.effectiveDate ? scenario : targetValues(day.officialTarget);
      if (!target) continue;
      studentsActual += day.studentsActual; bookingsActual += day.bookingsActual;
      if (day.countsForCapacity) { targetDays++; studentsTarget += target.students; bookingsTarget += target.bookings; }
    }
    const hasTarget = targetDays > 0;
    const students = comparison(studentsActual, studentsTarget, hasTarget, row.periodState, row.technicalCapacity.students);
    const bookings = comparison(bookingsActual, bookingsTarget, hasTarget, row.periodState, row.technicalCapacity.bookings);
    const actualAverage = bookingsActual > 0 ? studentsActual / bookingsActual : null;
    const targetAverage = bookingsTarget > 0 ? studentsTarget / bookingsTarget : null;
    return {
      ...row, targetAvailableDays: targetDays, assessmentAvailable: row.periodState !== "future" && hasTarget,
      studentsTargetComparison: students, bookingsTargetComparison: bookings,
      averageBookingSizeComparison: averageComparison(actualAverage, targetAverage, hasTarget, row.periodState),
      targetAboveTechnicalCapacity: { students: studentsTarget > row.technicalCapacity.students, bookings: bookingsTarget > row.technicalCapacity.bookings },
    };
  });
}

function targetValues(target: CapacityTarget | null): { students: number; bookings: number } | null {
  return target ? { students: target.studentsPerAvailableDay, bookings: target.bookingsPerAvailableDay } : null;
}
function state(actual: number, target: number, hasTarget: boolean, period: CapacityMonthRow["periodState"]): CapacityAssessment {
  if (!hasTarget) return "missingTarget";
  if (period === "future") return "future";
  return actual < target ? "below" : actual > target ? "above" : "onTarget";
}
function comparison(actual: number, target: number, hasTarget: boolean, period: CapacityMonthRow["periodState"], capacity: number): CapacityTargetMetric {
  const difference = hasTarget ? actual - target : null;
  return { actual: hasTarget ? actual : null, target: hasTarget ? target : null, difference, percentageOfTarget: hasTarget && target > 0 ? actual / target * 100 : null, differencePercentage: hasTarget && target > 0 ? difference! / target * 100 : null, assessment: state(actual, target, hasTarget, period), aboveTechnicalCapacity: hasTarget && actual > capacity };
}
function averageComparison(actual: number | null, target: number | null, hasTarget: boolean, period: CapacityMonthRow["periodState"]): CapacityTargetMetric {
  const difference = actual !== null && target !== null ? actual - target : null;
  return { actual: hasTarget ? actual : null, target: hasTarget ? target : null, difference, percentageOfTarget: actual !== null && target !== null && target > 0 ? actual / target * 100 : null, differencePercentage: difference !== null && target !== null && target > 0 ? difference / target * 100 : null, assessment: !hasTarget ? "missingTarget" : target === null || actual === null ? "unavailable" : state(actual, target, hasTarget, period), aboveTechnicalCapacity: false };
}

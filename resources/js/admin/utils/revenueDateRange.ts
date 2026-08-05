export interface AvailableVisitDateRange {
  min: string | null;
  max: string | null;
}

export interface RevenueDatePeriod {
  startDate: string;
  endDate: string;
}

export function normalizeRevenueDatePeriod(
  startDate: string,
  endDate: string,
  range: AvailableVisitDateRange,
): RevenueDatePeriod | null {
  if (!range.min || !range.max) return null;
  if (startDate > range.max || endDate < range.min) {
    return { startDate: range.min, endDate: range.max };
  }

  const normalizedStart = startDate < range.min ? range.min : startDate;
  const normalizedEnd = endDate > range.max ? range.max : endDate;
  return normalizedStart <= normalizedEnd
    ? { startDate: normalizedStart, endDate: normalizedEnd }
    : { startDate: range.min, endDate: range.max };
}

export function isIsoCalendarDate(value: unknown): value is string {
  if (typeof value !== "string" || !/^\d{4}-\d{2}-\d{2}$/.test(value)) return false;
  const [year, month, day] = value.split("-").map(Number);
  if (!year || !month || !day || month > 12) return false;
  return day <= new Date(year, month, 0).getDate();
}

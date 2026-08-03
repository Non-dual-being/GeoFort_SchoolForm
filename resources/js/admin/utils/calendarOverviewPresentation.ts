import type {
  CalendarOverviewCapacity,
  CalendarOverviewProgramFilter,
  CalendarOverviewStatusPresentation,
} from "../types/dashboardCalendarOverview";

export type CalendarOverviewOccupancyLevel = "empty" | "low" | "medium" | "high" | "full-or-over";

export const CALENDAR_OVERVIEW_OCCUPANCY_THRESHOLDS = {
  medium: 0.5,
  high: 0.8,
  full: 1,
} as const;

export function effectiveCalendarOverviewCapacity(capacity: CalendarOverviewCapacity, program: CalendarOverviewProgramFilter): number {
  return program === "all" ? capacity.totalDaily : capacity.programs[program];
}

export function calendarOverviewOccupancyLevel(knownStudentCount: number, capacity: number): CalendarOverviewOccupancyLevel {
  if (knownStudentCount <= 0) return "empty";
  const ratio = knownStudentCount / capacity;
  if (ratio < CALENDAR_OVERVIEW_OCCUPANCY_THRESHOLDS.medium) return "low";
  if (ratio < CALENDAR_OVERVIEW_OCCUPANCY_THRESHOLDS.high) return "medium";
  if (ratio < CALENDAR_OVERVIEW_OCCUPANCY_THRESHOLDS.full) return "high";
  return "full-or-over";
}

export function calendarOverviewOccupancyLabel(level: CalendarOverviewOccupancyLevel): string {
  return {
    empty: "geen bezetting",
    low: "lage bezetting",
    medium: "gemiddelde bezetting",
    high: "hoge bezetting",
    "full-or-over": "volledige of overschreden bezetting",
  }[level];
}

export function calendarOverviewStatusClass(presentation: CalendarOverviewStatusPresentation): string {
  return `admin-status--${presentation}`;
}

export function calendarOverviewDayStatusClass(presentation: CalendarOverviewStatusPresentation): string {
  return `has-status-${presentation}`;
}

export function calendarOverviewBookingCountClass(bookingCount: number): "has-single-booking" | "has-multiple-bookings" | null {
  if (bookingCount === 1) return "has-single-booking";
  return bookingCount >= 2 ? "has-multiple-bookings" : null;
}

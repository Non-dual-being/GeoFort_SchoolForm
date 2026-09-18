export type CalendarOverviewProgram = "dag" | "ochtend";
export type CalendarOverviewStatus = "In optie" | "Definitief" | "Afgewezen";
export type CalendarOverviewProgramFilter = "all" | CalendarOverviewProgram;
export type CalendarOverviewStatusFilter = "all" | CalendarOverviewStatus;
export type CalendarOverviewStatusPresentation = "option" | "confirmed" | "rejected";

export interface CalendarOverviewStatusOption {
  value: CalendarOverviewStatus;
  label: string;
  shortLabel: string;
  presentation: CalendarOverviewStatusPresentation;
}

export interface CalendarOverviewCapacity {
  totalDaily: number;
  programs: Record<CalendarOverviewProgram, number>;
}

export interface CalendarOverviewAggregate {
  program: CalendarOverviewProgram;
  status: CalendarOverviewStatus;
  bookingCount: number;
  studentCount: number;
  unknownStudentCount: number;
  invalidStudentCount: number;
}

export interface CalendarOverviewDay {
  date: string;
  weekday: number;
  inSelectedMonth: boolean;
  isPast: boolean;
  isToday: boolean;
  isBookableWeekday: boolean;
  disabled: null | { type: "manual" | "school_vacation" | "weekend"; source: "generated" | "planner"; label: string };
  hasBookingsOnBlockedDate: boolean;
  capacity: CalendarOverviewCapacity;
  aggregates: CalendarOverviewAggregate[];
}

export interface DashboardCalendarOverview {
  period: { year: number; month: number; monthStart: string; monthEnd: string; gridStart: string; gridEnd: string };
  generatedAt: string;
  timezone: "Europe/Amsterdam";
  filters: {
    programs: Array<{ value: CalendarOverviewProgram; label: string }>;
    statuses: CalendarOverviewStatusOption[];
  };
  capacity: CalendarOverviewCapacity;
  days: CalendarOverviewDay[];
}

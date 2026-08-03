export type CalendarOverviewProgram = "dag" | "ochtend";
export type CalendarOverviewStatus = "In optie" | "Definitief" | "Afgewezen";
export type CalendarOverviewProgramFilter = "all" | CalendarOverviewProgram;
export type CalendarOverviewStatusFilter = "all" | CalendarOverviewStatus;

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
  hasExcludedBookingsOnBlockedDate: boolean;
  aggregates: CalendarOverviewAggregate[];
}

export interface DashboardCalendarOverview {
  period: { year: number; month: number; monthStart: string; monthEnd: string; gridStart: string; gridEnd: string };
  generatedAt: string;
  timezone: "Europe/Amsterdam";
  filters: {
    programs: Array<{ value: CalendarOverviewProgram; label: string }>;
    statuses: Array<{ value: CalendarOverviewStatus; label: string; shortLabel: string; presentation: "option" | "confirmed" | "rejected" }>;
  };
  days: CalendarOverviewDay[];
}

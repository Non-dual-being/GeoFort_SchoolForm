export type DashboardCalendarDayState = "available" | "limited" | "full" | "blocked" | "past";

export interface DashboardCalendarWarning {
  code: string;
  title: string;
  description: string;
}

export interface DashboardCalendarBooking {
  id: number;
  status: string;
  schoolName: string;
  program: string;
  programLabel: string;
  studentCount: number | null;
  active: boolean;
}

export interface DashboardCalendarDay {
  date: string;
  weekday: number;
  isPast: boolean;
  manuallyBlocked: boolean;
  manualBlockReason: string | null;
  disabledType: string | null;
  canBlockManually: boolean;
  canReleaseManualBlock: boolean;
  bookingCount: number;
  optionBookingCount: number;
  confirmedBookingCount: number;
  otherBookingCount: number;
  studentCount: number;
  confirmedStudentCount: number;
  maximumCapacity: number | null;
  remainingCapacity: number | null;
  programs: string[];
  state: DashboardCalendarDayState;
  warnings: DashboardCalendarWarning[];
  bookings: DashboardCalendarBooking[];
  disabledReason: string | null;
}

export interface DashboardCalendar {
  startDate: string;
  endDate: string;
  managementPolicy: { reasonMinLength: number; reasonMaxLength: number; maxPeriodDays: number };
  days: DashboardCalendarDay[];
}

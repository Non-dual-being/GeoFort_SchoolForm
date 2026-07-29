export type CalendarDateAction = "block_single" | "block_period" | "release_single" | "release_period";
export type CalendarManagementMode = "active-bookings" | "available-management" | "manually-blocked";

export interface CalendarDateManagementIssue {
  code: string;
  field: string;
  title: string;
  description: string;
  metadata: Record<string, unknown>;
}

export interface CalendarDateManagementPreview {
  action: CalendarDateAction;
  startDate: string;
  endDate: string;
  calendarDayCount: number;
  bookingCount: number;
  studentCount: number;
  fingerprint: string;
  activeBookingsFingerprint: string;
  categories: {
    affectedDates: string[];
    weekendDates: string[];
    existingManualDates: Array<{ date: string; reason: string | null }>;
    otherBlockedDates: Array<{ date: string; type: string; reason: string | null }>;
    activeBookingDates: string[];
    activeBookings: Array<{ id: number; date: string; status: string; studentCount: number | null }>;
  };
}

export interface CalendarDateManagementResponse {
  ok: boolean;
  code: string;
  data?: {
    startDate: string;
    endDate: string;
    affectedCount: number;
    preview: CalendarDateManagementPreview | null;
  };
  issues: CalendarDateManagementIssue[];
}

export interface CalendarDatePreviewResponse {
  ok: boolean;
  code: string;
  data?: CalendarDateManagementPreview;
  issues: CalendarDateManagementIssue[];
}

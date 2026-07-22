import type { BookingAttendanceChangeCode, BookingAttendanceRequest } from "../types/bookingAttendance";
import type { BookingRuleOverrideRequest, BookingValidationIssue } from "../types/bookingStatus";

export function createBookingAttendanceRequest(input: Omit<BookingAttendanceRequest, "overrides">, overrides: BookingRuleOverrideRequest[] = []): BookingAttendanceRequest {
  return { ...input, overrides };
}

export function attendanceResultRequiresRefresh(code: BookingAttendanceChangeCode): boolean {
  return code === "SUCCESS" || code === "ATTENDANCE_CONFLICT";
}

export function reconcileAttendanceOverrideState(issues: BookingValidationIssue[], currentReasons: Record<string, string>): { issues: BookingValidationIssue[]; reasons: Record<string, string> } {
  const currentIssues = issues.filter((issue) => issue.overridable);
  const reasons: Record<string, string> = {};
  for (const issue of currentIssues) reasons[issue.code] = currentReasons[issue.code] ?? "";
  return { issues: currentIssues, reasons };
}

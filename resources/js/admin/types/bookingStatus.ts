export const BOOKING_STATUSES = ["In optie", "Definitief", "Afgewezen"] as const;
export type BookingStatus = typeof BOOKING_STATUSES[number];
export type BookingStatusMailMode = "none" | "send";

export type BookingStatusChangeCode =
  | "SUCCESS" | "BOOKING_NOT_FOUND" | "STATUS_CONFLICT" | "NO_STATUS_CHANGE"
  | "INVALID_CURRENT_STATUS" | "INVALID_TARGET_STATUS" | "INVALID_STORED_BOOKING"
  | "HISTORICAL_DATE" | "DISABLED_DATE" | "SCHOOL_LIMIT_EXCEEDED"
  | "STUDENT_LIMIT_EXCEEDED" | "INVALID_STUDENT_COUNT" | "DATABASE_ERROR"
  | "MAIL_SEND_FAILED" | "MAIL_STATUS_RECORDING_FAILED" | "MAIL_NOT_SUPPORTED_FOR_TARGET_STATUS" | "INVALID_MAIL_MODE"
  | "OVERRIDE_REQUIRED" | "INVALID_OVERRIDE_REQUEST" | "OVERRIDE_NOT_ALLOWED"
  | "OVERRIDE_REASON_REQUIRED" | "OVERRIDE_PERMISSION_DENIED"
  | "LEGACY_PRICE_ACCEPTANCE_REQUIRED" | "PRICE_SNAPSHOT_REQUIRED"
  | "UNAUTHENTICATED" | "INVALID_CSRF" | "INVALID_REQUEST" | "MALFORMED_JSON";

export interface BookingStatusChangeResponse {
  ok: boolean;
  code: BookingStatusChangeCode;
  bookingId: number;
  previousStatus: BookingStatus | null;
  currentStatus: BookingStatus | null;
  mailMode: BookingStatusMailMode;
  mailSent: boolean;
  validationIssues: BookingValidationIssue[];
  capacity: { code: string; allowed: boolean; projectedSchools: number; projectedStudents: number | null } | null;
  overriddenRules?: Array<{ ruleCode: string }>;
  overrideCount?: number;
}

export interface BookingRuleOverrideRequest { ruleCode: string; reason: string }
export interface BookingValidationIssue {
  code: string; category: string; field: string; severity: "error" | "warning" | "information";
  overridable: boolean; title: string; description: string; metadata: Record<string, string | number | boolean | null>;
}

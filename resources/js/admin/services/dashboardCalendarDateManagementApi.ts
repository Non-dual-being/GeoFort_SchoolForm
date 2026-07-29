import type {
  CalendarDateAction,
  CalendarDateManagementResponse,
  CalendarDatePreviewResponse,
} from "../types/calendarDateManagement";

export class CalendarDateManagementApiError extends Error {
  constructor(
    public readonly result: CalendarDateManagementResponse | CalendarDatePreviewResponse,
    public readonly status: number,
  ) {
    super(result.issues[0]?.description ?? "De kalenderwijziging kon niet worden verwerkt.");
  }
}

async function post<T extends CalendarDateManagementResponse | CalendarDatePreviewResponse>(
  url: string,
  body: unknown,
  csrfToken: string,
  signal?: AbortSignal,
): Promise<T> {
  const response = await fetch(url, {
    method: "POST",
    headers: { Accept: "application/json", "Content-Type": "application/json", "X-CSRF-Token": csrfToken },
    body: JSON.stringify(body),
    signal,
  });
  const result = await response.json() as T;
  if (!response.ok || !result.ok) throw new CalendarDateManagementApiError(result, response.status);
  return result;
}

export function previewDashboardCalendarDateManagement(
  body: { startDate: string; endDate: string; action: CalendarDateAction },
  csrfToken: string,
  signal?: AbortSignal,
): Promise<CalendarDatePreviewResponse> {
  return post("/api/admin/calendar/date-management-preview.php", body, csrfToken, signal);
}

export function manageDashboardCalendarDate(
  body: {
    expected: { startDate: string; endDate: string; previewFingerprint: string; activeBookingsFingerprint: string };
    proposed: {
      action: CalendarDateAction;
      reason: string | null;
      confirmed: boolean;
      existingBookingsAccepted: boolean;
    };
  },
  csrfToken: string,
): Promise<CalendarDateManagementResponse> {
  return post("/api/admin/calendar/manage-date.php", body, csrfToken);
}

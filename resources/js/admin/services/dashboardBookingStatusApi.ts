import type { BookingStatus, BookingStatusChangeResponse } from "../types/bookingStatus";

export class BookingStatusApiError extends Error {
  constructor(public readonly status: number, public readonly result: BookingStatusChangeResponse | null) {
    super("Statuswijziging mislukt");
  }
}

export async function updateDashboardBookingStatus(
  body: { bookingId: number; expectedCurrentStatus: BookingStatus; targetStatus: BookingStatus },
  csrfToken: string,
): Promise<BookingStatusChangeResponse> {
  const response = await fetch("/api/admin/requests/update-status.php", {
    method: "POST",
    headers: { Accept: "application/json", "Content-Type": "application/json", "X-CSRF-Token": csrfToken },
    body: JSON.stringify(body),
  });
  const result = await readResult(response);
  if (!response.ok || !result?.ok) throw new BookingStatusApiError(response.status, result);
  return result;
}

async function readResult(response: Response): Promise<BookingStatusChangeResponse | null> {
  try { return await response.json() as BookingStatusChangeResponse; } catch { return null; }
}

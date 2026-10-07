import type { PlannerRosterAttachment } from "../types/bookingStatus";

export interface PreparedPlannerRosterDocument {
  snapshotId: number;
  filename: string;
  mimeType: "application/pdf";
  sha256: string;
  byteLength: number;
}

async function errorMessage(response: Response): Promise<string> {
  if (response.status === 401) return "Je sessie is verlopen. Log opnieuw in om het schoolrooster te bekijken.";
  try {
    const body = await response.json() as { message?: string };
    if (body.message) return body.message;
  } catch { /* Keep a useful persistent error for non-JSON responses. */ }
  return "Het definitieve schoolrooster kon niet worden geladen. Probeer het opnieuw.";
}

export async function getPlannerRosterAttachment(bookingId: number): Promise<PlannerRosterAttachment | null> {
  const query = new URLSearchParams({ bookingId: String(bookingId) });
  const response = await fetch(`/api/admin/requests/roster-attachment.php?${query}`, {
    credentials: "same-origin", headers: { Accept: "application/json" }, cache: "no-store",
  });
  if (!response.ok) throw new Error(await errorMessage(response));
  const body = await response.json() as { ok: boolean; data?: { plannerFinal: PlannerRosterAttachment | null } };
  if (!body.ok || !body.data) throw new Error("De roosterkeuze kon niet worden geladen.");
  return body.data.plannerFinal;
}

export async function preparePlannerRoster(bookingId: number, snapshotId: number, csrfToken: string): Promise<PreparedPlannerRosterDocument> {
  const response = await fetch("/api/admin/requests/prepare-roster-document.php", {
    method: "POST", credentials: "same-origin", cache: "no-store",
    headers: { Accept: "application/json", "Content-Type": "application/json", "X-CSRF-Token": csrfToken },
    body: JSON.stringify({ bookingId, snapshotId }),
  });
  if (!response.ok) throw new Error(await errorMessage(response));
  const body = await response.json() as { ok: boolean; data?: { document: PreparedPlannerRosterDocument } };
  if (!body.ok || body.data?.document?.snapshotId !== snapshotId) {
    throw new Error("Het gekozen schoolrooster kon niet worden voorbereid. Open de roosterkeuze opnieuw.");
  }
  return body.data.document;
}

export async function downloadPlannerRoster(bookingId: number, snapshotId: number): Promise<void> {
  const query = new URLSearchParams({ bookingId: String(bookingId), snapshotId: String(snapshotId) });
  const response = await fetch(`/api/admin/rosters/school-pdf.php?${query}`, {
    credentials: "same-origin", headers: { Accept: "application/pdf, application/json" }, cache: "no-store",
  });
  if (!response.ok) throw new Error(await errorMessage(response));
  if (!response.headers.get("Content-Type")?.toLowerCase().startsWith("application/pdf")) {
    throw new Error("De download bevat geen geldig schoolrooster.");
  }
  const url = URL.createObjectURL(await response.blob());
  try {
    const link = document.createElement("a");
    link.href = url;
    link.download = response.headers.get("Content-Disposition")?.match(/filename="([A-Za-z0-9_.-]+\.pdf)"/i)?.[1]
      ?? "GeoFort_Definitief_Lesrooster.pdf";
    link.hidden = true;
    document.body.append(link);
    link.click();
    link.remove();
  } finally { URL.revokeObjectURL(url); }
}

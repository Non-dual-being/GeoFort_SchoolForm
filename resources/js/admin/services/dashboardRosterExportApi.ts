import { filenameFromDisposition } from "./dashboardBookingExportApi";

interface ErrorBody {
  message?: string;
  errors?: Record<string, string>;
  fieldErrors?: Record<string, string>;
}

export async function downloadDashboardRosterXlsx(planId: number, joint = false): Promise<string> {
  const query = new URLSearchParams({ id: String(planId) });
  if (joint) query.set("joint", "1");
  return downloadXlsx(`/api/admin/rosters/export-xlsx.php?${query}`);
}

export async function downloadDashboardRosterWeekXlsx(week: string): Promise<string> {
  return downloadXlsx(`/api/admin/rosters/week-export.php?${new URLSearchParams({ week })}`);
}

async function downloadXlsx(url: string): Promise<string> {
  const response = await fetch(url, {
    method: "GET",
    credentials: "same-origin",
    headers: {
      Accept: "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet, application/json",
    },
  });

  if (!response.ok) {
    throw new Error(await readErrorMessage(response));
  }

  const contentType = (response.headers.get("Content-Type") ?? "").toLowerCase();
  if (!contentType.includes("application/vnd.openxmlformats-officedocument.spreadsheetml.sheet")) {
    throw new Error("De Excel-download gaf geen geldig spreadsheet terug.");
  }

  const blob = await response.blob();
  const filename = filenameFromDisposition(response.headers.get("Content-Disposition"))
    .replace(/\.csv$/i, ".xlsx");
  const objectUrl = URL.createObjectURL(blob);

  try {
    const link = document.createElement("a");
    link.href = objectUrl;
    link.download = filename;
    link.hidden = true;
    document.body.append(link);
    link.click();
    link.remove();
  } finally {
    URL.revokeObjectURL(objectUrl);
  }

  return filename;
}

async function readErrorMessage(response: Response): Promise<string> {
  const contentType = (response.headers.get("Content-Type") ?? "").toLowerCase();
  if (!contentType.includes("application/json")) {
    return "Excel-exporteren is niet gelukt. Probeer het opnieuw.";
  }

  try {
    const body = await response.json() as ErrorBody;
    const fieldErrors = body.fieldErrors ?? body.errors;
    const validationMessage = fieldErrors
      ? Object.values(fieldErrors).find((message) => typeof message === "string")
      : undefined;

    return validationMessage
      ?? body.message
      ?? "Excel-exporteren is niet gelukt. Probeer het opnieuw.";
  } catch {
    return "Excel-exporteren is niet gelukt. Probeer het opnieuw.";
  }
}
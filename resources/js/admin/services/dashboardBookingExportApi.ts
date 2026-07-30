import { getApiData } from "../../services/http/apiClient";
import type {
  BookingExportBounds,
  BookingExportSummaryResponse,
} from "../types/bookingExport";

interface ErrorBody {
  message?: string;
  errors?: Record<string, string>;
  fieldErrors?: Record<string, string>;
}

export async function downloadDashboardBookingsCsv(
  startDate: string,
  endDate: string,
): Promise<string> {
  const params = new URLSearchParams({ startDate, endDate });
  const query = params.toString();
  const response = await fetch(
    `/api/admin/requests/export-csv.php${query === "" ? "" : `?${query}`}`,
    {
      method: "GET",
      credentials: "same-origin",
      headers: {
        Accept: "text/csv, application/json",
      },
    },
  );

  if (!response.ok) {
    throw new Error(await readErrorMessage(response));
  }

  const contentType = response.headers.get("Content-Type") ?? "";
  if (!contentType.toLowerCase().startsWith("text/csv")) {
    throw new Error("De downloadsessie is verlopen. Vernieuw de pagina en log zo nodig opnieuw in.");
  }

  const blob = await response.blob();
  const filename = filenameFromDisposition(
    response.headers.get("Content-Disposition"),
  );
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

export function fetchBookingExportBounds(
  signal?: AbortSignal,
): Promise<{ bounds: BookingExportBounds }> {
  return getApiData("/api/admin/requests/export-metadata.php", {
    method: "GET",
    signal,
  });
}

export function fetchBookingExportSummary(
  startDate: string,
  endDate: string,
  signal?: AbortSignal,
): Promise<BookingExportSummaryResponse> {
  const query = new URLSearchParams({ startDate, endDate });
  return getApiData(`/api/admin/requests/export-summary.php?${query}`, {
    method: "GET",
    signal,
  });
}

export function filenameFromDisposition(value: string | null): string {
  const fallback = "onderwijsboekingen.csv";
  if (!value) return fallback;

  const utf8 = value.match(/filename\*=UTF-8''([^;]+)/i);
  const quoted = value.match(/filename="([^"]+)"/i);
  const plain = value.match(/filename=([^;]+)/i);
  const candidate = utf8?.[1]
    ? decodeURIComponentSafely(utf8[1])
    : (quoted?.[1] ?? plain?.[1]?.trim() ?? "");
  const basename = candidate.replaceAll("\\", "/").split("/").pop()?.trim();

  return basename && basename.toLowerCase().endsWith(".csv")
    ? basename
    : fallback;
}

async function readErrorMessage(response: Response): Promise<string> {
  const contentType = response.headers.get("Content-Type") ?? "";
  if (!contentType.toLowerCase().includes("application/json")) {
    return "Exporteren is niet gelukt. Probeer het opnieuw.";
  }
  try {
    const body = await response.json() as ErrorBody;
    const fieldErrors = body.fieldErrors ?? body.errors;
    const validationMessage = fieldErrors
      ? Object.values(fieldErrors).find((message) => typeof message === "string")
      : undefined;
    return validationMessage
      ?? body.message
      ?? "Exporteren is niet gelukt. Probeer het opnieuw.";
  } catch {
    return "Exporteren is niet gelukt. Probeer het opnieuw.";
  }
}

function decodeURIComponentSafely(value: string): string {
  try {
    return decodeURIComponent(value);
  } catch {
    return "";
  }
}

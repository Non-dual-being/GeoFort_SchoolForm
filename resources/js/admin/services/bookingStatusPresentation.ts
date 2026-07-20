import type { BookingStatus, BookingStatusChangeCode, BookingStatusMailMode } from "../types/bookingStatus";

const messages: Partial<Record<BookingStatusChangeCode, string>> = {
  SUCCESS: "De status is gewijzigd.",
  STATUS_CONFLICT: "De aanvraag is ondertussen door iemand anders gewijzigd. De actuele gegevens zijn opnieuw geladen.",
  NO_STATUS_CHANGE: "De aanvraag heeft deze status al.",
  DISABLED_DATE: "Deze bezoekdatum is geblokkeerd en kan niet definitief worden gemaakt.",
  HISTORICAL_DATE: "Een aanvraag met een verstreken bezoekdatum kan niet definitief worden gemaakt.",
  SCHOOL_LIMIT_EXCEEDED: "Het maximale aantal scholen voor deze datum is bereikt.",
  STUDENT_LIMIT_EXCEEDED: "Het maximale aantal leerlingen voor deze datum wordt overschreden.",
  INVALID_STUDENT_COUNT: "Het leerlingenaantal van deze aanvraag is ongeldig.",
  INVALID_STORED_BOOKING: "De aanvraag bevat gegevens die eerst moeten worden gecontroleerd of gecorrigeerd.",
  DATABASE_ERROR: "De status kon niet worden gewijzigd. Probeer het opnieuw.",
  MAIL_SEND_FAILED: "De e-mail kon niet worden verzonden. De status is niet gewijzigd.",
  MAIL_NOT_SUPPORTED_FOR_TARGET_STATUS: "Voor deze status kan geen e-mail worden verstuurd.",
};

export function statusChangeMessage(code: BookingStatusChangeCode, mailMode: BookingStatusMailMode): string {
  if (code === "SUCCESS" && mailMode === "send") return "De status is gewijzigd en de e-mail is verstuurd.";
  return messages[code] ?? "De status kon niet worden gewijzigd. Probeer het opnieuw.";
}

export function statusConfirmation(target: BookingStatus, mailMode: BookingStatusMailMode): string {
  if (target === "Definitief" && mailMode === "send") return "De aanvraag wordt opnieuw gecontroleerd, de actuele prijs wordt berekend, de status wordt definitief en de bevestigingsmail wordt verstuurd. Bij een mailfout blijft de status ongewijzigd.";
  if (target === "Afgewezen" && mailMode === "send") return "De status wordt afgewezen en een afwijzingsmail wordt verstuurd. Bij een mailfout blijft de status ongewijzigd.";
  if (target === "Definitief") return "De aanvraag wordt opnieuw gecontroleerd. Datum-, inhouds- en capaciteitsregels worden opnieuw toegepast. Er wordt geen e-mail verstuurd.";
  if (target === "Afgewezen") return "Bevestig dat de aanvraag wordt afgewezen. Er wordt geen e-mail verstuurd.";
  return "Bevestig dat de aanvraag wordt teruggezet naar In optie. Er wordt geen e-mail verstuurd.";
}

export function shouldRefreshAfterStatusResult(code: BookingStatusChangeCode): boolean {
  return ["SUCCESS", "STATUS_CONFLICT", "NO_STATUS_CHANGE", "DATABASE_ERROR", "MAIL_SEND_FAILED"].includes(code);
}

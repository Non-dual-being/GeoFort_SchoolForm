import type { AdminBootstrapData, AdminFlashMessage, AdminFlashType, AdminUser } from "./types";

function isObject(value: unknown): value is Record<string, unknown> {
  return typeof value === "object" && value !== null;
}

function isFlashType(value: unknown): value is AdminFlashType {
  return value === "success" || value === "error" || value === "info";
}

function isAdminUser(value: unknown): value is AdminUser {
  return isObject(value)
    && typeof value.id === "number"
    && Number.isInteger(value.id)
    && value.id > 0
    && typeof value.name === "string"
    && typeof value.email === "string"
    && typeof value.role === "string"
    && (value.previousLoginAt === null || typeof value.previousLoginAt === "string");
}

function isFlashMessage(value: unknown): value is AdminFlashMessage {
  return isObject(value)
    && typeof value.message === "string"
    && isFlashType(value.type)
    && typeof value.autoDismiss === "boolean";
}

function isAdminBootstrapData(value: unknown): value is AdminBootstrapData {
  return isObject(value)
    && isAdminUser(value.user)
    && typeof value.logoutCsrfToken === "string"
    && typeof value.bookingStatusCsrfToken === "string"
    && typeof value.bookingAttendanceCsrfToken === "string"
    && typeof value.bookingCateringCsrfToken === "string"
    && typeof value.bookingSchoolContactCsrfToken === "string"
    && typeof value.bookingVisitDateCsrfToken === "string"
    && typeof value.bookingProgramCsrfToken === "string"
    && typeof value.bookingProgramConfigurationCsrfToken === "string"
    && typeof value.calendarDateManagementCsrfToken === "string"
    && typeof value.capacityTargetCsrfToken === "string"
    && Array.isArray(value.flashMessages)
    && value.flashMessages.every(isFlashMessage)
    && typeof value.publicBookingUrl === "string"
    && value.publicBookingUrl.startsWith("/")
    && !value.publicBookingUrl.startsWith("//")
    && typeof value.dashboardTitle === "string"
    && (value.environment === "development" || value.environment === "production");
}

export function readAdminBootstrapData(): AdminBootstrapData {
  const element = document.querySelector<HTMLScriptElement>("#admin-bootstrap-data");

  if (!element) {
    throw new Error("Admin bootstrapdata ontbreekt.");
  }

  let parsed: unknown;
  try {
    parsed = JSON.parse(element.textContent ?? "");
  } catch {
    throw new Error("Admin bootstrapdata bevat ongeldige JSON.");
  }

  if (!isAdminBootstrapData(parsed)) {
    throw new Error("Admin bootstrapdata heeft een ongeldige structuur.");
  }

  return parsed;
}

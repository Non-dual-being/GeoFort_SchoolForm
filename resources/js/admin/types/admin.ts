import type { InjectionKey } from "vue";

export type AdminRole = "admin" | string;
export type AdminFlashType = "success" | "error" | "info";

export interface AdminUser {
  id: number;
  name: string;
  email: string;
  role: AdminRole;
  previousLoginAt: string | null;
}

export interface AdminFlashMessage {
  message: string;
  type: AdminFlashType;
  autoDismiss: boolean;
}

export interface AdminBootstrapData {
  user: AdminUser;
  logoutCsrfToken: string;
  bookingStatusCsrfToken: string;
  bookingAttendanceCsrfToken: string;
  bookingCateringCsrfToken: string;
  bookingSchoolContactCsrfToken: string;
  bookingCjpCsrfToken: string;
  bookingVisitDateCsrfToken: string;
  bookingProgramCsrfToken: string;
  bookingProgramConfigurationCsrfToken: string;
  flashMessages: AdminFlashMessage[];
  publicBookingUrl: string;
  dashboardTitle: string;
  environment: "development" | "production";
}

export const adminBootstrapKey: InjectionKey<AdminBootstrapData> = Symbol("adminBootstrap");

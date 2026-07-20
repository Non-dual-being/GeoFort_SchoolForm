import type { BookingStatus } from "./bookingStatus";

export interface DashboardBookingDetailResponse {
  booking: DashboardBookingDetail;
}

export interface DashboardBookingDetail {
  id: number;
  status: BookingStatus;
  visitDate: string;
  school: {
    name: string;
    country: string;
    address: string;
    postalCode: string;
    city: string;
    phone: string;
  };
  contact: {
    firstName: string;
    lastName: string;
    email: string;
    phone: string;
  };
  education: {
    sector: string;
    sectorLabel: string;
    program: string;
    programLabel: string;
    module: string | null;
    moduleLabel: string | null;
    studentCount: number | null;
    supervisorCount: number | null;
    selections: DashboardEducationSelection[];
  };
  foodAndDrink: {
    remiseBreak: number;
    kazerneBreak: number;
    fortgrachtBreak: number;
    waterIce: number;
    lemonade: number;
    remiseLunch: number;
    ownPicnic: boolean;
  };
  additional: {
    cjpDiscount: boolean;
    cjpContactName: string | null;
    cjpCardNumber: string | null;
    referralSource: string | null;
    remarks: string | null;
  };
  metadata: {
    legacyImported: boolean;
    legacySourceSystem: string | null;
    legacySourceId: number | null;
  };
}

export interface DashboardEducationSelection {
  levelKey: string;
  levelLabel: string;
  groups: Array<{
    groupKey: string;
    groupLabel: string;
  }>;
}

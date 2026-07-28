import type { BookingStatus } from "./bookingStatus";
import type { BookingCateringOptions,BookingLunchReadChoice } from "./bookingCatering";
import type {EducationModuleFiltersConfig,EducationModulesConfig,EducationModuleKey} from "../../types/booking/BookingProgramConfigTypes";

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
    programOptions: Array<{key:string;label:string;description:string[];allowedSchoolTypes:string[];allowedWeekdays:number[]}>;
    module: string | null;
    moduleLabel: string | null;
    studentCount: number | null;
    supervisorCount: number | null;
    selections: DashboardEducationSelection[];
    configuration: {
      schoolLevels: Record<string,{label:string;groups:Record<string,string>}>;
      selectionRules: {minLevels:number;maxLevels:number;minGroupsPerLevel:number;maxGroupsPerLevel:number};
      studentLimitsByProgram: Record<string,{minimum:number;maximum:number}>;
      modules: EducationModulesConfig;
      moduleLabels: Record<EducationModuleKey,string>;
      moduleFilters: EducationModuleFiltersConfig;
    };
  };
  foodAndDrink: {
    remiseBreak: number;
    kazerneBreak: number;
    fortgrachtBreak: number;
    waterIce: number;
    lemonade: number;
    remiseLunch: number;
    ownPicnic: boolean;
    lunchChoice: BookingLunchReadChoice;
    options: BookingCateringOptions;
    info: {included:Array<{label:string;description:string}>;optional:Record<string,unknown>;notes:string[]};
  };
  additional: {
    cjpUse: string;
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
  priceQuote: { total: { totalInclVat: number; totalExclVat: number }; visit: { studentCount:number; supervisorCount:number; freeSupervisors:number; paidSupervisors:number }; vatPercentage:number } | null;
}

export interface DashboardEducationSelection {
  levelKey: string;
  levelLabel: string;
  groups: Array<{
    groupKey: string;
    groupLabel: string;
  }>;
}

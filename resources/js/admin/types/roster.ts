export type RosterPlanStatus = "concept" | "checked" | "published";

export interface DashboardRosterGroup {
  id: number;
  label: string;
  position: number;
  studentCount: number | null;
}

export interface DashboardRosterPlanSummary {
  id: number;
  bookingId: number | null;
  visitDate: string;
  status: RosterPlanStatus | string;
  revision: number;
  schoolName: string;
  city: string;
  sectorLabel: string;
  programLabel: string;
  moduleLabel: string | null;
  studentCount: number | null;
  groupCount: number;
}

export interface DashboardRosterPlan {
  id: number;
  bookingId: number | null;
  visitDate: string;
  status: RosterPlanStatus | string;
  revision: number;
  sourceCurrent: boolean;
  needsReview: boolean;
  createdAt: string;
  updatedAt: string;
  school: {
    name: string;
    city: string;
  };
  education: {
    sector: string | null;
    sectorLabel: string;
    program: string | null;
    programLabel: string;
    choiceModule: string | null;
    choiceModuleLabel: string | null;
    studentCount: number | null;
  };
  groups: DashboardRosterGroup[];
}

export interface DashboardRosterListResponse {
  items: DashboardRosterPlanSummary[];
}

export interface DashboardRosterDetailResponse {
  plan: DashboardRosterPlan;
}

export interface DashboardRosterCreateResponse {
  created: boolean;
  plan: DashboardRosterPlan;
}
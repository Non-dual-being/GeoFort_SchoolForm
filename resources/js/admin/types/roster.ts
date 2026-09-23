export type RosterPlanStatus = "concept" | "checked" | "published";
export type RosterStaffingMode = "lesson_only" | "with_staff";
export type RosterEmploymentType = "paid" | "volunteer" | string;

export interface DashboardRosterGroup {
  id: number;
  label: string;
  position: number;
  studentCount: number | null;
}

export interface RosterModuleOption {
  key: string;
  label: string;
  color: string;
  defaultLocation: string | null;
  maxParallel: number;
  minimumGeoFortStaff: number;
  schoolSupervisionAllowed: boolean;
}

export interface RosterStaffPreference {
  moduleKey: string;
  moduleLabel: string;
  rank: number;
}

export interface RosterStaffMember {
  id: number;
  name: string;
  isActive: boolean;
  employmentType: RosterEmploymentType;
  canGuide: boolean;
  canCook: boolean;
  preferences: RosterStaffPreference[];
}

export interface RosterStaffAssignment {
  id: number;
  name: string;
}

export interface RosterStaffingSettings {
  staffingMode: RosterStaffingMode;
  preferGeoFortKe: boolean;
  cookStaffId: number | null;
}

export interface DashboardRosterSession {
  id: number;
  moduleKey: string;
  moduleLabel: string;
  color: string;
  startTime: string;
  endTime: string;
  location: string | null;
  groupIds: number[];
  groupLabels: string[];
  minimumGeoFortStaff: number;
  schoolSupervisionAllowed: boolean;
  staffAssignments: RosterStaffAssignment[];
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

export interface RosterGenerationRound {
  label: string;
  startTime: string;
  endTime: string;
}

export interface RosterGenerationProposalSession {
  moduleKey: string;
  moduleLabel: string;
  color: string;
  startTime: string;
  endTime: string;
  roundLabel: string;
  location: string | null;
  groupIds: number[];
  groupLabels: string[];
  minimumGeoFortStaff: number;
  schoolSupervisionAllowed: boolean;
  assignedStaffIds: number[];
  schoolFallback: boolean;
}

export interface RosterGenerationProposal {
  planId: number;
  revision: number;
  existingSessionCount: number;
  rounds: RosterGenerationRound[];
  sessions: RosterGenerationProposalSession[];
  warnings: Array<{ code: string; message: string }>;
  staffing: {
    mode: RosterStaffingMode;
    preferGeoFortKe: boolean;
    minimumSimultaneousGeoFortStaff: number;
    preferredSimultaneousGeoFortStaff: number;
    schoolSupervisionEligibleSessions: number;
    selectedStaffCount: number;
    selectedPeopleCount: number;
    unfilledRequiredAssignments: number;
    cookRequired: boolean;
    cookSelected: boolean;
    cook: null | {
      id: number;
      name: string;
      employmentType: RosterEmploymentType;
      workMinutes: number;
    };
    targetWorkMinutes: number;
    assignmentStatus: string;
    message: string;
    staff: Array<{
      id: number;
      name: string;
      employmentType: RosterEmploymentType;
      load: number;
      workMinutes: number;
      moduleCount: number;
      gaps: number;
      averagePreferenceRank: number | null;
    }>;
  };
  templateNote: string;
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
    contactName: string;
    contactPhone: string;
    supervisorCount: number | null;
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
  planning: {
    modules: RosterModuleOption[];
    sessions: DashboardRosterSession[];
    generation: {
      defaultRounds: RosterGenerationRound[];
      templateNote: string;
    };
    staffCatalog: RosterStaffMember[];
    selectedStaffIds: number[];
    staffingSettings: RosterStaffingSettings;
  };
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
export interface RosterSessionMutationResponse {
  sessionId?: number;
  revision: number;
  warnings?: Array<{ code: string; message: string }>;
}
export interface RosterSessionSaveRequest {
  planId: number;
  sessionId: number | null;
  expectedRevision: number;
  moduleKey: string;
  startTime: string;
  endTime: string;
  location: string | null;
  groupIds: number[];
  staffIds?: number[];
  cookStaffId?: number | null;
}

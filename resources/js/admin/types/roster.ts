export type RosterPlanStatus = "concept" | "checked" | "published" | "archived";
export interface RosterJointPlanning { id: number; revision: number; visitDate: string; workflow: "lessons_first" | "school_first"; planIds: number[]; }
export type RosterSupervisionKind = "staff" | "school" | "open";
export interface RosterValidationIssue {
  code: string;
  severity: "error" | "warning";
  message: string;
  sessionId?: number;
  groupId?: number;
  staffId?: number;
  otherPlanId?: number;
  moduleKey?: string;
}
export interface RosterValidationResult {
  planId: number;
  revision: number;
  valid: boolean;
  errors: RosterValidationIssue[];
  warnings: RosterValidationIssue[];
}
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

export interface RosterPlannerModulePreference {
  staffId: number;
  moduleKeys: string[];
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

export interface RosterGroupStaffAssignment {
  groupId: number;
  staffAssignments: RosterStaffAssignment[];
}

export interface RosterLessonPreferences {
  allowLateVi?: boolean;
  moduleOrder: string[];
  supervision: Record<string, "geofort" | "school">;
}

export interface RosterStaffingSettings {
  staffingMode: RosterStaffingMode;
  preferGeoFortKe: boolean;
  cookStaffId: number | null;
  plannerModulePreferences?: RosterPlannerModulePreference[];
}

export interface DashboardRosterSession {
  cookStaffId?: number | null;
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
  groupStaffAssignments?: RosterGroupStaffAssignment[];
  groupSupervision?: Array<{ groupId: number; kind: RosterSupervisionKind }>;
}

export interface DashboardRosterPlanSummary {
  programVariant?: string;
  recommendedGroupCount?: number | null;
  bookingStatus?: string | null;
  jointPlanning?: RosterJointPlanning | null;
  archivedAt?: string;
  id: number;
  bookingId: number | null;
  planningMode: "booking_locked" | "custom";
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

// Tijdvakken van een zelfstandig rooster; modules horen bij de latere sessies.
export interface RosterManualTimeBlock extends RosterGenerationRound {
  type: "welcome" | "round" | "break" | "lunch" | "departure";
}

export interface RosterGenerationProposalSession {
  existingSessionId?: number;
  groupStaffAssignments?: Array<{ groupId: number; kind: "open" | "staff" | "school"; assignedStaffIds: number[] }>;
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

export interface RosterGenerationHardIssue {
  code: string;
  message: string;
  moduleKey: string | null;
  moduleLabel: string | null;
  startTime: string | null;
  endTime: string | null;
  groupLabels: string[];
  requiredStaff: number | null;
  assignedStaff: number | null;
  eligibleStaffNames: string[];
}

export interface RosterGuidedStaffingMetrics {
  gapMinutes: number;
  paidGapMinutes: number;
  unfilledAssignments: number;
  staff: Array<{ id: number; name: string; employmentType: string; workMinutes: number; gapMinutes: number }>;
}

export interface RosterGenerationProposal {
  optimizeExistingLessons?: boolean;
  optimization?: {
    before: RosterGuidedStaffingMetrics;
    after: RosterGuidedStaffingMetrics;
    candidateCount: number;
    searchLimited: boolean;
    changes: Array<{ sessionId: number; moduleLabel: string; groupLabels: string[]; fromStart: string; fromEnd: string; toStart: string; toEnd: string }>;
  };
  staffingSignature?: string;
  useExistingLessons?: boolean;
  lessonPreferences?: RosterLessonPreferences | null;
  lessonConflictSignature?: string | null;
  catalogRevision: number;
  planId: number;
  revision: number;
  existingSessionCount: number;
  rounds: RosterGenerationRound[];
  sessions: RosterGenerationProposalSession[];
  warnings: Array<{ code: string; message: string }>;
  hardIssues: RosterGenerationHardIssue[];
  canApply: boolean;
  canFinalize: boolean;
  diagnostics: {
    compressionLevel: number;
    candidateCount: number;
  };
  staffing: {
    mode: RosterStaffingMode;
    preferGeoFortKe: boolean;
    plannerModulePreferences: RosterPlannerModulePreference[];
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
  recommendedGroupCount?: number | null;
  bookingVisitDate?: string | null;
  jointPlanning?: RosterJointPlanning | null;
  archivedAt?: string;
  finalization?: {
    trusted: boolean;
    snapshotId: number | null;
    sourceRevision: number | null;
    publishedRevision: number | null;
    finalizedAt: string | null;
    historyCount: number;
  };
  id: number;
  bookingId: number | null;
  planningMode: "booking_locked" | "custom";
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
    address?: string;
    postalCode?: string;
    country?: string;
    schoolPhone?: string;
    contactEmail?: string;
  };
  education: {
    sector: string | null;
    sectorLabel: string;
    program: string | null;
    programVersion?: string | null;
    programLabel: string;
    choiceModule: string | null;
    choiceModuleLabel: string | null;
    studentCount: number | null;
    selectionLabels?: string[];
  };
  groups: DashboardRosterGroup[];
  planning: {
    modules: RosterModuleOption[];
    rounds?: RosterManualTimeBlock[];
    roundsSource?: "default" | "saved" | "booking_standard";
    sessions: DashboardRosterSession[];
    generation: {
      defaultRounds: RosterGenerationRound[];
      templateNote: string;
    };
    staffCatalog: RosterStaffMember[];
    catalogRevision?: number;
    selectedStaffIds: number[];
    staffingSettings: RosterStaffingSettings;
    lessonPreferences?: RosterLessonPreferences;
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
export interface DashboardRosterDeleteResponse {
  deleted: true;
  planId: number;
}

export interface DashboardRosterManualCreateRequest {
  allowWeekdayOverride?: boolean;
  bookingId?: number | null;
  schoolName: string;
  city: string;
  visitDate: string;
  sector: string;
  program: string;
  studentCount: number;
  groupCount: number;
}

export interface DashboardRosterConvertToCustomRequest {
  planId: number;
  expectedRevision: number;
}

export interface DashboardRosterConvertToCustomResponse {
  plan: DashboardRosterPlan;
}

export type DashboardRosterManualProgramSaveRequest = {
  groupCount?: number;
  planId: number;
  expectedRevision: number;
} & (
  | { moduleKeys: string[]; rounds?: RosterManualTimeBlock[] }
  | { moduleKeys?: string[]; rounds: RosterManualTimeBlock[] }
);

export interface DashboardRosterManualProgramSaveResponse {
  plan: DashboardRosterPlan;
}
export interface RosterSessionResetResponse {
  planId: number;
  revision: number;
  deletedSessionCount: number;
  alreadyEmpty: boolean;
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
  staffGroupId?: number | null;
  supervisionKind?: RosterSupervisionKind;
  cookStaffId?: number | null;
}

export interface RosterDayPreview {
  savedControl: {
    selectedPlanIds: number[];
    plans: Record<string, RosterValidationResult>;
    canFinalize: boolean;
    day: {
      visitDate: string;
      errors: RosterValidationIssue[];
      warnings: RosterValidationIssue[];
    };
  };
  lessons: {
    status: "candidate_found" | "no_candidate_found" | "saved_lessons_invalid";
    checkedCombinations: number;
    errors: RosterValidationIssue[];
    conflicts: RosterValidationIssue[];
    schools: Array<{
      planId: number;
      schoolName: string;
      isProposal: boolean;
      sessions: RosterGenerationProposalSession[];
    }>;
  };
}

export interface RosterBookingRefreshPreview {
  changeType: "program" | "choice_module" | "groups" | "details" | "unresolved";
  changes: Array<{ label: string; before: string; after: string }>;
  resetLessons: boolean;
  clearStaffing: boolean;
  staffedLessonCount: number;
  planId: number;
  revision: number;
  studentCount: number;
  beforeGroupCount: number;
  afterGroupCount: number;
  beforeDate: string;
  afterDate: string;
  removedGroups: Array<{ id: number; label: string }>;
  addedGroupCount: number;
  affectedLessons: Array<{ id: number; moduleLabel: string; groupLabels: string[]; startTime: string; endTime: string; remainingGroupIds: number[]; removedGroupLabels: string[] }>;
  preservedLessonCount: number;
  remainingLessonCount: number;
  moduleLabels: string[];
  canApply: boolean;
  blockedReason: string | null;
  signature: string;
}

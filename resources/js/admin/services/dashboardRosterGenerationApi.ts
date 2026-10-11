import { getApiData } from "../../services/http/apiClient";
import type {
  RosterGenerationProposal,
  RosterGenerationRound,
  RosterPlannerModulePreference,
  RosterSessionMutationResponse,
  RosterStaffingMode,
} from "../types/roster";

export interface RosterGenerationOptions {
  staffingMode: RosterStaffingMode;
  staffIds: number[];
  /** @deprecated Compatibility field; new generation always tries GeoFort first for KE/VS. */
  preferGeoFortKe: boolean;
  cookStaffId: number | null;
  plannerModulePreferences: RosterPlannerModulePreference[];
}

export function previewGeneratedRoster(
  planId: number,
  rounds: RosterGenerationRound[],
  options: RosterGenerationOptions,
  token: string,
): Promise<RosterGenerationProposal> {
  return getApiData<RosterGenerationProposal>(
    "/api/admin/rosters/generation-preview.php",
    {
      method: "POST",
      headers: {
        "Content-Type": "application/json",
        "X-CSRF-Token": token,
      },
      body: JSON.stringify({
        planId,
        rounds,
        staffIds: options.staffIds,
        staffingMode: options.staffingMode,
        preferGeoFortKe: options.preferGeoFortKe,
        cookStaffId: options.cookStaffId,
        plannerModulePreferences: options.plannerModulePreferences,
      }),
    },
  );
}

export function applyGeneratedRoster(
  planId: number,
  expectedRevision: number,
  rounds: RosterGenerationRound[],
  replaceExisting: boolean,
  options: RosterGenerationOptions,
  token: string,
  expectedCatalogRevision: number,
  acceptedLessonConflictSignature: string | null = null,
): Promise<
  RosterSessionMutationResponse & {
    sessionCount: number;
    unfilledRequiredAssignments: number;
  }
> {
  return getApiData<
    RosterSessionMutationResponse & {
      sessionCount: number;
      unfilledRequiredAssignments: number;
    }
  >(
    "/api/admin/rosters/generation-apply.php",
    {
      method: "POST",
      headers: {
        "Content-Type": "application/json",
        "X-CSRF-Token": token,
      },
      body: JSON.stringify({
        planId,
        expectedRevision,
        expectedCatalogRevision,
        acceptedLessonConflictSignature,
        rounds,
        replaceExisting,
        staffIds: options.staffIds,
        staffingMode: options.staffingMode,
        preferGeoFortKe: options.preferGeoFortKe,
        cookStaffId: options.cookStaffId,
        plannerModulePreferences: options.plannerModulePreferences,
      }),
    },
  );
}

import type { DashboardRosterSession, RosterStaffingSettings } from "../types/roster";

/** Saved assignments determine progress; a wizard choice or staff selection does not. */
export function rosterStaffingState(sessions: DashboardRosterSession[], settings: RosterStaffingSettings) {
  let openLessons = 0;
  let hasStaff = false;
  for (const session of sessions) {
    const assigned = new Set<number>();
    let uncoveredGroup = false;
    for (const groupId of session.groupIds) {
      const kind = session.groupSupervision?.find(g => g.groupId === groupId)?.kind;
      if (kind === "school" && session.schoolSupervisionAllowed) continue;
      const specific = session.groupStaffAssignments?.find(g => g.groupId === groupId);
      const staff = kind === "open" || kind === "school" ? [] : (specific?.staffAssignments ?? session.staffAssignments);
      if (!staff.length) uncoveredGroup = true;
      staff.forEach(person => assigned.add(person.id));
    }
    const cook = session.moduleKey === "Voedsel-Innovatie"
      ? (session.cookStaffId ?? settings.cookStaffId) : null;
    hasStaff ||= assigned.size > 0 || cook !== null;
    if (uncoveredGroup || assigned.size < session.minimumGeoFortStaff
      || (session.moduleKey === "Voedsel-Innovatie" && cook === null)) openLessons++;
  }
  const state = !sessions.length ? "empty" : !openLessons ? "complete" : hasStaff ? "partial" : "none";
  const label = state === "empty" ? "Nog geen lessen" : state === "complete" ? "Begeleiding ingevuld"
    : state === "none" ? "Begeleiding nog in te vullen" : `${openLessons} lessen met open begeleiding`;
  const action = state === "complete" ? "Docentindeling optimaliseren" : state === "partial" ? "Docenten aanvullen" : "Docenten indelen";
  return { state, label, action, openLessons, hasStaff };
}

export function jointLessonsAvailable(plans: Array<{ planning: { sessions: DashboardRosterSession[] } }>): boolean {
  return plans.length === 2 && plans.every(plan => plan.planning.sessions.length > 0);
}

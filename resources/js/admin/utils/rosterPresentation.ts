import type { DashboardRosterGroup, DashboardRosterSession } from "../types/roster";

export interface RosterModulePresentation {
  key: string;
  moduleKey: string;
  moduleLabel: string;
  color: string;
  isCook: boolean;
  groupIds: number[];
  groupLabels: string[];
}

interface ModuleEntry {
  session: DashboardRosterSession;
  groupIds: number[];
  isCook?: boolean;
}

/** The caller supplies one responsible person/School; time and module stay separate. */
export function groupRosterModules(
  entries: ModuleEntry[],
  groups: DashboardRosterGroup[],
): RosterModulePresentation[] {
  const groupById = new Map(groups.map((group) => [group.id, group]));
  const modules = new Map<string, RosterModulePresentation>();
  for (const { session, groupIds, isCook = false } of entries) {
    if (groupIds.length === 0) continue;
    const key = `${session.startTime}|${session.endTime}|${session.moduleKey || session.moduleLabel}`;
    const module = modules.get(key) ?? {
      key, moduleKey: session.moduleKey, moduleLabel: session.moduleLabel,
      color: session.color, isCook, groupIds: [], groupLabels: [],
    };
    module.isCook ||= isCook;
    for (const id of groupIds) {
      if (module.groupIds.includes(id)) continue;
      module.groupIds.push(id);
      module.groupLabels.push(groupById.get(id)?.label
        ?? session.groupLabels[session.groupIds.indexOf(id)] ?? `Groep ${id}`);
    }
    modules.set(key, module);
  }
  return [...modules.values()].map((module) => {
    const ordered = module.groupIds.map((id, index) => ({ id, label: module.groupLabels[index]! }))
      .sort((a, b) => {
        const left = groupById.get(a.id)?.position;
        const right = groupById.get(b.id)?.position;
        if (left !== undefined && right !== undefined && left !== right) return left - right;
        return a.label.localeCompare(b.label, "nl", { numeric: true }) || a.id - b.id;
      });
    return { ...module, groupIds: ordered.map((group) => group.id), groupLabels: ordered.map((group) => group.label) };
  });
}

export function rosterModulesForStaff(
  sessions: DashboardRosterSession[],
  groups: DashboardRosterGroup[],
  staffId: number,
  cookStaffId: number | null,
): RosterModulePresentation[] {
  return groupRosterModules(sessions.map((session) => {
    const isCook = (session.cookStaffId ?? cookStaffId) === staffId && session.moduleKey === "Voedsel-Innovatie";
    const groupIds = isCook ? session.groupIds : session.groupIds.filter((groupId) => {
      const specific = session.groupStaffAssignments?.find((assignment) => assignment.groupId === groupId);
      return (specific?.staffAssignments ?? session.staffAssignments).some((staff) => staff.id === staffId);
    });
    return { session, groupIds, isCook };
  }), groups);
}

export function rosterModulesForSchool(
  sessions: DashboardRosterSession[],
  groups: DashboardRosterGroup[],
): RosterModulePresentation[] {
  return groupRosterModules(sessions.map((session) => ({
    session,
    groupIds: session.groupIds.filter((id) => session.groupSupervision?.some(
      (group) => group.groupId === id && group.kind === "school",
    )),
  })), groups);
}

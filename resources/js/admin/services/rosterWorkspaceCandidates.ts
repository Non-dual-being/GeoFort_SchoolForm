import type { JointOptions, JointPlan } from './dashboardRosterWorkspaceApi';

/** Recognize a no-op against the current draft, including the shared VI guide. */
export function jointAssignmentIsCurrent(state: JointPlan[], target: JointOptions['target'], staffId: number, moduleKey: string): boolean {
  if (!target) return false;
  const session = state.find(p => p.id === target.planId)?.sessions.find(s => s.id === target.sessionId);
  if (!session) return false;
  if (target.cook) return session.cook === staffId;
  const groups = session.groups.filter(g => moduleKey === 'Voedsel-Innovatie' || g.id === target.groupId);
  return groups.length > 0 && groups.every(g => g.kind === 'staff' && g.staffIds.length === 1 && g.staffIds[0] === staffId);
}

import type { DashboardRosterPlan, RosterGenerationRound, RosterStaffMember } from '../types/roster';
export interface PairedStaffUse { id: number; name: string; slots: Array<{ startTime: string; endTime: string; moduleKey: string; label: string; cook: boolean }> }
export function pairedStaffUse(plan: DashboardRosterPlan | null): PairedStaffUse[] {
  if (!plan) return [];
  const result = new Map<number, PairedStaffUse>();
  for (const session of plan.planning.sessions) {
    const people = new Map<number, { id: number; name: string }>();
    for (const groupId of session.groupIds) {
      const kind = session.groupSupervision?.find(g => g.groupId === groupId)?.kind;
      if (kind === 'school' || kind === 'open') continue;
      const group = session.groupStaffAssignments?.find(g => g.groupId === groupId)?.staffAssignments ?? [];
      for (const member of kind === 'staff' ? group : group.length ? group : session.staffAssignments) people.set(member.id, member);
    }
    const cookId = session.moduleKey === 'Voedsel-Innovatie' ? (session.cookStaffId ?? plan.planning.staffingSettings.cookStaffId) : null;
    if (cookId) people.set(cookId, { id: cookId, name: plan.planning.staffCatalog.find(m => m.id === cookId)?.name ?? 'Kok' });
    for (const person of people.values()) {
      const use = result.get(person.id) ?? { ...person, slots: [] };
      if (!use.slots.some(s => s.startTime === session.startTime && s.endTime === session.endTime && s.moduleKey === session.moduleKey)) use.slots.push({ startTime: session.startTime.slice(0,5), endTime: session.endTime.slice(0,5), moduleKey: session.moduleKey, label: session.moduleLabel, cook: person.id === cookId });
      result.set(person.id, use);
    }
  }
  return [...result.values()].map(use => ({ ...use, slots: use.slots.sort((a,b) => a.startTime.localeCompare(b.startTime)) })).sort((a,b) => a.name.localeCompare(b.name,'nl'));
}
export function availablePairedRounds(member: RosterStaffMember, use: PairedStaffUse | undefined, rounds: RosterGenerationRound[], modules: string[]): RosterGenerationRound[] {
  if (!use) return rounds;
  const dayModules = new Set(use.slots.filter(s => !s.cook).map(s => s.moduleKey));
  const canGuideModule = modules.some(key => member.preferences.some(p => p.moduleKey === key) && (dayModules.has(key) || dayModules.size < 4));
  if (!canGuideModule) return [];
  return rounds.filter(round => !use.slots.some(s => s.startTime < round.endTime && s.endTime > round.startTime));
}

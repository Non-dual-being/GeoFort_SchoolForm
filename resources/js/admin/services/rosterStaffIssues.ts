import type { DashboardRosterPlan } from "../types/roster";

export function rosterStaffIssues(plan: DashboardRosterPlan): Array<{ staffId: number; name: string; reasons: string[] }> {
  if (plan.status !== "concept") return [];
  const issues = new Map<number, { staffId: number; name: string; reasons: string[] }>();
  const member = (id: number) => plan.planning.staffCatalog.find(m => m.id === id);
  function add(id: number, reason: string) {
    const item = issues.get(id) ?? { staffId: id, name: member(id)?.name ?? "Medewerker #" + id, reasons: [] };
    if (!item.reasons.includes(reason)) item.reasons.push(reason);
    issues.set(id, item);
  }
  for (const id of plan.planning.selectedStaffIds) if (!member(id)?.isActive) add(id, "Inactief of ontbrekend in de dagselectie.");
  for (const session of plan.planning.sessions) {
    const assigned = [...session.staffAssignments, ...(session.groupStaffAssignments ?? []).flatMap(g => g.staffAssignments)];
    for (const assignment of assigned) {
      const person = member(assignment.id);
      if (!person?.isActive) add(assignment.id, "Inactief maar nog als begeleider toegewezen.");
      if (!person?.canGuide) add(assignment.id, "Heeft geen bevoegdheid meer om te begeleiden.");
      if (!person?.preferences.some(p => p.moduleKey === session.moduleKey)) add(assignment.id, "Mist de gebruikte vaardigheid: " + session.moduleLabel + ".");
    }
  }
  const cook = plan.planning.staffingSettings.cookStaffId;
  if (cook !== null && (!member(cook)?.isActive || !member(cook)?.canCook)) add(cook, "De opgeslagen kokkeuze is niet meer actief of bevoegd.");
  return [...issues.values()];
}

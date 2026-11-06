import type {DashboardRosterPlan} from '../types/roster';
export function linkedWorkspaceLocation(plan: Pick<DashboardRosterPlan,'id'|'jointPlanning'>) {
  if(!plan.jointPlanning)return null;
  return {name:'roster-create',query:{schools:'2',school1:String(plan.jointPlanning.planIds[0]),school2:String(plan.jointPlanning.planIds[1]),joint:'1',focus:String(plan.id)}};
}
export function manualWorkspaceLocation(plan: Pick<DashboardRosterPlan,'id'|'jointPlanning'>) {
  return linkedWorkspaceLocation(plan)??{name:'roster-manual',params:{id:plan.id}};
}

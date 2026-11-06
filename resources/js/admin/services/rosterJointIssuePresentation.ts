import type {DashboardRosterPlan, RosterValidationIssue} from '../types/roster';

export type JointDisplayIssue = RosterValidationIssue & {
  planId?:number;
  schools?:Array<{planId:number;schoolName:string}>;
  sessionIds?:number[];
  moduleKeys?:string[];
  startTime?:string;
  endTime?:string;
};

function category(code:string):string {
  const labels:Record<string,string>={
    FOUR_MODULE_KE_SUPERVISION_REQUIRED:'Verplichte begeleiding',
    OPEN_SUPERVISION:'Begeleiding ontbreekt',GUIDES_REQUIRED:'Begeleiding ontbreekt',GROUP_GUIDES_REQUIRED:'Begeleiding ontbreekt',
    SCHOOL_NOT_ALLOWED:'Schoolbegeleiding niet toegestaan',COOK_REQUIRED:'Kok ontbreekt',
    STAFF_NOT_QUALIFIED:'Bevoegdheid',COOK_NOT_QUALIFIED:'Bevoegdheid',
    STAFF_UNAVAILABLE:'Beschikbaarheid',STAFF_DAY_SELECTION_REQUIRED:'Beschikbaarheid',INACTIVE_SELECTED_STAFF:'Inactieve medewerker',
    STAFF_DAY_OVERLAP:'Dubbele inzet',STAFF_OVERLAP:'Dubbele inzet',OTHER_PLAN_STAFF_CONFLICT:'Dubbele inzet',
    FOOD_SCHOOL_OVERLAP:'VI per school',VI_ROLES_CONFLICT:'Kok en begeleider',STAFF_FOOD_TEAM:'Kok en begeleider',
    MODULE_CAPACITY:'Modulecapaciteit',SESSION_GROUP_CAPACITY:'Modulecapaciteit',GROUP_OVERLAP:'Overlappende lessen',
    MISSING_MODULE:'Module ontbreekt',DUPLICATE_MODULE:'Module dubbel ingedeeld',
    STAFF_DAY_MODULE_LIMIT:'Te veel modules',STAFF_MODULE_LIMIT:'Te veel modules',
    INVALID_SESSION_TIME:'Lestijden',INVALID_TIMELINE:'Dagindeling',INVALID_VISIT_DATE:'Bezoekdatum',
    SOURCE_CHANGED:'Aanvraag gewijzigd',STALE_REVISION:'Rooster gewijzigd',
    GROUP_LIMIT:'Aantal groepen',INVALID_GROUP:'Groepsindeling',INVALID_STUDENT_COUNT:'Leerlingaantal',
  };
  return labels[code]??'Roostercontrole';
}

// Only use structured server metadata and matching sessions, never infer schools
// or modules by parsing an error message. Missing context remains explicitly broad.
export function presentJointIssue(issue:JointDisplayIssue,plans:DashboardRosterPlan[]) {
  const ids=new Set([issue.planId,...(issue.schools??[]).map(s=>s.planId),issue.otherPlanId].filter((id):id is number=>id!==undefined));
  const scoped=ids.size?plans.filter(p=>ids.has(p.id)):plans;
  const sessionIds=new Set([issue.sessionId,...issue.sessionIds??[]].filter((id):id is number=>id!==undefined));
  const sessions=scoped.flatMap(p=>p.planning.sessions.filter(s=>sessionIds.has(s.id)).map(s=>({plan:p,session:s})));
  const schoolNames=[...ids].map(id=>plans.find(p=>p.id===id)?.school.name??issue.schools?.find(s=>s.planId===id)?.schoolName??`Rooster #${id}`);
  const schools=[...new Set(schoolNames.length?schoolNames:sessions.map(s=>s.plan.school.name))];
  const keys=[...new Set([issue.moduleKey,...issue.moduleKeys??[],...sessions.map(s=>s.session.moduleKey)].filter((key):key is string=>!!key))];
  const modules=keys.map(key=>plans.flatMap(p=>p.planning.modules).find(m=>m.key===key)?.label??sessions.find(s=>s.session.moduleKey===key)?.session.moduleLabel??key.replaceAll('-',' '));
  const context:string[]=[];
  if(issue.startTime&&issue.endTime)context.push(`${issue.startTime.slice(0,5)}–${issue.endTime.slice(0,5)}`);
  else context.push(...new Set(sessions.map(s=>`${s.session.startTime.slice(0,5)}–${s.session.endTime.slice(0,5)}`)));
  if(issue.groupId)context.push(...new Set(scoped.flatMap(p=>p.groups.filter(g=>g.id===issue.groupId).map(g=>g.label))));
  if(issue.staffId){const person=plans.flatMap(p=>p.planning.staffCatalog).find(p=>p.id===issue.staffId);if(person)context.push(person.name);}
  const only=sessions.length===1?sessions[0]:undefined;
  const groupId=only&&(issue.groupId??only.session.groupIds[0]);
  const target=only&&groupId&&only.session.groupIds.includes(groupId)?{planId:only.plan.id,sessionId:only.session.id,groupId}:null;
  return {schools:schools.length?schools:['Gezamenlijke planning'],modules:modules.length?modules:['Roosterbreed'],category:category(issue.code),context,target};
}

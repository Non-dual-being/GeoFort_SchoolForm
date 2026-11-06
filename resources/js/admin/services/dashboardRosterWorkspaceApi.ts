import { getApiData } from '../../services/http/apiClient';
import type { RosterStaffMember, RosterValidationIssue } from '../types/roster';
export interface JointGroup { id:number; kind:'staff'|'school'|'open'; staffIds:number[] }
export interface JointSession { id:number; start:string; end:string; cook:number; groups:JointGroup[] }
export interface JointPlan { id:number; sessions:JointSession[] }
export interface JointOptions {
  approvedStaff?:Array<{id:number;schedule:string}>; releasedStaff?:number[]; moduleSwaps?:boolean;
  teamMode:'existing'|'selection'; teamIds?:number[]; shiftLessons:boolean; timePins:string[]; staffPins:string[]; retained:number[];
  additions:Array<{id:number;start:string;end:string;confirmed:true}>;
  target?:{planId:number;sessionId:number;groupId:number;cook:boolean};
}
export interface JointReport { errors:Array<RosterValidationIssue & {planId?:number}>; warnings:RosterValidationIssue[]; canApply:boolean; canFinalize:boolean }
export interface JointReadiness { canOpenJoint:boolean;canOptimize:boolean;canFinalize:boolean;schools:Array<{id:number;ready:boolean;issues:RosterValidationIssue[]}>;report:JointReport }
export interface JointLoad { options:JointOptions|null;state:JointPlan[];readiness:JointReadiness;context:string;members:RosterStaffMember[];selected:number[];existing:number[] }
export interface JointResult { state:JointPlan[];signature:string;report:JointReport;applied:boolean;context:string;search:null|{before:JointMetrics;after:JointMetrics;candidateCount:number;searchLimited:boolean} }
export interface JointMetrics {people:number;paidGapMinutes:number;unfilledAssignments:number;keSchoolAssignments:number;staff:Array<{staffId:number;name:string;workMinutes:number;gapMinutes:number}>}
export interface JointCandidate {id:number;qualified:boolean;selected:boolean;reasons:string[];uses:Array<{planId:number;start:string;end:string;moduleKey:string;cook:boolean}>}
export function jointWorkspace<T=JointResult>(plans:readonly {id:number;revision:number}[],token:string,mode:string,state:JointPlan[]|null=null,options:JointOptions|null=null,signature?:string,signal?:AbortSignal):Promise<T> {
  return getApiData<T>('/api/admin/rosters/joint-workspace.php', {method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':token},body:JSON.stringify({plans:plans.map(({id,revision})=>({id,revision})),mode,state,options:options??{},signature}),signal});
}

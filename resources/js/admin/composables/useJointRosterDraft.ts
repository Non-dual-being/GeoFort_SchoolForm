import {computed,ref} from 'vue';
import type {JointPlan,JointOptions} from '../services/dashboardRosterWorkspaceApi';
export const cloneJoint=<T>(value:T):T=>JSON.parse(JSON.stringify(value)) as T;
export function useJointRosterDraft(base:JointPlan[],hasTeam:boolean,saved?:JointOptions|null,selected:number[]=[]) {
  const original=cloneJoint(base);
  const state=ref(cloneJoint(base));
  const options=ref<JointOptions>({teamMode:hasTeam?'existing':'selection',shiftLessons:false,timePins:[],staffPins:[],retained:[],additions:[]});
  if(saved) options.value={...cloneJoint(saved),shiftLessons:false,additions:[],target:undefined};
  options.value.teamIds=selected;
  const originalOptions=cloneJoint(options.value);
  const history=ref<Array<{state:JointPlan[];options:JointOptions}>>([]), future=ref<typeof history.value>([]);
  const dirty=computed(()=>JSON.stringify(state.value)!==JSON.stringify(original)||JSON.stringify(options.value)!==JSON.stringify(originalOptions));
  const remember=()=>({state:cloneJoint(state.value),options:cloneJoint(options.value)});
  function edit(change:(draft:JointPlan[])=>void) {history.value.push(remember());future.value=[];change(state.value);}
  function replace(next:JointPlan[]) {edit(()=>{state.value=cloneJoint(next);});}
  function undo() {const item=history.value.pop();if(!item)return;future.value.push(remember());state.value=item.state;options.value=item.options;}
  function redo() {const item=future.value.pop();if(!item)return;history.value.push(remember());state.value=item.state;options.value=item.options;}
  const changes=computed(()=>state.value.flatMap(p=>p.sessions.flatMap(s=>{
    const old=original.find(x=>x.id===p.id)?.sessions.find(x=>x.id===s.id); if(!old)return[];
    const rows:Array<{planId:number;sessionId:number;groupId?:number;kind:string;before:string|number[];after:string|number[]}>=[];
    if(old.start!==s.start||old.end!==s.end)rows.push({planId:p.id,sessionId:s.id,kind:'Lestijd',before:`${old.start}–${old.end}`,after:`${s.start}–${s.end}`});
    if(old.cook!==s.cook)rows.push({planId:p.id,sessionId:s.id,kind:'Kok',before:[old.cook],after:[s.cook]});
    for(const g of s.groups){const prev=old.groups.find(x=>x.id===g.id);if(prev&&JSON.stringify(prev)!==JSON.stringify(g))rows.push({planId:p.id,sessionId:s.id,groupId:g.id,kind:'Begeleiding',before:prev.kind==='staff'?prev.staffIds:prev.kind==='school'?'School':'Open',after:g.kind==='staff'?g.staffIds:g.kind==='school'?'School':'Open'});}
    return rows;
  })));
  return {state,options,dirty,changes,history,future,edit,replace,undo,redo};
}

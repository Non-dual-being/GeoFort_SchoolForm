<script setup lang="ts">
import {computed,inject,nextTick,onBeforeUnmount,ref,watch} from 'vue';
import {onBeforeRouteLeave,onBeforeRouteUpdate} from 'vue-router';
import AdminButton from '../form/AdminButton.vue';
import AdminDialog from '../feedback/AdminDialog.vue';
import RosterJointTimeline from './RosterJointTimeline.vue';
import RosterStaffLetter from './RosterStaffLetter.vue';
import RosterJointIssues from './RosterJointIssues.vue';
import {ChevronDown} from 'lucide-vue-next';
import RosterJointStaffTimeline from './RosterJointStaffTimeline.vue';
import RosterJointFinalization from './RosterJointFinalization.vue';
import {adminBootstrapKey} from '../../types/admin';
import type {DashboardRosterPlan} from '../../types/roster';
import {jointAssignmentIsCurrent} from '../../services/rosterWorkspaceCandidates';
import {jointWorkspace,type JointLoad,type JointResult,type JointCandidate,type JointPlan} from '../../services/dashboardRosterWorkspaceApi';
import {cloneJoint,jointStaffSchedule,useJointRosterDraft} from '../../composables/useJointRosterDraft';
import {downloadDashboardRosterXlsx} from '../../services/dashboardRosterExportApi';
const props=defineProps<{plans:DashboardRosterPlan[];loaded:JointLoad;startOptimization?:boolean;initialSchoolId?:number}>();
const emit=defineEmits<{refresh:[];back:[]}>();
const bootstrap=inject(adminBootstrapKey)!;
const single=props.plans.length===1;
const readOnly=props.plans.every(p=>p.status!=='concept');
const token=single?bootstrap.rosterSingleWorkspaceCsrfToken!:bootstrap.rosterJointWorkspaceCsrfToken!;
const {state,options,dirty,changes,history,future,editOptions,replace,undo,redo}=useJointRosterDraft(props.loaded.state,props.loaded.existing.length>0,props.loaded.options,props.loaded.selected);
const view=ref<'lessons'|'changes'>('lessons');
const editMode=ref<'modules'|'staff'>('staff');
const approvalsOpen=ref(false);
// Visual focus is separate from the editable draft and staff approvals.
const highlightedStaffId=ref<number|null>(null);
function highlightStaff(id:number){highlightedStaffId.value=highlightedStaffId.value===id?null:id;}
const comparison=ref<HTMLElement|null>(null);
watch(view,async value=>{if(value==='changes'){await nextTick();comparison.value?.scrollIntoView({block:'start',behavior:window.matchMedia('(prefers-reduced-motion: reduce)').matches?'instant':'smooth'});}});
const showIssues=ref(false);
const visitDate=new Intl.DateTimeFormat('nl-NL',{weekday:'long',day:'numeric',month:'long',year:'numeric'}).format(new Date(props.plans[0]!.visitDate+'T12:00:00'));
const settings=ref(!!props.startOptimization),busy=ref(false),error=ref(''),notice=ref(''),review=ref<JointResult|null>(null),search=ref<JointResult['search']>(null);
const selected=ref<{planId:number;sessionId:number;groupId:number;cook:boolean}|null>(null);
const action=ref<'replace'|'swap'|'lesson'>('replace');
const candidates=ref<JointCandidate[]>([]),candidateBusy=ref(false),query=ref('');
const adding=ref<number|null>(null),availabilityStart=ref('09:00'),availabilityEnd=ref('17:00');
const cache=new Map<string,JointResult>(); let controller:AbortController|undefined;let candidateRequest=0;let timer:ReturnType<typeof setTimeout>|undefined;let applied=false; let runVersion=0;
const plan=(id:number)=>props.plans.find(p=>p.id===id)!;
const meta=(pid:number,sid:number)=>plan(pid).planning.sessions.find(s=>s.id===sid)!;
const name=(id:number)=>props.loaded.members.find(m=>m.id===id)?.name??(id?`#${id}`:'Open');
const selectedPlan=computed(()=>selected.value?plan(selected.value.planId):null);
const selectedSession=computed(()=>selected.value?state.value.find(p=>p.id===selected.value!.planId)?.sessions.find(s=>s.id===selected.value!.sessionId):null);
const selectedMeta=computed(()=>selected.value?meta(selected.value.planId,selected.value.sessionId):null);
const selectedKey=computed(()=>selected.value?`${selected.value.planId}:${selected.value.sessionId}:${selected.value.groupId}`:undefined);
const locked=computed(()=>selectedPlan.value?.status==='published');
const approvedIds=computed(()=>(options.value.approvedStaff??[]).map(p=>p.id));
const activeIds=computed(()=>[...new Set(state.value.flatMap(p=>p.sessions.flatMap(s=>[s.cook,...s.groups.flatMap(g=>g.staffIds)]))).values()].filter(Boolean));
const letterIds=ref([...new Set([...props.loaded.existing,...(props.loaded.options?.approvedStaff??[]).map(a=>a.id)])].sort((a,b)=>name(a).localeCompare(name(b),'nl')));
watch(activeIds,ids=>{for(const id of [...ids].sort((a,b)=>name(a).localeCompare(name(b),'nl')))if(!letterIds.value.includes(id))letterIds.value.push(id);},{immediate:true});
function alphabet(index:number){let code='';for(let n=index+1;n>0;n=Math.floor((n-1)/26))code=String.fromCharCode(65+(n-1)%26)+code;return code;}
const letters=computed(()=>Object.fromEntries(letterIds.value.map((id,i)=>[id,alphabet(i)])));
const legendIds=computed(()=>letterIds.value.filter(id=>activeIds.value.includes(id)||approvedIds.value.includes(id)||(props.loaded.options?.approvedStaff??[]).some(a=>a.id===id)));
watch(legendIds,ids=>{if(highlightedStaffId.value!==null&&!ids.includes(highlightedStaffId.value))highlightedStaffId.value=null;});
const approvalChanges=computed(()=>legendIds.value.filter(id=>(props.loaded.options?.approvedStaff??[]).some(a=>a.id===id)!==approvedIds.value.includes(id)));
function assignmentPinned(pid:number,sid:number,gid:number,cook=false){
  const s=state.value.find(p=>p.id===pid)!.sessions.find(s=>s.id===sid)!;
  const ids=cook?[s.cook]:s.groups.filter(g=>meta(pid,sid).moduleKey==='Voedsel-Innovatie'||g.id===gid).flatMap(g=>g.staffIds);
  return ids.some(id=>approvedIds.value.includes(id));
}
const selectedAssignmentPinned=computed(()=>!!selected.value&&assignmentPinned(selected.value.planId,selected.value.sessionId,selected.value.groupId,selected.value.cook));
function changeDraft(change:(draft:JointPlan[])=>void){
  const next=cloneJoint(state.value);change(next);
  if((options.value.approvedStaff??[]).some(p=>jointStaffSchedule(next,p.id)!==p.schedule)){error.value='Deze wijziging raakt een goedgekeurde medewerker. Geef de medewerker eerst vrij via Medewerkers goedkeuren.';return false;}
  replace(next);return true;
}
function toggleApproval(id:number){
  const isApproved=approvedIds.value.includes(id);
  editOptions(o=>{
    if(isApproved){o.approvedStaff=o.approvedStaff!.filter(p=>p.id!==id);if(!o.releasedStaff!.includes(id))o.releasedStaff!.push(id);}
    else o.approvedStaff!.push({id,schedule:jointStaffSchedule(state.value,id)});
  });
  notify(isApproved?`${name(id)} is vrijgegeven in de bewerkversie.`:`Het rooster van ${name(id)} staat vast in de bewerkversie. Pas de wijzigingen toe om dit te bewaren.`);
  if(selected.value&&editMode.value==='staff')void loadCandidates();
}
function chooseMode(mode:'modules'|'staff'){editMode.value=mode;view.value='lessons';selected.value=null;action.value='replace';candidates.value=[];candidateRequest++;}

const filteredCandidates=computed(()=>candidates.value.filter(c=>name(c.id).toLocaleLowerCase('nl').includes(query.value.toLocaleLowerCase('nl'))).sort((a,b)=>Number(b.qualified)-Number(a.qualified)||Number(b.uses.length>0)-Number(a.uses.length>0)||name(a.id).localeCompare(name(b.id),'nl')));
const fingerprint=computed(()=>JSON.stringify([state.value,options.value]));
watch(fingerprint,()=>{review.value=null;search.value=null;notice.value='';},{flush:'sync'});
function notify(text:string){notice.value=text;clearTimeout(timer);timer=setTimeout(()=>notice.value='',8000);}
const leaveOpen=ref(false);let leaveResolve:((answer:boolean)=>void)|undefined;
function mayLeave():boolean|Promise<boolean>{
  if(applied||!dirty.value)return true;
  leaveOpen.value=true;
  return new Promise(resolve=>{leaveResolve?.(false);leaveResolve=resolve;});
}
function answerLeave(answer:boolean){leaveOpen.value=false;leaveResolve?.(answer);leaveResolve=undefined;}
onBeforeRouteLeave(mayLeave);onBeforeRouteUpdate(mayLeave);
function unload(e:BeforeUnloadEvent){if(dirty.value&&!applied){e.preventDefault();e.returnValue='';}}
window.addEventListener('beforeunload',unload);
onBeforeUnmount(()=>{controller?.abort();leaveResolve?.(false);candidateRequest++;clearTimeout(timer);window.removeEventListener('beforeunload',unload);});
async function back(){if(await mayLeave()){applied=true;emit('back');}}
async function select(pid:number,sid:number,gid:number){
  if(busy.value)return;
  if(selected.value&&action.value!=='replace') {swap(pid,sid,gid);return;}
  selected.value={planId:pid,sessionId:sid,groupId:gid,cook:false};query.value='';adding.value=null;if(editMode.value==='staff')await loadCandidates();
}
async function loadCandidates(){
  const request=++candidateRequest;if(!selected.value)return;candidateBusy.value=true;candidates.value=[];error.value='';
  if(selectedAssignmentPinned.value){candidateBusy.value=false;return;}
  try{const result=await jointWorkspace<{candidates:JointCandidate[]}>(props.plans,token,'candidates',cloneJoint(state.value),{...cloneJoint(options.value),target:selected.value});if(request===candidateRequest)candidates.value=result.candidates;}
  catch(e){if(request===candidateRequest)error.value=e instanceof Error?e.message:'Kandidaten laden is niet gelukt.';}
  finally{if(request===candidateRequest)candidateBusy.value=false;}
}
function isCurrentCandidate(c:JointCandidate){return jointAssignmentIsCurrent(state.value,selected.value??undefined,c.id,selectedMeta.value?.moduleKey??'');}
function setStaff(id:number,kind:'staff'|'school'|'open'='staff'){
  if(!selected.value||locked.value||selectedAssignmentPinned.value)return;
  if(kind==='staff'&&jointAssignmentIsCurrent(state.value,selected.value,id,selectedMeta.value?.moduleKey??''))return;
  const t={...selected.value};
  if(!changeDraft(draft=>{const s=draft.find(p=>p.id===t.planId)!.sessions.find(s=>s.id===t.sessionId)!;if(t.cook)s.cook=id;else{const groups=meta(t.planId,t.sessionId).moduleKey==='Voedsel-Innovatie'?s.groups:s.groups.filter(g=>g.id===t.groupId);for(const g of groups){g.kind=kind;g.staffIds=kind==='staff'?[id]:[];}}}))return;
  notify('Wijziging toegevoegd aan de bewerkversie.');void loadCandidates();
}
function pick(c:JointCandidate){
  if(candidateBusy.value||!c.qualified||c.reasons.length||locked.value||isCurrentCandidate(c))return;
  if(!c.selected&&!options.value.additions.some(a=>a.id===c.id)){adding.value=c.id;return;}
  setStaff(c.id);
}
function confirmAddition(){if(adding.value===null||availabilityEnd.value<=availabilityStart.value)return;const id=adding.value;options.value.additions.push({id,start:availabilityStart.value,end:availabilityEnd.value,confirmed:true});options.value.teamIds?.push(id);adding.value=null;setStaff(id);}
function swap(pid:number,sid:number,gid:number){
  const t=selected.value;if(!t||locked.value||plan(pid).status==='published')return;
  if(t.planId===pid&&t.sessionId===sid&&t.groupId===gid){error.value='Kies een andere les.';return;}
  if(action.value==='lesson'&&t.planId!==pid){error.value='Lessen wisselen kan binnen dezelfde school. Docenten kun je tussen beide scholen omwisselen.';return;}
  if(action.value==='lesson'&&selectedSession.value&&(()=>{const target=state.value.find(p=>p.id===pid)!.sessions.find(s=>s.id===sid)!;const length=(s:{start:string;end:string})=>Number(s.end.slice(0,2))*60+Number(s.end.slice(3))-Number(s.start.slice(0,2))*60-Number(s.start.slice(3));return length(target)!==length(selectedSession.value!);})()){error.value='Kies een lesblok met dezelfde duur.';return;}
  if(action.value==='lesson'&&meta(pid,sid).groupIds.slice().sort().join(',')!==meta(t.planId,t.sessionId).groupIds.slice().sort().join(',')){error.value='Kies een module voor dezelfde groep of dezelfde combinatie van groepen.';return;}
  if(action.value==='lesson'&&(options.value.timePins.includes(`${pid}:${sid}`)||options.value.timePins.includes(`${t.planId}:${t.sessionId}`))||action.value==='swap'&&(assignmentPinned(pid,sid,gid)||assignmentPinned(t.planId,t.sessionId,t.groupId))){error.value='Deze les of toewijzing staat vast.';return;}
  if(!changeDraft(draft=>{const a=draft.find(p=>p.id===t.planId)!.sessions.find(s=>s.id===t.sessionId)!;const b=draft.find(p=>p.id===pid)!.sessions.find(s=>s.id===sid)!;
    if(action.value==='lesson'){const first=[a.start,a.end];[a.start,a.end]=[b.start,b.end];[b.start,b.end]=first as [string,string];}
    else{const ag=cloneJoint(a.groups.find(g=>g.id===t.groupId)!),bg=cloneJoint(b.groups.find(g=>g.id===gid)!);
      const left=meta(t.planId,t.sessionId).moduleKey==='Voedsel-Innovatie'?a.groups:a.groups.filter(g=>g.id===t.groupId);
      const right=meta(pid,sid).moduleKey==='Voedsel-Innovatie'?b.groups:b.groups.filter(g=>g.id===gid);
      for(const g of left){g.kind=bg.kind;g.staffIds=[...bg.staffIds];}for(const g of right){g.kind=ag.kind;g.staffIds=[...ag.staffIds];}}
  }))return;action.value='replace';selected.value=null;notify('Wissel toegevoegd. Controleer het resultaat vóór toepassen.');
}
async function run(mode:'preview'|'optimize'|'apply'){
  if(busy.value||readOnly)return;busy.value=true;error.value='';const current=++runVersion;const abort=new AbortController();controller=abort;
  const input=cloneJoint(state.value),settings=cloneJoint(options.value),at=fingerprint.value;
  try{
    let result:JointResult;
    if(mode==='optimize'){
      const current=await jointWorkspace<JointLoad>(props.plans,token,'read',null,null,undefined,abort.signal);
      const key=JSON.stringify([current.context,input,settings]);
      const saved=cache.get(key);
      result=saved??await jointWorkspace(props.plans,token,mode,input,settings,undefined,abort.signal);
      if(!saved){if(cache.size>3)cache.clear();cache.set(key,cloneJoint(result));}
      if(abort.signal.aborted||at!==fingerprint.value)return;
      if(JSON.stringify(result.state)!==JSON.stringify(state.value))replace(result.state);
      search.value=result.search;review.value=result;view.value='changes';selected.value=null;
    }else{
      result=await jointWorkspace(props.plans,token,mode,input,settings,mode==='apply'?review.value?.signature:undefined,abort.signal);
      if(abort.signal.aborted||at!==fingerprint.value)return;
      if(mode==='apply'){applied=true;emit('refresh');return;}
      review.value=result;view.value='changes';
    }
  }catch(e){if(!abort.signal.aborted)error.value=e instanceof Error?e.message:'Het voorstel is niet gelukt.';}
  finally{if(current===runVersion)busy.value=false;}
}
async function exportSaved(){try{await downloadDashboardRosterXlsx(props.plans[0]!.id,!single);}catch(e){error.value=e instanceof Error?e.message:'Exporteren is niet gelukt.';}}
const presentedPlans=computed(()=>props.plans.map(p=>({...p,planning:{...p.planning,staffingSettings:{...p.planning.staffingSettings,cookStaffId:null},sessions:p.planning.sessions.map(s=>{
  const d=state.value.find(x=>x.id===p.id)!.sessions.find(x=>x.id===s.id)!;
  return {...s,startTime:d.start,endTime:d.end,cookStaffId:d.cook||null,staffAssignments:[...new Set(d.groups.flatMap(g=>g.staffIds))].map(id=>({id,name:name(id)})),groupStaffAssignments:d.groups.map(g=>({groupId:g.id,staffAssignments:g.staffIds.map(id=>({id,name:name(id)}))})),groupSupervision:d.groups.map(g=>({groupId:g.id,kind:g.kind}))};
})}})));
function describe(v:string|number[]){return typeof v==='string'?v:v.map(name).join(', ');}
</script>
<template>
  <section class="joint-workspace" :aria-label="single?'Handmatig rooster aanpassen':'Gezamenlijke roosterwerkruimte'">
    <p class="joint-workspace__hint">{{visitDate}} · {{plans.map(p=>p.school.name).join(' + ')}}</p>
    <div class="joint-workspace__toolbar"><AdminButton variant="secondary" @click="back">← {{single?'Rooster bekijken':'Schoolbeheer'}}</AdminButton><span role="status">{{dirty?`${changes.length+approvalChanges.length} ${changes.length+approvalChanges.length===1?'wijziging':'wijzigingen'} · nog niet toegepast`:single?'Opgeslagen rooster':'Opgeslagen roosters'}}</span><AdminButton variant="secondary" @click="exportSaved">Excel exporteren</AdminButton></div>
    <details class="joint-workspace__attention" :open="showIssues">
      <summary @click.prevent="showIssues=!showIssues">{{loaded.readiness.report.errors.length?`${loaded.readiness.report.errors.length} ${loaded.readiness.report.errors.length===1?'aandachtspunt':'aandachtspunten'} in ${single?'het opgeslagen rooster':'de opgeslagen roosters'}`:single?'Het opgeslagen rooster voldoet aan de eindcontrole':'Opgeslagen roosters voldoen aan de eindcontrole'}}</summary>
      <RosterJointIssues :issues="loaded.readiness.report.errors" :plans="plans" />
      <p v-if="dirty" class="joint-workspace__hint">Controleer de bewerkversie via Wijzigingen om het nieuwe resultaat te zien.</p>
    </details>
    <p v-if="dirty" class="joint-workspace__hint">Excel gebruikt {{single?'het opgeslagen rooster':'de opgeslagen roosters'}}. Je bewerkversie wordt pas bewaard bij ‘Wijzigingen toepassen’.</p>
    <p v-if="readOnly" class="admin-inline-notice">Dit rooster is definitief en staat vast. Heropen het via Rooster bekijken voordat je wijzigingen maakt.</p>
    <p v-if="error" role="alert" class="admin-inline-error">{{error}}</p><p v-if="notice" role="status" class="admin-inline-notice">{{notice}}</p>
    <div class="joint-workspace__toolbar joint-workspace__bar">
      <div role="group" aria-label="Wat wil je aanpassen?"><button :disabled="busy" :aria-pressed="view==='lessons'&&editMode==='modules'" @click="chooseMode('modules')">Modules aanpassen</button><button :disabled="busy" :aria-pressed="view==='lessons'&&editMode==='staff'" @click="chooseMode('staff')">Docenten aanpassen</button><button :aria-pressed="view==='changes'" @click="view='changes';selected=null;action='replace'">Wijzigingen ({{changes.length+approvalChanges.length}})</button></div>
      <div><button :disabled="busy||!history.length" @click="undo">Ongedaan maken</button><button :disabled="busy||!future.length" @click="redo">Opnieuw</button><button :aria-expanded="settings" :disabled="readOnly" @click="settings=!settings">Team en optimalisatie</button></div>
    </div>
    <section v-if="settings && !readOnly" class="admin-card joint-workspace__settings">
      <h2>{{single?'Personeelsinzet verbeteren':'Gezamenlijke inzet verbeteren'}}</h2>
      <fieldset :disabled="busy"><legend>Welke medewerkers mogen meedoen?</legend><label><input v-model="options.teamMode" type="radio" value="existing" :disabled="!loaded.existing.length"> Bestaand team herverdelen <small>Alleen de {{loaded.existing.length}} al ingeroosterde medewerkers.</small></label><label><input v-model="options.teamMode" type="radio" value="selection"> Team samenstellen <small>Gebruik {{single?'de dagselectie':'de dagselectie van beide scholen'}} en expliciet toegevoegde medewerkers.</small></label></fieldset>
      <details v-if="options.teamMode==='selection'"><summary>Dagselectie kiezen ({{options.teamIds?.length??0}} medewerkers)</summary><div class="joint-workspace__team"><label v-for="id in [...new Set([...loaded.selected,...options.additions.map(a=>a.id)])]" :key="id"><input v-model="options.teamIds" type="checkbox" :value="id" :disabled="approvedIds.includes(id)||busy"> {{name(id)}}{{approvedIds.includes(id)?' · goedgekeurd':''}}</label></div><p>Een medewerker toevoegen kan vanuit een les: kies de bevoegde medewerker en bevestig de beschikbaarheid.</p></details>
      <p class="joint-workspace__hint">Het voorstel verdeelt de medewerkers over de bestaande lessen. Goedgekeurde medewerkers en definitieve roosters blijven staan.</p>
      <details><summary>Medewerkers behouden in team</summary><label v-for="id in loaded.existing" :key="id"><input v-model="options.retained" type="checkbox" :value="id"> {{name(id)}}</label></details>
      <AdminButton :disabled="busy||!loaded.readiness.canOptimize" @click="run('optimize')">{{single?'Voorstel maken':'Gezamenlijk voorstel maken'}}</AdminButton>
    </section>
    <p v-if="busy" role="status">{{single?'Rooster controleren…':view==='changes'?'Gezamenlijke controle uitvoeren…':'Beide roosters worden samen beoordeeld…'}} <button @click="controller?.abort()">Annuleren</button></p>
    <p v-if="action!=='replace'" class="admin-inline-notice">{{action==='swap'?(single?'Kies de tweede docenttoewijzing in dit rooster.':'Kies de tweede docenttoewijzing in één van beide scholen.'):'Kies een tweede les binnen dezelfde school.'}} <button @click="action='replace'">Annuleren</button></p>
    <section class="joint-workspace__legend" aria-label="Medewerkerslegenda">
      <div class="joint-workspace__toolbar">
        <h2>Medewerkers</h2>
        <button class="joint-workspace__approval-toggle" :aria-expanded="approvalsOpen" :aria-label="`Medewerkers goedkeuren, ${approvedIds.length} vast, ${approvalsOpen?'inklappen':'uitklappen'}`" aria-controls="joint-staff-approvals" @click="approvalsOpen=!approvalsOpen">
          <span>Medewerkers goedkeuren</span>
          <span v-if="approvedIds.length" class="joint-workspace__approval-count">{{approvedIds.length}} vast</span>
          <ChevronDown :size="18" :class="{'is-open':approvalsOpen}" aria-hidden="true" />
        </button>
      </div>
      <p class="joint-workspace__hint">Selecteer één medewerker om diens lessen {{single?'in dit rooster':'in beide roosters'}} uit te lichten.</p>
      <ul><li v-for="id in legendIds" :key="id">
        <button class="joint-workspace__legend-person" :class="{'is-approved':approvedIds.includes(id)}" :aria-pressed="highlightedStaffId===id" :aria-label="`${highlightedStaffId===id?'Uitlichten stoppen voor':'Lessen uitlichten van'} ${name(id)}${approvedIds.includes(id)?' · Goedgekeurd · vast':''}`" @click="highlightStaff(id)">
          <RosterStaffLetter :letter="letters[id]" :name="name(id)" :approved="approvedIds.includes(id)" />
          <span>{{name(id)}}<small v-if="approvedIds.includes(id)">Goedgekeurd · vast</small></span>
        </button>
      </li></ul>
      <div v-if="highlightedStaffId!==null" class="joint-workspace__focus-status">
        <span role="status">Lessen van <strong>{{name(highlightedStaffId)}}</strong> uitgelicht</span>
        <button @click="highlightedStaffId=null">Uitlichten stoppen</button>
      </div>
      <div v-show="approvalsOpen" id="joint-staff-approvals" class="joint-workspace__approvals">
        <p>Keur het rooster van een medewerker goed {{single?'voor deze school':'voor beide scholen'}}. Lessen, groepen, kooktaken en vrije tijd blijven dan vast totdat je de medewerker vrijgeeft. Bewaar dit met Wijzigingen toepassen.</p>
        <div v-for="id in legendIds" :key="id" class="joint-workspace__approval-row">
          <div class="joint-workspace__approval-person">
            <RosterStaffLetter :letter="letters[id]" :name="name(id)" :approved="approvedIds.includes(id)" />
            <span><b>{{name(id)}}</b><small>{{approvedIds.includes(id)?'Goedgekeurd · niet aanpasbaar':'Nog niet goedgekeurd · aanpasbaar'}}</small></span>
          </div>
          <div class="joint-workspace__approval-actions">
            <button :aria-pressed="highlightedStaffId===id" :aria-label="`${highlightedStaffId===id?'Deselecteer':'Selecteer'} ${name(id)}`" @click="highlightStaff(id)">{{highlightedStaffId===id?'Geselecteerd':'Selecteren'}}</button>
            <button :disabled="readOnly||busy||!activeIds.includes(id)&&!approvedIds.includes(id)" @click="toggleApproval(id)">{{approvedIds.includes(id)?'Vrijgeven':'Rooster goedkeuren'}}</button>
          </div>
        </div>
      </div>
    </section>
    <p v-if="view==='lessons'" class="joint-workspace__hint">{{editMode==='modules'?'Klik op een module om die met een andere module binnen dezelfde school te wisselen. De lesblokken blijven staan.':'Klik op de docentletters in een les om begeleiding te vervangen of om te wisselen.'}}</p>
    <RosterJointTimeline :plans="plans" :state="state" :mode="editMode" :letters="letters" :approved="approvedIds" :highlighted-staff-id="highlightedStaffId" :selected="selectedKey" :initial-school-id="initialSchoolId" :readonly="view==='changes'||busy||readOnly" @select="select" />
    <RosterJointStaffTimeline :plans="presentedPlans" :letters="letters" :approved="approvedIds" />
    <section v-if="view==='changes'" ref="comparison" class="admin-card joint-workspace__changes"><h2>Bekijk wat verandert</h2>
      <p v-for="id in approvalChanges" :key="id"><strong>{{name(id)}}</strong>: {{approvedIds.includes(id)?'rooster goedgekeurd en vastgezet':'rooster vrijgegeven'}}.</p>
      <div v-if="search" class="joint-workspace__metrics"><p>Medewerkers <strong>{{search.before.people}} → {{search.after.people}}</strong></p><p>Betaalde tussenminuten <strong>{{search.before.paidGapMinutes}} → {{search.after.paidGapMinutes}}</strong></p><p>Open plekken <strong>{{search.before.unfilledAssignments}} → {{search.after.unfilledAssignments}}</strong></p><p>KE bij school <strong>{{search.before.keSchoolAssignments}} → {{search.after.keSchoolAssignments}}</strong></p></div>
      <p v-for="person in search?.before.staff.filter(p=>p.workMinutes>0 && !(search?.after.staff.some(a=>a.staffId===p.staffId && a.workMinutes>0)))??[]" :key="person.staffId"><strong>{{person.name}}</strong> is niet meer nodig voor {{single?'dit rooster':'dit scholenpaar'}}. Inzet op andere roosters blijft behouden.</p>
      <p v-if="search">{{search.candidateCount}} indelingen beoordeeld. {{search.searchLimited?'De zoeklimiet is bereikt; er kan nog een betere oplossing zijn.':'Dit is het beste gevonden voorstel binnen de onderzochte indelingen.'}}</p>
      <p v-if="!changes.length">{{approvalChanges.length?'De lessen en docenttoewijzingen blijven gelijk.':'Er zijn geen wijzigingen ten opzichte van '+(single?'het opgeslagen rooster':'de opgeslagen roosters')+'.'}}</p>
      <article v-for="(change,i) in changes" :key="i"><strong>{{plan(change.planId).school.name}} · {{meta(change.planId,change.sessionId).moduleLabel}}</strong><span>{{change.groupId?plan(change.planId).groups.find(g=>g.id===change.groupId)?.label+' · ':''}}{{change.kind}}</span><p>{{describe(change.before)}} → <strong>{{describe(change.after)}}</strong></p></article>
      <div v-if="review"><p v-if="review.report.canApply" class="admin-inline-notice">De bewerkversie kan als concept worden toegepast.</p><RosterJointIssues :issues="review.report.errors" :plans="presentedPlans" allow-open @open-lesson="(planId,sessionId,groupId)=>{view='lessons';select(planId,sessionId,groupId)}" /><p v-if="!review.report.canFinalize">Definitief maken blijft geblokkeerd zolang verplichte begeleiding of andere harde regels ontbreken.</p></div>

    <div class="joint-workspace__toolbar"><AdminButton variant="secondary" :disabled="readOnly||busy||!dirty" @click="run('preview')">Wijzigingen controleren</AdminButton><AdminButton :disabled="readOnly||busy||!dirty||!review?.report.canApply" @click="run('apply')">Wijzigingen toepassen</AdminButton></div>
    </section>
    <aside v-if="selected&&selectedSession&&selectedMeta&&action==='replace'" class="joint-workspace__editor" aria-label="Les bewerken">
      <button class="joint-workspace__close" aria-label="Lespaneel sluiten" @click="selected=null;action='replace'">×</button><p class="admin-eyebrow">{{selectedPlan?.school.name}}</p><h2>{{selectedMeta.moduleLabel}}</h2><p>{{selectedSession.start}}–{{selectedSession.end}} · {{selectedPlan?.groups.find(g=>g.id===selected?.groupId)?.label}}</p>
      <p v-if="locked" class="admin-inline-notice">Dit rooster is definitief en staat vast. Heropen het via {{single?'Rooster bekijken':'de gezamenlijke eindcontrole'}} om te bewerken.</p>
      <template v-else>
        <template v-if="editMode==='modules'"><p>Wissel deze module met een andere module voor dezelfde groep(en). Bestaande tijden blijven staan; docenten gaan met hun les mee.</p><button @click="action='lesson'">Tweede module kiezen</button></template>
        <template v-else>
        <p v-if="selectedAssignmentPinned" class="admin-inline-notice">Deze begeleiding is goedgekeurd en staat vast. Gebruik Medewerkers goedkeuren om de medewerker vrij te geven.</p>
        <label v-if="selectedMeta.moduleKey==='Voedsel-Innovatie'"><input v-model="selected.cook" type="checkbox" @change="loadCandidates"> Kok wijzigen</label>
        <template v-if="!selectedAssignmentPinned"><div class="joint-workspace__toolbar"><button :disabled="selected.cook||selectedAssignmentPinned" @click="action='swap';view='lessons'">Docenten omwisselen</button></div>
        <label>Docent vervangen<input v-model="query" type="search" placeholder="Zoek medewerker" /></label>
        <button v-if="selectedMeta.schoolSupervisionAllowed&&!selected.cook" :disabled="selectedAssignmentPinned" @click="setStaff(0,'school')">Schoolbegeleiding gebruiken</button>
        <p v-if="candidateBusy" role="status">Geschiktheid en inzet controleren…</p>
        <div v-if="adding!==null" class="joint-workspace__addition"><strong>{{name(adding)}} toevoegen aan de dagselectie</strong><p>Bevestig de beschikbaarheid voor deze bezoekdag.</p><label>Van <input v-model="availabilityStart" type="time"></label><label>Tot <input v-model="availabilityEnd" type="time"></label><AdminButton @click="confirmAddition">Beschikbaarheid bevestigen</AdminButton></div>
        <ul class="joint-workspace__candidates"><li v-for="c in filteredCandidates" :key="c.id"><button :disabled="candidateBusy||!c.qualified||c.reasons.length>0||selectedAssignmentPinned||isCurrentCandidate(c)" @click="pick(c)"><strong>{{letters[c.id]?letters[c.id]+' · ':''}}{{name(c.id)}}</strong><small v-if="isCurrentCandidate(c)">Al toegewezen aan deze les</small><small>{{loaded.members.find(m=>m.id===c.id)?.employmentType==='volunteer'?'Vrijwilliger':'Betaald'}} · {{c.selected?'In dagselectie':'Beschikbaarheid nog bevestigen'}}</small><span v-if="!c.uses.length">Nog niet ingezet</span><span v-for="(use,i) in c.uses" :key="i">{{use.planId===0?'Ander rooster':single?'Dit rooster':`School ${plans.findIndex(p=>p.id===use.planId)+1}`}} · {{use.start}}–{{use.end}}</span><small v-for="reason in c.reasons" :key="reason">{{reason}}</small></button></li></ul>
        </template>
        </template>
      </template>
    </aside>
    <details v-if="!dirty && !single" class="joint-workspace__final"><summary>Gezamenlijke eindcontrole en definitief maken</summary><RosterJointFinalization :plans="plans" @changed="emit('refresh')" /></details>
  </section>
  <AdminDialog :open="leaveOpen" title="Bewerkversie verlaten?" :close-on-backdrop="false" @close="answerLeave(false)">
    <p>Je wijzigingen zijn nog niet toegepast. Als je nu weggaat, vervallen deze wijzigingen. {{single?'Het opgeslagen rooster blijft behouden.':'De opgeslagen roosters blijven behouden.'}}</p>
    <template #footer><AdminButton @click="answerLeave(false)">Verder bewerken</AdminButton><AdminButton variant="secondary" @click="answerLeave(true)">Verlaten zonder toepassen</AdminButton></template>
  </AdminDialog>
</template>
<style scoped>
.joint-workspace{display:grid;gap:1rem;min-width:0}.joint-workspace__toolbar{display:flex;flex-wrap:wrap;gap:.65rem;align-items:center;justify-content:space-between}.joint-workspace__toolbar>div{display:flex;flex-wrap:wrap;gap:.4rem}.joint-workspace button:not(.admin-button){font:inherit;cursor:pointer;border:1px solid #becde3;border-radius:.5rem;padding:.6rem .85rem;background:var(--admin-surface);color:#16345f}.joint-workspace button[aria-pressed=true]{background:var(--admin-header-bg);color:white}.joint-workspace button:enabled:hover{border-color:#668cbd;box-shadow:0 0 0 2px #c9dcf4}.joint-workspace button:disabled{opacity:.5;cursor:not-allowed}.joint-workspace button:focus-visible{outline:3px solid #759cdf;outline-offset:3px}.joint-workspace__bar{padding:.7rem;background:#edf2f9;border:1px solid #d4deed;border-radius:.7rem}.joint-workspace__attention{padding:.85rem 1rem;background:#f0f5fd;border:1px solid #cad8eb;border-radius:.55rem;font-size:.85rem}.joint-workspace__hint{font-size:.85rem;color:#546580;margin:0}.joint-workspace__settings{display:grid;gap:.85rem}.joint-workspace h2{font-size:1.2rem;margin:0 0 .6rem}.joint-workspace fieldset{display:grid;gap:.8rem;border:1px solid var(--admin-border);border-radius:.6rem;padding:1rem}.joint-workspace label{display:block}.joint-workspace label small{display:block;margin-left:1.5rem;color:#5b6c86}.joint-workspace__switch{padding:1rem;border:1px solid #98b5db;border-radius:.6rem;background:#edf5ff;font-weight:bold}.joint-workspace__switch input{accent-color:#173e72;width:1.2rem;height:1.2rem}.joint-workspace__metrics{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:.75rem}.joint-workspace__metrics p{background:#f1f5fb;border:1px solid #d2deef;border-radius:.6rem;padding:.8rem}.joint-workspace__metrics strong{display:block;font-size:1.4rem}.joint-workspace__changes{display:grid;gap:1rem;scroll-margin-top:1.5rem}.joint-workspace__changes article{border-bottom:1px solid #d9e1ed;padding:.8rem 0;display:grid;gap:.35rem}.joint-workspace__changes p{margin:0}.joint-workspace__editor{position:fixed;right:0;top:0;bottom:0;width:min(430px,95vw);background:var(--admin-surface);box-shadow:-8px 0 40px #142c5540;z-index:30;padding:1.5rem;overflow:auto;display:flex;flex-direction:column;gap:.8rem;border-left:3px solid #234675}.joint-workspace__close{align-self:flex-end}.joint-workspace__editor input[type=search]{width:100%;padding:.65rem;border:1px solid #bccce1;border-radius:.5rem;margin-top:.4rem}.joint-workspace__candidates{padding:0;margin:0;list-style:none;display:grid;gap:.5rem}.joint-workspace__candidates button{display:grid;gap:.35rem;text-align:left;width:100%}.joint-workspace__candidates small,.joint-workspace__candidates span{font-size:.8rem}.joint-workspace__addition{padding:1rem;background:#edf5ff;border-radius:.6rem}.joint-workspace__person{border-top:1px solid #d6e0ee;padding:1rem 0}.joint-workspace__person header{display:flex;justify-content:space-between;margin-bottom:.6rem}.joint-workspace__person>div{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:.5rem}.joint-workspace__person button{display:grid;gap:.4rem;text-align:left}.joint-workspace__person small,.joint-workspace__person span{font-size:.8rem}.joint-workspace__final{padding:1rem;border:1px solid var(--admin-border);border-radius:.7rem}.joint-workspace summary{cursor:pointer;font-weight:600}.joint-workspace input{accent-color:#173e72}
.joint-workspace__legend{background:var(--admin-surface);border:1px solid var(--admin-border);border-radius:.75rem;padding:1rem}.joint-workspace__legend h2{margin:0}.joint-workspace__legend>.joint-workspace__hint{margin-top:.8rem}.joint-workspace__legend ul{display:flex;flex-wrap:wrap;gap:.5rem;list-style:none;padding:0;margin:.8rem 0 0}.joint-workspace__legend li{min-width:0}.joint-workspace button.joint-workspace__legend-person{display:flex;align-items:center;gap:.6rem;text-align:left;padding:.5rem .65rem;font-size:.85rem}.joint-workspace__legend-person.is-approved{border-color:#819ec8}.joint-workspace__legend-person[aria-pressed=true]{box-shadow:0 0 0 2px #b6cdea}.joint-workspace__legend small{display:block;font-size:.75rem;color:#34577e;margin-top:.2rem}.joint-workspace__legend-person[aria-pressed=true] small{color:#e4edfb}.joint-workspace__approval-toggle{display:flex;align-items:center;justify-content:center;flex-wrap:wrap;gap:.6rem}.joint-workspace__approval-count{font-size:.72rem;line-height:1.3;color:#5c6d82;background:#f0f3f8;border-radius:1rem;padding:.15rem .45rem;font-variant-numeric:tabular-nums}.joint-workspace__approval-toggle svg{transition:transform .15s}.joint-workspace__approval-toggle svg.is-open{transform:rotate(180deg)}.joint-workspace__approvals{border-top:1px solid var(--admin-border);margin-top:1rem;padding-top:.5rem}.joint-workspace__approval-row{display:flex;flex-wrap:wrap;gap:.75rem;justify-content:space-between;align-items:center;padding:.65rem 0;border-top:1px solid #e1e7ef}.joint-workspace__approval-person,.joint-workspace__approval-actions{display:flex;align-items:center;gap:.6rem}.joint-workspace__approval-actions{flex-wrap:wrap}.joint-workspace__focus-status{display:flex;align-items:center;flex-wrap:wrap;gap:.75rem;margin-top:.8rem;font-size:.85rem}.joint-workspace__focus-status button{font-size:.8rem}
@media(prefers-reduced-motion:reduce){.joint-workspace__approval-toggle svg{transition:none}}
</style>

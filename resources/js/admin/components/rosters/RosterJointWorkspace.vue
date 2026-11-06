<script setup lang="ts">
import {computed,inject,onBeforeUnmount,ref,watch} from 'vue';
import {onBeforeRouteLeave,onBeforeRouteUpdate} from 'vue-router';
import AdminButton from '../form/AdminButton.vue';
import AdminDialog from '../feedback/AdminDialog.vue';
import RosterJointTimeline from './RosterJointTimeline.vue';
import RosterJointStaffTimeline from './RosterJointStaffTimeline.vue';
import RosterJointFinalization from './RosterJointFinalization.vue';
import {adminBootstrapKey} from '../../types/admin';
import type {DashboardRosterPlan} from '../../types/roster';
import {jointWorkspace,type JointLoad,type JointResult,type JointCandidate,type JointPlan} from '../../services/dashboardRosterWorkspaceApi';
import {cloneJoint,useJointRosterDraft} from '../../composables/useJointRosterDraft';
import {downloadDashboardRosterXlsx} from '../../services/dashboardRosterExportApi';
const props=defineProps<{plans:DashboardRosterPlan[];loaded:JointLoad;startOptimization?:boolean}>();
const emit=defineEmits<{refresh:[];back:[]}>();
const bootstrap=inject(adminBootstrapKey)!;
const token=bootstrap.rosterJointWorkspaceCsrfToken!;
const {state,options,dirty,changes,history,future,edit,replace,undo,redo}=useJointRosterDraft(props.loaded.state,props.loaded.existing.length>0,props.loaded.options,props.loaded.selected);
const view=ref<'lessons'|'staff'|'changes'>('lessons');
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
const timeKey=computed(()=>selected.value?`${selected.value.planId}:${selected.value.sessionId}`:'');
const staffKey=computed(()=>selected.value?`${timeKey.value}:${selected.value.cook?'cook':selected.value.groupId}`:'');
const locked=computed(()=>selectedPlan.value?.status==='published');
const originalSession=computed(()=>selected.value?props.loaded.state.find(p=>p.id===selected.value!.planId)?.sessions.find(s=>s.id===selected.value!.sessionId):null);
const timeChanged=computed(()=>selectedSession.value?.start!==originalSession.value?.start || selectedSession.value?.end!==originalSession.value?.end);
const assignmentChanged=computed(()=>selected.value?.cook ? selectedSession.value?.cook!==originalSession.value?.cook : JSON.stringify(selectedSession.value?.groups.find(g=>g.id===selected.value?.groupId))!==JSON.stringify(originalSession.value?.groups.find(g=>g.id===selected.value?.groupId)));
function assignmentPinned(pid:number,sid:number,gid:number,cook=false){
  if(cook)return options.value.staffPins.includes(`${pid}:${sid}:cook`);
  const groups=meta(pid,sid).moduleKey==='Voedsel-Innovatie'?meta(pid,sid).groupIds:[gid];
  return groups.some(id=>options.value.staffPins.includes(`${pid}:${sid}:${id}`));
}
const selectedAssignmentPinned=computed(()=>!!selected.value&&assignmentPinned(selected.value.planId,selected.value.sessionId,selected.value.groupId,selected.value.cook));

const filteredCandidates=computed(()=>candidates.value.filter(c=>name(c.id).toLocaleLowerCase('nl').includes(query.value.toLocaleLowerCase('nl'))).sort((a,b)=>Number(b.qualified)-Number(a.qualified)||Number(b.uses.length>0)-Number(a.uses.length>0)||name(a.id).localeCompare(name(b.id),'nl')));
const fingerprint=computed(()=>JSON.stringify([state.value,options.value]));
watch(fingerprint,()=>{review.value=null;search.value=null;},{flush:'sync'});
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
  selected.value={planId:pid,sessionId:sid,groupId:gid,cook:false};query.value='';adding.value=null;await loadCandidates();
}
async function loadCandidates(){
  const request=++candidateRequest;if(!selected.value)return;candidateBusy.value=true;candidates.value=[];error.value='';
  try{const result=await jointWorkspace<{candidates:JointCandidate[]}>(props.plans,token,'candidates',cloneJoint(state.value),{...cloneJoint(options.value),target:selected.value});if(request===candidateRequest)candidates.value=result.candidates;}
  catch(e){if(request===candidateRequest)error.value=e instanceof Error?e.message:'Kandidaten laden is niet gelukt.';}
  finally{if(request===candidateRequest)candidateBusy.value=false;}
}
function toggle(list:string[],key:string){const i=list.indexOf(key);if(i<0)list.push(key);else list.splice(i,1);}
function setStaff(id:number,kind:'staff'|'school'|'open'='staff'){
  if(!selected.value||locked.value||selectedAssignmentPinned.value)return;
  const t={...selected.value};
  edit(draft=>{const s=draft.find(p=>p.id===t.planId)!.sessions.find(s=>s.id===t.sessionId)!;if(t.cook)s.cook=id;else{const groups=meta(t.planId,t.sessionId).moduleKey==='Voedsel-Innovatie'?s.groups:s.groups.filter(g=>g.id===t.groupId);for(const g of groups){g.kind=kind;g.staffIds=kind==='staff'?[id]:[];}}});
  notify('Wijziging toegevoegd aan de bewerkversie.');void loadCandidates();
}
function pick(c:JointCandidate){
  if(candidateBusy.value||!c.qualified||c.reasons.length||locked.value)return;
  if(!c.selected&&!options.value.additions.some(a=>a.id===c.id)){adding.value=c.id;return;}
  setStaff(c.id);
}
function confirmAddition(){if(adding.value===null||availabilityEnd.value<=availabilityStart.value)return;const id=adding.value;options.value.additions.push({id,start:availabilityStart.value,end:availabilityEnd.value,confirmed:true});options.value.teamIds?.push(id);adding.value=null;setStaff(id);}
function swap(pid:number,sid:number,gid:number){
  const t=selected.value;if(!t||locked.value||plan(pid).status==='published')return;
  if(t.planId===pid&&t.sessionId===sid&&t.groupId===gid){error.value='Kies een andere les.';return;}
  if(action.value==='lesson'&&t.planId!==pid){error.value='Lessen wisselen kan binnen dezelfde school. Docenten kun je tussen beide scholen omwisselen.';return;}
  if(action.value==='lesson'&&!options.value.shiftLessons){error.value='Geef eerst toestemming om lessen te verschuiven.';return;}
  if(action.value==='lesson'&&(options.value.timePins.includes(`${pid}:${sid}`)||options.value.timePins.includes(`${t.planId}:${t.sessionId}`))||action.value==='swap'&&(assignmentPinned(pid,sid,gid)||assignmentPinned(t.planId,t.sessionId,t.groupId))){error.value='Deze les of toewijzing staat vast.';return;}
  edit(draft=>{const a=draft.find(p=>p.id===t.planId)!.sessions.find(s=>s.id===t.sessionId)!;const b=draft.find(p=>p.id===pid)!.sessions.find(s=>s.id===sid)!;
    if(action.value==='lesson'){const first=[a.start,a.end];[a.start,a.end]=[b.start,b.end];[b.start,b.end]=first as [string,string];}
    else{const ag=cloneJoint(a.groups.find(g=>g.id===t.groupId)!),bg=cloneJoint(b.groups.find(g=>g.id===gid)!);
      const left=meta(t.planId,t.sessionId).moduleKey==='Voedsel-Innovatie'?a.groups:a.groups.filter(g=>g.id===t.groupId);
      const right=meta(pid,sid).moduleKey==='Voedsel-Innovatie'?b.groups:b.groups.filter(g=>g.id===gid);
      for(const g of left){g.kind=bg.kind;g.staffIds=[...bg.staffIds];}for(const g of right){g.kind=ag.kind;g.staffIds=[...ag.staffIds];}}
  });action.value='replace';selected.value=null;notify('Wissel toegevoegd. Controleer het gezamenlijke resultaat vóór toepassen.');
}
async function run(mode:'preview'|'optimize'|'apply'){
  if(busy.value)return;busy.value=true;error.value='';const current=++runVersion;const abort=new AbortController();controller=abort;
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
  }catch(e){if(!abort.signal.aborted)error.value=e instanceof Error?e.message:'Het gezamenlijke voorstel is niet gelukt.';}
  finally{if(current===runVersion)busy.value=false;}
}
async function exportSaved(){try{await downloadDashboardRosterXlsx(props.plans[0]!.id,true);}catch(e){error.value=e instanceof Error?e.message:'Exporteren is niet gelukt.';}}
const presentedPlans=computed(()=>props.plans.map(p=>({...p,planning:{...p.planning,staffingSettings:{...p.planning.staffingSettings,cookStaffId:null},sessions:p.planning.sessions.map(s=>{
  const d=state.value.find(x=>x.id===p.id)!.sessions.find(x=>x.id===s.id)!;
  return {...s,startTime:d.start,endTime:d.end,cookStaffId:d.cook||null,staffAssignments:[...new Set(d.groups.flatMap(g=>g.staffIds))].map(id=>({id,name:name(id)})),groupStaffAssignments:d.groups.map(g=>({groupId:g.id,staffAssignments:g.staffIds.map(id=>({id,name:name(id)}))})),groupSupervision:d.groups.map(g=>({groupId:g.id,kind:g.kind}))};
})}})));
async function selectStaff(pid:number,sid:number,gid:number,cook:boolean){await select(pid,sid,gid);if(selected.value&&cook){selected.value.cook=true;await loadCandidates();}}
function describe(v:string|number[]){return typeof v==='string'?v:v.map(name).join(', ');}
</script>
<template>
  <section class="joint-workspace" aria-label="Gezamenlijke roosterwerkruimte">
    <p class="joint-workspace__hint">{{visitDate}} · {{plans.map(p=>p.school.name).join(' + ')}}</p>
    <div class="joint-workspace__toolbar"><AdminButton variant="secondary" @click="back">← Schoolbeheer</AdminButton><span role="status">{{dirty?`${changes.length} roosterwijzigingen · nog niet toegepast`:'Opgeslagen roosters'}}</span><AdminButton variant="secondary" @click="exportSaved">Excel exporteren</AdminButton></div>
    <details class="joint-workspace__attention" :open="showIssues"><summary @click.prevent="showIssues=!showIssues">{{loaded.readiness.report.errors.length?`${loaded.readiness.report.errors.length} aandachtspunten in de opgeslagen roosters`:'Opgeslagen roosters voldoen aan de eindcontrole'}}</summary><ul><li v-for="(issue,i) in loaded.readiness.report.errors" :key="i">{{issue.planId?`${plan(issue.planId).school.name}: `:''}}{{issue.message}}</li></ul><p v-if="dirty">Controleer de bewerkversie via Wijzigingen om het nieuwe resultaat te zien.</p></details>
    <p v-if="dirty" class="joint-workspace__hint">Excel gebruikt de opgeslagen roosters. Je bewerkversie wordt pas bewaard bij ‘Wijzigingen toepassen’.</p>
    <p v-if="error" role="alert" class="admin-inline-error">{{error}}</p><p v-if="notice" role="status" class="admin-inline-notice">{{notice}}</p>
    <div class="joint-workspace__toolbar joint-workspace__bar">
      <div role="group" aria-label="Weergave"><button v-for="item in [{key:'lessons',label:'Lesroosters'},{key:'staff',label:'Docenten'},{key:'changes',label:`Wijzigingen (${changes.length})`}]" :key="item.key" :aria-pressed="view===item.key" @click="view=item.key as typeof view">{{item.label}}</button></div>
      <div><button :disabled="busy||!history.length" @click="undo">Ongedaan maken</button><button :disabled="busy||!future.length" @click="redo">Opnieuw</button><button :aria-expanded="settings" @click="settings=!settings">Team en optimalisatie</button></div>
    </div>
    <section v-if="settings" class="admin-card joint-workspace__settings">
      <h2>Gezamenlijke inzet verbeteren</h2>
      <fieldset :disabled="busy"><legend>Welke medewerkers mogen meedoen?</legend><label><input v-model="options.teamMode" type="radio" value="existing" :disabled="!loaded.existing.length"> Bestaand team herverdelen <small>Alleen de {{loaded.existing.length}} al ingeroosterde medewerkers.</small></label><label><input v-model="options.teamMode" type="radio" value="selection"> Team samenstellen <small>Gebruik de dagselectie van beide scholen en expliciet toegevoegde medewerkers.</small></label></fieldset>
      <details v-if="options.teamMode==='selection'"><summary>Dagselectie kiezen ({{options.teamIds?.length??0}} medewerkers)</summary><div class="joint-workspace__team"><label v-for="id in [...new Set([...loaded.selected,...options.additions.map(a=>a.id)])]" :key="id"><input v-model="options.teamIds" type="checkbox" :value="id"> {{name(id)}}</label></div><p>Een medewerker toevoegen kan vanuit een les: kies de bevoegde medewerker en bevestig de beschikbaarheid.</p></details>
      <label class="joint-workspace__switch"><input v-model="options.shiftLessons" type="checkbox" role="switch" :disabled="busy"> Lessen van beide scholen mogen verschuiven</label><p class="joint-workspace__hint">Vaste afspraken en definitieve roosters blijven staan. Voedsel Innovatie verschuift alleen als die school dit in de wizard heeft vrijgegeven.</p>
      <details><summary>Medewerkers behouden in team</summary><label v-for="id in loaded.existing" :key="id"><input v-model="options.retained" type="checkbox" :value="id"> {{name(id)}}</label></details>
      <AdminButton :disabled="busy||!loaded.readiness.canOptimize" @click="run('optimize')">Gezamenlijk voorstel maken</AdminButton>
    </section>
    <p v-if="busy" role="status">{{view==='changes'?'Gezamenlijke controle uitvoeren…':'Beide roosters worden samen beoordeeld…'}} <button @click="controller?.abort()">Annuleren</button></p>
    <p v-if="action!=='replace'" class="admin-inline-notice">{{action==='swap'?'Kies de tweede docenttoewijzing in één van beide scholen.':'Kies een tweede les binnen dezelfde school.'}} <button @click="action='replace'">Annuleren</button></p>
    <RosterJointTimeline v-if="view==='lessons'" :plans="plans" :state="state" :time-pins="options.timePins" :selected="selectedKey" @select="select" />
    <RosterJointStaffTimeline v-else-if="view==='staff'" :plans="presentedPlans" @select="selectStaff" />
    <section v-else class="admin-card joint-workspace__changes"><h2>Bekijk wat verandert</h2>
      <div v-if="search" class="joint-workspace__metrics"><p>Medewerkers <strong>{{search.before.people}} → {{search.after.people}}</strong></p><p>Betaalde tussenminuten <strong>{{search.before.paidGapMinutes}} → {{search.after.paidGapMinutes}}</strong></p><p>Open plekken <strong>{{search.before.unfilledAssignments}} → {{search.after.unfilledAssignments}}</strong></p><p>KE bij school <strong>{{search.before.keSchoolAssignments}} → {{search.after.keSchoolAssignments}}</strong></p></div>
      <p v-for="person in search?.before.staff.filter(p=>p.workMinutes>0 && !(search?.after.staff.some(a=>a.staffId===p.staffId && a.workMinutes>0)))??[]" :key="person.staffId"><strong>{{person.name}}</strong> is niet meer nodig voor dit scholenpaar. Inzet op andere roosters blijft behouden.</p>
      <p v-if="search">{{search.candidateCount}} indelingen beoordeeld. {{search.searchLimited?'De zoeklimiet is bereikt; er kan nog een betere oplossing zijn.':'Dit is het beste gevonden voorstel binnen de onderzochte indelingen.'}}</p>
      <p v-if="!changes.length">Er zijn geen wijzigingen ten opzichte van de opgeslagen roosters.</p>
      <article v-for="(change,i) in changes" :key="i"><strong>{{plan(change.planId).school.name}} · {{meta(change.planId,change.sessionId).moduleLabel}}</strong><span>{{change.groupId?plan(change.planId).groups.find(g=>g.id===change.groupId)?.label:''}} · {{change.kind}}</span><p>{{describe(change.before)}} → <strong>{{describe(change.after)}}</strong></p></article>
      <div v-if="review"><p v-if="review.report.canApply" class="admin-inline-notice">De bewerkversie kan als concept worden toegepast.</p><ul v-if="review.report.errors.length"><li v-for="(issue,i) in review.report.errors" :key="i">{{issue.planId?`${plan(issue.planId).school.name}: `:''}}{{issue.message}} <button v-if="issue.planId&&issue.sessionId" @click="view='lessons';select(issue.planId,issue.sessionId,issue.groupId??meta(issue.planId,issue.sessionId).groupIds[0]!)">Bekijk les</button></li></ul><p v-if="!review.report.canFinalize">Definitief maken blijft geblokkeerd zolang verplichte begeleiding of andere harde regels ontbreken.</p></div>

    <div class="joint-workspace__toolbar"><AdminButton variant="secondary" :disabled="busy||!dirty" @click="run('preview')">Wijzigingen controleren</AdminButton><AdminButton :disabled="busy||!dirty||!review?.report.canApply" @click="run('apply')">Wijzigingen toepassen</AdminButton></div>
    </section>
    <aside v-if="selected&&selectedSession&&selectedMeta&&action==='replace'" class="joint-workspace__editor" aria-label="Les bewerken">
      <button class="joint-workspace__close" aria-label="Lespaneel sluiten" @click="selected=null;action='replace'">×</button><p class="admin-eyebrow">{{selectedPlan?.school.name}}</p><h2>{{selectedMeta.moduleLabel}}</h2><p>{{selectedSession.start}}–{{selectedSession.end}} · {{selectedPlan?.groups.find(g=>g.id===selected?.groupId)?.label}}</p>
      <p v-if="locked" class="admin-inline-notice">Dit rooster is definitief en staat vast. Heropen het via de gezamenlijke eindcontrole om te bewerken.</p>
      <template v-else>
        <label><input type="checkbox" :disabled="timeChanged" :checked="options.timePins.includes(timeKey)" @change="toggle(options.timePins,timeKey)"> Lestijd vastzetten</label><label><input type="checkbox" :disabled="assignmentChanged" :checked="options.staffPins.includes(staffKey)" @change="toggle(options.staffPins,staffKey)"> Deze docenttoewijzing vastzetten</label>
        <p v-if="timeChanged||assignmentChanged" class="joint-workspace__hint">Vastzetten bewaart de opgeslagen afspraak. Maak je wijziging eerst ongedaan als je die afspraak wilt vastzetten.</p>
        <label v-if="selectedMeta.moduleKey==='Voedsel-Innovatie'"><input v-model="selected.cook" type="checkbox" @change="loadCandidates"> Kok wijzigen</label>
        <div class="joint-workspace__toolbar"><button :disabled="selected.cook||selectedAssignmentPinned" @click="action='swap';view='lessons'">Docenten omwisselen</button><button :disabled="!options.shiftLessons||options.timePins.includes(timeKey)" @click="action='lesson';view='lessons'">Lessen wisselen</button></div>
        <label>Docent vervangen<input v-model="query" type="search" placeholder="Zoek medewerker" /></label>
        <button v-if="selectedMeta.schoolSupervisionAllowed&&!selected.cook" :disabled="selectedAssignmentPinned" @click="setStaff(0,'school')">Schoolbegeleiding gebruiken</button>
        <p v-if="candidateBusy" role="status">Geschiktheid en inzet controleren…</p>
        <div v-if="adding!==null" class="joint-workspace__addition"><strong>{{name(adding)}} toevoegen aan de dagselectie</strong><p>Bevestig de beschikbaarheid voor deze bezoekdag.</p><label>Van <input v-model="availabilityStart" type="time"></label><label>Tot <input v-model="availabilityEnd" type="time"></label><AdminButton @click="confirmAddition">Beschikbaarheid bevestigen</AdminButton></div>
        <ul class="joint-workspace__candidates"><li v-for="c in filteredCandidates" :key="c.id"><button :disabled="!c.qualified||c.reasons.length>0||selectedAssignmentPinned" @click="pick(c)"><strong>{{name(c.id)}}</strong><small>{{loaded.members.find(m=>m.id===c.id)?.employmentType==='volunteer'?'Vrijwilliger':'Betaald'}} · {{c.selected?'In dagselectie':'Beschikbaarheid nog bevestigen'}}</small><span v-if="!c.uses.length">Nog niet ingezet</span><span v-for="(use,i) in c.uses" :key="i">{{use.planId===0?'Ander rooster':`School ${plans.findIndex(p=>p.id===use.planId)+1}`}} · {{use.start}}–{{use.end}}</span><small v-for="reason in c.reasons" :key="reason">{{reason}}</small></button></li></ul>
      </template>
    </aside>
    <details v-if="!dirty" class="joint-workspace__final"><summary>Gezamenlijke eindcontrole en definitief maken</summary><RosterJointFinalization :plans="plans" @changed="emit('refresh')" /></details>
  </section>
  <AdminDialog :open="leaveOpen" title="Bewerkversie verlaten?" :close-on-backdrop="false" @close="answerLeave(false)">
    <p>Je wijzigingen zijn nog niet toegepast. Als je nu weggaat, vervallen deze wijzigingen. De opgeslagen roosters blijven behouden.</p>
    <template #footer><AdminButton @click="answerLeave(false)">Verder bewerken</AdminButton><AdminButton variant="secondary" @click="answerLeave(true)">Verlaten zonder toepassen</AdminButton></template>
  </AdminDialog>
</template>
<style scoped>
.joint-workspace{display:grid;gap:1rem;min-width:0}.joint-workspace__toolbar{display:flex;flex-wrap:wrap;gap:.65rem;align-items:center;justify-content:space-between}.joint-workspace__toolbar>div{display:flex;flex-wrap:wrap;gap:.4rem}.joint-workspace button:not(.admin-button){font:inherit;cursor:pointer;border:1px solid #becde3;border-radius:.5rem;padding:.6rem .85rem;background:var(--admin-surface);color:#16345f}.joint-workspace button[aria-pressed=true]{background:var(--admin-header-bg);color:white}.joint-workspace button:disabled{opacity:.5;cursor:not-allowed}.joint-workspace button:focus-visible{outline:3px solid #759cdf;outline-offset:3px}.joint-workspace__bar{padding:.7rem;background:#edf2f9;border:1px solid #d4deed;border-radius:.7rem}.joint-workspace__attention{padding:.65rem .8rem;background:#f0f5fd;border:1px solid #cad8eb;border-radius:.55rem;font-size:.85rem}.joint-workspace__hint{font-size:.85rem;color:#546580;margin:0}.joint-workspace__settings{display:grid;gap:.85rem}.joint-workspace h2{font-size:1.2rem;margin:0 0 .6rem}.joint-workspace fieldset{display:grid;gap:.8rem;border:1px solid var(--admin-border);border-radius:.6rem;padding:1rem}.joint-workspace label{display:block}.joint-workspace label small{display:block;margin-left:1.5rem;color:#5b6c86}.joint-workspace__switch{padding:1rem;border:1px solid #98b5db;border-radius:.6rem;background:#edf5ff;font-weight:bold}.joint-workspace__switch input{accent-color:#173e72;width:1.2rem;height:1.2rem}.joint-workspace__metrics{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:.75rem}.joint-workspace__metrics p{background:#f1f5fb;border:1px solid #d2deef;border-radius:.6rem;padding:.8rem}.joint-workspace__metrics strong{display:block;font-size:1.4rem}.joint-workspace__changes{display:grid;gap:1rem}.joint-workspace__changes article{border-bottom:1px solid #d9e1ed;padding:.8rem 0;display:grid;gap:.35rem}.joint-workspace__changes p{margin:0}.joint-workspace__editor{position:fixed;right:0;top:0;bottom:0;width:min(430px,95vw);background:var(--admin-surface);box-shadow:-8px 0 40px #142c5540;z-index:30;padding:1.5rem;overflow:auto;display:flex;flex-direction:column;gap:.8rem;border-left:3px solid #234675}.joint-workspace__close{align-self:flex-end}.joint-workspace__editor input[type=search]{width:100%;padding:.65rem;border:1px solid #bccce1;border-radius:.5rem;margin-top:.4rem}.joint-workspace__candidates{padding:0;margin:0;list-style:none;display:grid;gap:.5rem}.joint-workspace__candidates button{display:grid;gap:.35rem;text-align:left;width:100%}.joint-workspace__candidates small,.joint-workspace__candidates span{font-size:.8rem}.joint-workspace__addition{padding:1rem;background:#edf5ff;border-radius:.6rem}.joint-workspace__person{border-top:1px solid #d6e0ee;padding:1rem 0}.joint-workspace__person header{display:flex;justify-content:space-between;margin-bottom:.6rem}.joint-workspace__person>div{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:.5rem}.joint-workspace__person button{display:grid;gap:.4rem;text-align:left}.joint-workspace__person small,.joint-workspace__person span{font-size:.8rem}.joint-workspace__final{padding:1rem;border:1px solid var(--admin-border);border-radius:.7rem}.joint-workspace summary{cursor:pointer;font-weight:600}.joint-workspace input{accent-color:#173e72}
</style>

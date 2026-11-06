<script setup lang="ts">
import {computed,ref} from 'vue';
import type {DashboardRosterPlan} from '../../types/roster';
import type {JointPlan,JointSession,JointGroup} from '../../services/dashboardRosterWorkspaceApi';
const props=defineProps<{plans:DashboardRosterPlan[];state:JointPlan[];mode:'modules'|'staff';letters:Record<number,string>;approved:number[];readonly?:boolean;selected?:string}>();
const emit=defineEmits<{select:[planId:number,sessionId:number,groupId:number]}>();
const school=ref(props.plans[0]?.id);
const minutes=(t:string)=>Number(t.slice(0,2))*60+Number(t.slice(3,5));
const points=computed(()=>[...new Set([...props.state.flatMap(p=>p.sessions.flatMap(s=>[s.start,s.end])),...props.plans.flatMap(p=>(p.planning.rounds??[]).flatMap(r=>[r.startTime.slice(0,5),r.endTime.slice(0,5)]))])].sort());
const scale=computed(()=>Math.max(2.6,80/Math.min(45,...props.state.flatMap(p=>p.sessions.map(s=>minutes(s.end)-minutes(s.start))))));
const scrolls=new Map<number,HTMLElement>();
function bindScroll(id:number,element:unknown){if(element instanceof HTMLElement)scrolls.set(id,element);else scrolls.delete(id);}
function syncScroll(event:Event){const source=event.target;if(!(source instanceof HTMLElement))return;for(const target of scrolls.values())if(target!==source&&Math.abs(target.scrollTop-source.scrollTop)>1)target.scrollTop=source.scrollTop;}
const rows=computed(()=>`auto ${points.value.slice(0,-1).map((t,i)=>`${Math.max(8,(minutes(points.value[i+1]!)-minutes(t))*scale.value)}px`).join(' ')}`);
function position(s:JointSession,col:number){return{gridColumn:String(col+2),gridRow:`${points.value.indexOf(s.start)+2} / ${points.value.indexOf(s.end)+2}`};}
function meta(pid:number,sid:number){return props.plans.find(p=>p.id===pid)!.planning.sessions.find(s=>s.id===sid)!;}
function approvedLesson(s:JointSession){return [s.cook,...s.groups.flatMap(g=>g.staffIds)].some(id=>props.approved.includes(id));}
function isLesson(pid:number,start:string,end:string){return props.state.find(p=>p.id===pid)!.sessions.some(s=>s.start<end&&s.end>start);}
function codes(g:JointGroup){return g.kind==='school'?'School':g.staffIds.length?g.staffIds.map(id=>props.letters[id]??'?').join(' + '):'Open';}
function labels(g:JointGroup){return g.kind==='school'?'Schoolbegeleiding':g.staffIds.length?g.staffIds.map(id=>props.plans.flatMap(p=>p.planning.staffCatalog).find(m=>m.id===id)?.name??`#${id}`).join(', '):'Open docentplek';}
</script>
<template>
  <section class="joint-timelines" :class="{'joint-timelines--many':plans.some(p=>p.groups.length>4),'joint-timelines--staff':mode==='staff'}" aria-label="Lesroosters van beide scholen">
    <div class="joint-timelines__switch" role="group" aria-label="School kiezen"><button v-for="p in plans" :key="p.id" :aria-pressed="school===p.id" @click="school=p.id">{{p.school.name}}</button></div>
    <article v-for="(plan,index) in plans" :key="plan.id" :class="{'joint-timelines__inactive':school!==plan.id}">
      <header><span>School {{index+1}} · {{plan.status==='published'?'Definitief · vast':'Concept'}}</span><h2>{{plan.school.name}}</h2><p>{{plan.education.programLabel}} · {{plan.groups.length}} groepen</p></header>
      <div :ref="el=>bindScroll(plan.id,el)" @scroll="syncScroll" class="joint-timelines__scroll" tabindex="0" :aria-label="`Lesrooster ${plan.school.name}`" role="region">
        <div class="joint-timelines__grid" :style="{gridTemplateColumns:`64px repeat(${plan.groups.length}, minmax(130px,1fr))`,gridTemplateRows:rows}">
          <strong class="joint-timelines__heading">Tijd</strong><strong v-for="g in plan.groups" :key="g.id" class="joint-timelines__heading">{{g.label}}</strong>
          <span v-for="(time,i) in points.slice(0,-1)" :key="time" class="joint-timelines__time" :class="{'joint-timelines__time--lesson':isLesson(plan.id,time,points[i+1]!)}" :style="{gridColumn:'1',gridRow:String(i+2)}">{{time}}</span>
          <template v-for="s in state.find(p=>p.id===plan.id)?.sessions??[]" :key="s.id">
            <button v-for="g in s.groups" :key="g.id" class="joint-timelines__lesson" :disabled="readonly||plan.status==='published'||mode==='modules'&&approvedLesson(s)" :aria-label="`${plan.school.name}, ${plan.groups.find(group=>group.id===g.id)?.label}, ${s.start}–${s.end}, ${meta(plan.id,s.id).moduleLabel}, ${labels(g)}`" :class="{'joint-timelines__lesson--selected':selected===`${plan.id}:${s.id}:${g.id}`,'joint-timelines__lesson--approved':g.staffIds.some(id=>approved.includes(id))}" :style="{...position(s,plan.groups.findIndex(group=>group.id===g.id)),backgroundColor:meta(plan.id,s.id).color}" @click="emit('select',plan.id,s.id,g.id)">
              <strong class="joint-timelines__module">{{meta(plan.id,s.id).moduleLabel}}</strong><span class="joint-timelines__staff" :title="labels(g)">{{codes(g)}}<small v-if="g.staffIds.some(id=>approved.includes(id))" aria-label="Medewerker goedgekeurd"> ✓</small></span>
              <small v-if="s.cook" class="joint-timelines__cook" :title="plan.planning.staffCatalog.find(m=>m.id===s.cook)?.name">Kok {{letters[s.cook]}}{{approved.includes(s.cook)?' ✓':''}}</small>
            </button>
          </template>
          <div v-for="(block,i) in (plan.planning.rounds??[]).filter(b=>b.type!=='round')" :key="`block${i}`" class="joint-timelines__break" :style="{gridColumn:`2 / span ${plan.groups.length}`,gridRow:`${points.indexOf(block.startTime.slice(0,5))+2} / ${points.indexOf(block.endTime.slice(0,5))+2}`}">{{block.label}}</div>
        </div>
      </div>
    </article>
  </section>
</template>
<style scoped>
.joint-timelines{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:1rem;min-width:0;container-type:inline-size}.joint-timelines article{min-width:0;border:1px solid var(--admin-border);border-radius:.75rem;background:var(--admin-surface);overflow:hidden}.joint-timelines header{padding:1rem;background:var(--admin-surface-soft);min-height:130px}.joint-timelines h2{font-size:1.1rem;margin:.4rem 0}.joint-timelines p,.joint-timelines header span{font-size:.85rem;color:#536582;margin:0}.joint-timelines__scroll{overflow:auto;max-height:76vh;scrollbar-width:thin;scrollbar-color:#7e9bc6 transparent}.joint-timelines__grid{display:grid;min-width:max-content}.joint-timelines__heading{background:#eaf0fa;padding:.8rem;position:sticky;top:0;z-index:2;border-bottom:1px solid #b2bfd3}.joint-timelines__time{font-size:.72rem;padding:.2rem;background:#f5f7fa;border-top:1px solid #dde3ee;position:sticky;left:0;z-index:1}.joint-timelines__lesson{border:1px solid #ffffffa0;display:flex;flex-direction:column;justify-content:center;gap:.3rem;padding:.4rem;color:#102040;cursor:pointer;min-width:0;overflow:auto;font:inherit;line-height:1.25;text-align:center}.joint-timelines__lesson{white-space:normal;overflow-wrap:anywhere}.joint-timelines__lesson strong{font-size:.83rem;max-width:100%;white-space:normal}.joint-timelines__lesson span,.joint-timelines__lesson small{font-size:.72rem}.joint-timelines__lesson:hover{filter:brightness(.97);outline:2px solid #496998;outline-offset:-3px}.joint-timelines__lesson--selected{outline:3px solid #112b59!important;outline-offset:-4px}.joint-timelines__break{display:grid;place-content:center;background:#f5f7fa;color:#66748d;border:1px solid white;font-size:.75rem}.joint-timelines__switch{display:none;gap:.5rem}.joint-timelines__switch button{padding:.7rem;border:1px solid #bac9df;border-radius:.5rem;background:var(--admin-surface);cursor:pointer;color:#172c54}.joint-timelines__switch button[aria-pressed=true]{background:var(--admin-header-bg);color:white}
@media(max-width:1500px){.joint-timelines{grid-template-columns:1fr}.joint-timelines__switch{display:flex;flex-wrap:wrap}.joint-timelines__inactive{display:none}.joint-timelines header{min-height:0}}
@media(min-width:1501px){.joint-timelines--many{grid-template-columns:1fr}.joint-timelines--many .joint-timelines__switch{display:flex}.joint-timelines--many .joint-timelines__inactive{display:none}}
.joint-timelines__time{background:#f8f9fb;color:#63728a}.joint-timelines__time--lesson{background:#dfe8f5;color:#102c54;font-weight:700}.joint-timelines__lesson .joint-timelines__staff{font-size:1rem;opacity:.7;font-weight:600}.joint-timelines--staff .joint-timelines__lesson .joint-timelines__module{font-size:.75rem;font-weight:500;opacity:.72}.joint-timelines--staff .joint-timelines__lesson .joint-timelines__staff{font-size:1.2rem;opacity:1;background:#ffffffc9;border:1px solid #ffffff;border-radius:.35rem;padding:.2rem .6rem;align-self:center}.joint-timelines--staff .joint-timelines__cook{font-weight:700}.joint-timelines__lesson--approved .joint-timelines__staff{border-color:#5d7eaa!important}.joint-timelines__lesson:disabled{cursor:default}.joint-timelines__lesson:disabled:hover{filter:none;outline:none}.joint-timelines__lesson:focus-visible{outline:3px solid #173e72;outline-offset:-4px}
</style>

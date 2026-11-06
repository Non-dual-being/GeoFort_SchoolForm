<script setup lang="ts">
import {computed} from 'vue';
import type {DashboardRosterPlan} from '../../types/roster';
const props=defineProps<{plans:DashboardRosterPlan[];letters:Record<number,string>;approved:number[]}>();
function textColor(color:string) {
  const hex = color.replace('#','');
  if (!/^[0-9a-f]{6}$/i.test(hex)) return '#111827';
  const rgb = [0,2,4].map(i => parseInt(hex.slice(i,i+2),16));
  return rgb[0]!*0.299 + rgb[1]!*0.587 + rgb[2]!*0.114 > 145 ? '#111827' : '#ffffff';
}
interface Assignment { key:string; planId:number; sessionId:number; groupId:number; staffId:number; name:string; school:number; schoolName:string; start:string; end:string; module:string; label:string; color:string; cook:boolean; groups:string[] }
const assignments = computed(() => {
  const rows = new Map<string, Assignment>();
  props.plans.forEach((plan, index) => {
    for (const session of plan.planning.sessions) {
      const people = new Map<number, { name:string; groups:Set<string>; groupIds:Set<number>; cook:boolean }>();
      session.groupIds.forEach((groupId, g) => {
        const kind = session.groupSupervision?.find(item => item.groupId === groupId)?.kind;
        const specific = session.groupStaffAssignments?.find(item => item.groupId === groupId)?.staffAssignments ?? [];
        const staff = kind === 'open' || kind === 'school' ? [] : kind === 'staff' ? specific : specific.length ? specific : session.staffAssignments;
        for (const person of staff) {
          const row = people.get(person.id) ?? { name:person.name, groups:new Set<string>(), groupIds:new Set<number>(), cook:false };
          row.groupIds.add(groupId); row.groups.add(plan.groups.find(group => group.id === groupId)?.label ?? session.groupLabels[g] ?? `Groep ${groupId}`); people.set(person.id,row);
        }
      });
      const cookId = session.moduleKey === 'Voedsel-Innovatie' ? (session.cookStaffId ?? plan.planning.staffingSettings.cookStaffId) : null;
      if (cookId !== null) people.set(cookId,{ name:plan.planning.staffCatalog.find(p => p.id === cookId)?.name ?? `Kok #${cookId}`, groups:new Set(session.groupLabels),groupIds:new Set(session.groupIds),cook:true });
      for (const [staffId, person] of people) {
        const key = `${plan.id}:${staffId}:${session.startTime}:${session.endTime}:${session.moduleKey}:${person.cook}`;
        const row = rows.get(key) ?? { key,planId:plan.id,sessionId:session.id,groupId:[...person.groupIds][0]!,staffId,name:person.name,school:index+1,schoolName:plan.school.name,start:session.startTime.slice(0,5),end:session.endTime.slice(0,5),module:session.moduleKey,label:session.moduleLabel,color:session.color,cook:person.cook,groups:[] };
        row.groups = [...new Set([...row.groups,...person.groups])]; rows.set(key,row);
      }
    }
  });
  return [...rows.values()];
});
const staff = computed(() => [...new Map(assignments.value.map(row => [row.staffId,{id:row.staffId,name:row.name}])).values()].sort((a,b) => a.name.localeCompare(b.name,'nl')));
// These are school timetable blocks, not additional staff assignments. A lesson at
// either school always takes precedence over a school break/welcome for that person.
const neutralBlocks = computed(() => props.plans.flatMap((plan,index) => {
  const people = [...new Map(assignments.value.filter(row => row.school === index+1).map(row => [row.staffId,row])).values()];
  return (plan.planning.rounds ?? []).filter(block => block.type !== 'round').flatMap((block,i) => people.map(person => ({
    key:`neutral:${plan.id}:${i}:${person.staffId}`, staffId:person.staffId, school:index+1, schoolName:plan.school.name,
    start:block.startTime.slice(0,5), end:block.endTime.slice(0,5), label:block.label,
  })));
}));
function neutralCell(id:number,start:string,end:string) {
  if (cell(id,start,end).length) return [];
  const merged = new Map<string,{key:string; label:string; schools:number[]}>();
  for (const row of neutralBlocks.value.filter(row => row.staffId === id && row.start < end && row.end > start)) {
    const key = row.label.trim().toLocaleLowerCase('nl');
    const item = merged.get(key) ?? {key,label:row.label,schools:[]};
    if (!item.schools.includes(row.school)) item.schools.push(row.school);
    merged.set(key,item);
  }
  return [...merged.values()];
}
const slots = computed(() => {
  const points = [...new Set([...assignments.value,...neutralBlocks.value].flatMap(row => [row.start,row.end]))].sort();
  return points.slice(0,-1).map((start,i) => ({start,end:points[i+1]!}));
});
function cell(id:number,start:string,end:string) { return assignments.value.filter(row => row.staffId===id && row.start<end && row.end>start); }
// Merge only slices of the same stored assignment, never adjacent separate lessons.
const spans = computed(() => {
  const result = new Map<number, number[]>();
  for (const person of staff.value) {
    const values = slots.value.map(slot => cell(person.id, slot.start, slot.end).map(item => item.key).sort().join('|'));
    const sizes = values.map(() => 1);
    for (let i = 0; i < values.length; i++) {
      if (!values[i]) continue;
      let end = i + 1;
      while (end < values.length && values[end] === values[i]) { sizes[end] = 0; end++; }
      sizes[i] = end - i;
      i = end - 1;
    }
    result.set(person.id, sizes);
  }
  return result;
});
</script>
<template>
    <section class="admin-card joint-overview" aria-labelledby="joint-teachers-title">
      <h2 id="joint-teachers-title">Gezamenlijk docentenrooster</h2>
      <p>Alleen bekijken · beide scholen op één tijdlijn. Dit overzicht volgt de wijzigingen in de lesroosters hierboven.</p>
      <p v-if="!staff.length">Er zijn nog geen docenten ingeroosterd. Kies Team samenstellen om te beginnen.</p>
      <div v-else class="joint-overview__table" tabindex="0" role="region" aria-label="Docentenrooster voor beide scholen">
        <table><thead><tr><th scope="col">Tijd</th><th v-for="person in staff" :key="person.id" scope="col"><span class="joint-overview__letter">{{letters[person.id]}}</span> {{ person.name }}<small v-if="approved.includes(person.id)" class="joint-overview__approved">✓ Goedgekeurd · vast</small></th></tr></thead>
          <tbody><tr v-for="(slot, slotIndex) in slots" :key="slot.start"><th scope="row" :class="{ 'joint-overview__neutral-time': !assignments.some(row => row.start < slot.end && row.end > slot.start) }">{{ slot.start }}–{{ slot.end }}</th>
            <template v-for="person in staff" :key="person.id">
            <td v-if="spans.get(person.id)?.[slotIndex]" :rowspan="spans.get(person.id)?.[slotIndex]" :style="(spans.get(person.id)?.[slotIndex] ?? 1) > 1 && cell(person.id,slot.start,slot.end).length === 1 ? { backgroundColor: cell(person.id,slot.start,slot.end)[0]?.color } : undefined">
              <div v-for="item in cell(person.id,slot.start,slot.end)" :key="item.key" class="joint-overview__assignment" :class="`joint-overview__assignment--${item.school}`" :style="{ backgroundColor:item.color, color:textColor(item.color) }">
                <strong>[School {{ item.school }}]</strong><span>{{ item.cook ? 'Kok · ' : '' }}{{ item.label }}</span><small>{{ item.groups.map(group => `(${group.replace(/^Groep\s+(\d+)$/i, 'Groep$1')})`).join(' ') }}</small>
              </div>
              <div v-for="item in neutralCell(person.id,slot.start,slot.end)" :key="item.key" class="joint-overview__assignment joint-overview__neutral" :class="item.schools.length > 1 ? 'joint-overview__assignment--shared' : `joint-overview__assignment--${item.schools[0]}`">
                <strong>{{ item.schools.map(school => `[School ${school}]`).join(' · ') }}</strong><span>{{ item.label }}</span>
              </div>
              <span class="joint-overview__free" v-if="!cell(person.id,slot.start,slot.end).length && !neutralCell(person.id,slot.start,slot.end).length">Vrij</span>
            </td>
            </template>
          </tr></tbody>
        </table>
      </div>
    </section>
</template>
<style scoped>

.joint-overview { display:grid; gap:1rem; min-width:0; }
.joint-overview__actions { display:flex; flex-wrap:wrap; gap:1rem; margin-top:1.25rem; align-items:center; }
.joint-overview__free { display:block; background:#eef0f2; color:#4b5563; padding:.75rem; border-radius:.35rem; }
.joint-overview__neutral { background:#fff; color:#374151; }
.joint-overview__manage { min-width:0; }
.joint-overview__schools { display:grid; grid-template-columns:minmax(0,1fr); gap:1rem; }
.joint-overview__school { min-width:0; overflow:hidden; }
.joint-overview__table { overflow:auto; margin-top:1rem; max-height:42rem; }
.joint-overview table { border-collapse:collapse; width:100%; }
.joint-overview th,.joint-overview td { padding:.65rem; border:1px solid var(--admin-border); vertical-align:top; text-align:left; }
.joint-overview thead th { position:sticky; top:0; background:var(--admin-surface-soft); z-index:1; }
.joint-overview tbody th { white-space:nowrap; background:#dfe8f5; }
.joint-overview td { min-width:13rem; }
.joint-overview td[rowspan]:not([rowspan="1"]) { vertical-align:middle; }
.joint-overview__assignment { display:grid; gap:.25rem; border-left:5px solid #374151; border-radius:.35rem; padding:.6rem; margin-bottom:.4rem; }
.joint-overview__assignment--2 { border-color:#9ca3af; }
.joint-overview__assignment--shared { border-left:0; position:relative; padding-left:calc(.6rem + 5px); }
.joint-overview__assignment--shared::before { content:''; position:absolute; left:0; top:0; bottom:0; width:5px; border-radius:.35rem 0 0 .35rem; background:linear-gradient(to bottom,#374151 0 50%,#9ca3af 50% 100%); }
.joint-overview .joint-overview__neutral-time { background:#fff; }
@media(max-width:900px) { .joint-overview__schools { grid-template-columns:1fr; } }

.joint-overview__letter{display:inline-grid;place-content:center;min-width:1.8rem;min-height:1.8rem;border:1px solid #b3c4df;border-radius:.3rem;background:white}.joint-overview__approved{display:block;margin-top:.4rem;color:#34577e;font-weight:500}
</style>

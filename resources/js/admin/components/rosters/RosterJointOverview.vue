<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { scrollToRosterAction } from '../../services/scrollToRosterAction';
import DashboardRosterDetailView from '../../views/DashboardRosterDetailView.vue';
import { downloadDashboardRosterXlsx } from '../../services/dashboardRosterExportApi';
import AdminButton from '../form/AdminButton.vue';
import RosterJointFinalization from './RosterJointFinalization.vue';
import RosterJointOptimization from './RosterJointOptimization.vue';
import RosterDaySchedule from './RosterDaySchedule.vue';
import type { DashboardRosterPlan, RosterDayPreview } from '../../types/roster';
const props = defineProps<{ plans: DashboardRosterPlan[]; preview: RosterDayPreview | null; checking: boolean; startOptimization?: boolean }>();
const emit = defineEmits<{ edit: [id: number]; refresh: [] }>();
const mode = ref<'view' | 'manage'>('view');
watch(() => props.startOptimization, value => {
  if (!value) return;
  mode.value = 'manage';
  void scrollToRosterAction(() => document.getElementById('joint-optimize-title'));
}, { immediate: true });
const selectedSchool = ref<number | null>(null);
const activeSchool = computed(() => props.plans.find(p => p.id === selectedSchool.value) ?? props.plans[0]);
const exporting = ref(false);
const exportError = ref('');
async function exportJoint() {
  if (!props.plans[0] || exporting.value) return;
  exporting.value = true; exportError.value = '';
  try { await downloadDashboardRosterXlsx(props.plans[0].id, true); }
  catch (error) { exportError.value = error instanceof Error ? error.message : 'Exporteren is niet gelukt.'; }
  finally { exporting.value = false; }
}
function textColor(color:string) {
  const hex = color.replace('#','');
  if (!/^[0-9a-f]{6}$/i.test(hex)) return '#111827';
  const rgb = [0,2,4].map(i => parseInt(hex.slice(i,i+2),16));
  return rgb[0]!*0.299 + rgb[1]!*0.587 + rgb[2]!*0.114 > 145 ? '#111827' : '#ffffff';
}
interface Assignment { key:string; staffId:number; name:string; school:number; schoolName:string; start:string; end:string; module:string; label:string; color:string; cook:boolean; groups:string[] }
const assignments = computed(() => {
  const rows = new Map<string, Assignment>();
  props.plans.forEach((plan, index) => {
    for (const session of plan.planning.sessions) {
      const people = new Map<number, { name:string; groups:Set<string>; cook:boolean }>();
      session.groupIds.forEach((groupId, g) => {
        const kind = session.groupSupervision?.find(item => item.groupId === groupId)?.kind;
        const specific = session.groupStaffAssignments?.find(item => item.groupId === groupId)?.staffAssignments ?? [];
        const staff = kind === 'open' || kind === 'school' ? [] : kind === 'staff' ? specific : specific.length ? specific : session.staffAssignments;
        for (const person of staff) {
          const row = people.get(person.id) ?? { name:person.name, groups:new Set<string>(), cook:false };
          row.groups.add(plan.groups.find(group => group.id === groupId)?.label ?? session.groupLabels[g] ?? `Groep ${groupId}`); people.set(person.id,row);
        }
      });
      const cookId = session.moduleKey === 'Voedsel-Innovatie' ? (session.cookStaffId ?? plan.planning.staffingSettings.cookStaffId) : null;
      if (cookId !== null) people.set(cookId,{ name:plan.planning.staffCatalog.find(p => p.id === cookId)?.name ?? `Kok #${cookId}`, groups:new Set(session.groupLabels),cook:true });
      for (const [staffId, person] of people) {
        const key = `${plan.id}:${staffId}:${session.startTime}:${session.endTime}:${session.moduleKey}:${person.cook}`;
        const row = rows.get(key) ?? { key,staffId,name:person.name,school:index+1,schoolName:plan.school.name,start:session.startTime.slice(0,5),end:session.endTime.slice(0,5),module:session.moduleKey,label:session.moduleLabel,color:session.color,cook:person.cook,groups:[] };
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
const warnings = computed(() => props.preview ? [...props.preview.savedControl.day.warnings, ...Object.values(props.preview.savedControl.plans).flatMap(report => report.warnings.map(issue => ({...issue, message: `[School ${props.plans.findIndex(p => p.id === report.planId) + 1}] ${issue.message}`})))] : []);
const issues = computed(() => props.preview ? [...props.preview.savedControl.day.errors,...Object.values(props.preview.savedControl.plans).flatMap(report => report.errors.map(issue => ({...issue,message:`${props.plans.find(p=>p.id===report.planId)?.school.name ?? 'School'}: ${issue.message}`})))] : []);
</script>
<template>
  <section class="joint-overview" aria-label="Gezamenlijk roosteroverzicht">
    <header class="admin-card">
      <p class="admin-eyebrow">Samen controleren · opgeslagen roosters</p>
      <h2>Twee lesroosters, één docentenrooster</h2>
      <p>Bekijk beide scholen samen of pas lessen en personeel handmatig aan. Elke opgeslagen wijziging wordt tegen het andere rooster gecontroleerd.</p>
      <div class="joint-overview__actions">
      <AdminButton :disabled="checking" @click="$emit('refresh')">{{ checking ? 'Controleren…' : 'Opnieuw controleren' }}</AdminButton>
      <AdminButton variant="secondary" :loading="exporting" @click="exportJoint">Gezamenlijk Excel exporteren</AdminButton>
      </div>
      <p v-if="exportError" class="admin-inline-error" role="alert">{{ exportError }}</p>
    </header>
    <div class="admin-roster-workspace-bar__mode" role="group" aria-label="Gezamenlijke werkmodus">
      <button type="button" :aria-pressed="mode === 'view'" @click="mode = 'view'">Bekijk</button>
      <button type="button" :aria-pressed="mode === 'manage'" @click="mode = 'manage'">Beheer</button>
    </div>
    <section v-if="mode === 'manage'" class="joint-overview__manage" aria-label="Gezamenlijk handmatig beheer">
      <RosterJointOptimization :plans="plans" @changed="emit('refresh')" />
      <nav class="admin-roster-workspace-tabs" aria-label="School beheren">
        <button v-for="(plan,index) in plans" :key="plan.id" type="button" :aria-pressed="activeSchool?.id === plan.id" @click="selectedSchool = plan.id">School {{ index+1 }} · {{ plan.school.name }}</button>
      </nav>
      <p>Je bewerkt deze school binnen de gezamenlijke planning. De harde personeelsregels gelden voor beide scholen.</p>
      <DashboardRosterDetailView v-if="activeSchool" :key="`${activeSchool.id}:${activeSchool.revision}`" :embedded-plan-id="activeSchool.id" manual-only @changed="emit('refresh')" />
    </section>
    <div v-if="mode === 'view'" class="joint-overview__schools">
      <article v-for="(plan,index) in plans" :key="plan.id" class="admin-card joint-overview__school">
        <p class="admin-eyebrow">School {{ index+1 }} · {{ plan.status === 'published' ? 'Definitief' : 'Concept' }}</p>
        <h2>{{ plan.school.name }}</h2>
        <p>{{ plan.groups.length }} groepen · {{ plan.education.programLabel }}</p>
        <RosterDaySchedule :groups="plan.groups" :sessions="plan.planning.sessions" :rounds="plan.planning.rounds" />
      </article>
    </div>
    <section class="admin-card" aria-labelledby="joint-teachers-title">
      <h2 id="joint-teachers-title">Gezamenlijk docentenrooster</h2>
      <p>Elke docent staat één keer in het overzicht. De tijden volgen beide lesroosters. Aankomst, welkom en pauzes staan per docent in wit volgens de betrokken school. Een ingeroosterde les bij een van beide scholen gaat voor. Vrij betekent: geen opgeslagen inzet of schoolactiviteit in dit tijdvak. Alleen ingeroosterde docenten worden getoond.</p>
      <p v-if="!staff.length">Er is nog geen personeelsinzet opgeslagen.</p>
      <div v-else class="joint-overview__table" tabindex="0" role="region" aria-label="Docentenrooster voor beide scholen">
        <table><thead><tr><th scope="col">Tijd</th><th v-for="person in staff" :key="person.id" scope="col">{{ person.name }}</th></tr></thead>
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
    <section class="admin-card" aria-labelledby="joint-attention-title">
      <h2 id="joint-attention-title">Wat heeft nog aandacht nodig?</h2>
      <p v-if="checking" role="status">De opgeslagen indelingen worden gecontroleerd…</p>
      <p v-else-if="!preview">De controle is nog niet beschikbaar. Controleer beide roosters opnieuw.</p>
      <template v-else>
        <p v-if="issues.length">Los deze punten op voordat beide scholen definitief kunnen worden ingepland.</p>
        <p v-else-if="preview.savedControl.canFinalize" class="admin-inline-notice">De opgeslagen roosters voldoen samen aan de gecontroleerde harde regels.</p>
        <ul v-if="issues.length"><li v-for="(issue,i) in issues" :key="i">{{ issue.message }}</li></ul>
        <ul v-if="warnings.length"><li v-for="(issue,i) in warnings" :key="i">Waarschuwing: {{ issue.message }}</li></ul>
      </template>
      <p v-if="mode === 'view'">Open Beheer voor gezamenlijke optimalisatie, eindcontrole en finaliseren.</p>
    </section>
    <RosterJointFinalization v-if="mode === 'manage'" :plans="plans" @changed="emit('refresh')" />
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
.joint-overview tbody th { white-space:nowrap; }
.joint-overview td { min-width:13rem; }
.joint-overview td[rowspan]:not([rowspan="1"]) { vertical-align:middle; }
.joint-overview__assignment { display:grid; gap:.25rem; border-left:5px solid #374151; border-radius:.35rem; padding:.6rem; margin-bottom:.4rem; }
.joint-overview__assignment--2 { border-color:#9ca3af; }
.joint-overview__assignment--shared { border-left:0; position:relative; padding-left:calc(.6rem + 5px); }
.joint-overview__assignment--shared::before { content:''; position:absolute; left:0; top:0; bottom:0; width:5px; border-radius:.35rem 0 0 .35rem; background:linear-gradient(to bottom,#374151 0 50%,#9ca3af 50% 100%); }
.joint-overview .joint-overview__neutral-time { background:#fff; }
@media(max-width:900px) { .joint-overview__schools { grid-template-columns:1fr; } }
</style>

<script setup lang="ts">
import { computed, inject, onBeforeUnmount, ref, watch } from 'vue';
import AdminButton from '../form/AdminButton.vue';
import { adminBootstrapKey } from '../../types/admin';
import type { DashboardRosterPlan } from '../../types/roster';
import { optimizeJointRoster, type JointOptimization } from '../../services/dashboardRosterOptimizationApi';
const props = defineProps<{ plans: DashboardRosterPlan[] }>();
const emit = defineEmits<{ changed: [] }>();
const bootstrap = inject(adminBootstrapKey)!;
let successTimer: ReturnType<typeof setTimeout> | undefined;
onBeforeUnmount(() => clearTimeout(successTimer));
const busy = ref(false); const error = ref(''); const success = ref('');
const proposal = ref<JointOptimization | null>(null);
const version = computed(() => props.plans.map(p => `${p.id}:${p.revision}:${p.planning.catalogRevision}`).join(','));
const ready = computed(() => props.plans.length === 2 && props.plans.every(p => p.status === 'concept' && p.jointPlanning && p.planning.sessions.length && p.planning.selectedStaffIds.length));
watch(version, () => { proposal.value = null; error.value = ''; });
const people = computed(() => proposal.value?.before.staff.map(p => ({ ...p, after: proposal.value!.after.staff.find(a => a.staffId === p.staffId)! })).filter(p => p.after && (p.gapMinutes !== p.after.gapMinutes || p.workMinutes !== p.after.workMinutes || p.cookMinutes !== p.after.cookMinutes)) ?? []);
async function run(apply = false) {
  if (busy.value || !ready.value) return;
  const signature = apply ? proposal.value?.signature : undefined;
  if (apply && !signature) return;
  const requestedVersion = version.value;
  busy.value = true; error.value = ''; success.value = '';
  try {
    const result = await optimizeJointRoster(props.plans, bootstrap.rosterJointOptimizeCsrfToken!, signature);
    if (apply) { proposal.value = null; success.value = 'De personeelsinzet van beide scholen is samen bijgewerkt.'; clearTimeout(successTimer); successTimer = setTimeout(() => { success.value = ''; }, 8000); emit('changed'); }
    else if (version.value === requestedVersion) proposal.value = result;
  } catch (e) { proposal.value = null; error.value = e instanceof Error ? e.message : 'Optimaliseren is niet gelukt.'; }
  finally { busy.value = false; }
}
</script>
<template>
  <section class="admin-card joint-optimization" aria-labelledby="joint-optimize-title">
    <h2 id="joint-optimize-title">Gezamenlijke personeelsinzet optimaliseren</h2>
    <p>Vul eerst alle verplichte docentplekken en probeer daarna met minder medewerkers te plannen. Vervolgens verminderen we betaalde tussenuren en verdelen we het werk. De geselecteerde medewerkers van beide scholen vormen samen het team; koken telt mee. Bij vier modules hoort KE GeoFort-begeleiding te hebben: één bevoegde begeleider mag maximaal twee groepen in dezelfde KE-sessie begeleiden. KE met schoolbegeleiding blijft alleen als concept mogelijk en blokkeert definitief maken; Excel exporteren blijft mogelijk. Vleermuizen en KE bij vijf modules kunnen naar schoolbegeleiding. Je ziet elke wijziging vóór toepassen. Lessen en tijden blijven behouden; bestaande schoolbegeleiding blijft staan, behalve wanneer viermodule-KE GeoFort-begeleiding nodig heeft.</p>
    <p v-if="!ready">Beschikbaar zodra beide gekoppelde scholen een les- en docentenrooster in concept hebben.</p>
    <AdminButton variant="secondary" :disabled="!ready || busy" :loading="busy" @click="run(false)">{{ busy ? 'Personeelsinzet vergelijken…' : 'Optimalisatievoorstel maken' }}</AdminButton>
    <p v-if="error" class="admin-inline-error" role="alert">{{ error }}</p>
    <p v-if="success" class="admin-inline-notice" role="status">{{ success }}</p>
    <div v-if="proposal" class="joint-optimization__result" aria-live="polite">
      <h3>{{ proposal.improved ? 'Voorstel voor beide scholen' : 'Geen beter voorstel gevonden' }}</h3>
      <p v-if="!proposal.improved">Binnen de onderzochte mogelijkheden is geen betere verdeling gevonden. Je huidige rooster blijft behouden.</p>
      <p v-if="proposal.searchLimited">De zoeklimiet is bereikt. Dit is het beste gevonden voorstel; een betere verdeling is mogelijk.</p>
      <div class="joint-optimization__metrics">
        <p v-if="proposal.before.keSchoolAssignments || proposal.after.keSchoolAssignments"><strong>KE zonder GeoFort · vier modules</strong><span>{{ proposal.before.keSchoolAssignments }} → {{ proposal.after.keSchoolAssignments }}</span></p>
        <p><strong>Open verplichte plekken</strong><span>{{ proposal.before.unfilledAssignments }} → {{ proposal.after.unfilledAssignments }}</span></p>
        <p><strong>Tussenuren betaald</strong><span>{{ proposal.before.paidGapMinutes }} → {{ proposal.after.paidGapMinutes }} minuten</span></p>
        <p><strong>Tussenuren vrijwilligers</strong><span>{{ proposal.before.volunteerGapMinutes }} → {{ proposal.after.volunteerGapMinutes }} minuten</span></p>
        <p><strong>Begeleiderswissels ochtend</strong><span>{{ proposal.before.morningGroupChanges }} → {{ proposal.after.morningGroupChanges }}</span></p>
        <p><strong>Verschil werkduur begeleiders</strong><span>{{ proposal.before.workSpreadMinutes }} → {{ proposal.after.workSpreadMinutes }} minuten</span></p>
        <p><strong>Medewerkers inclusief kok</strong><span>{{ proposal.before.people }} → {{ proposal.after.people }}</span></p>
      </div>
      <p>Pauzes tellen niet als tussenuren. Een vrije ochtend of eerder klaar zijn telt ook niet als een tussenuur.</p>
      <div v-if="people.length" class="joint-optimization__table">
        <table><caption>Inzet voor en na optimalisatie (minuten)</caption><thead><tr><th>Medewerker</th><th>Werk</th><th>Waarvan koken</th><th>Tussenuren</th></tr></thead>
          <tbody><tr v-for="p in people" :key="p.staffId"><th>{{ p.name }}</th><td>{{ p.workMinutes }} → {{ p.after.workMinutes }}</td><td>{{ p.cookMinutes }} → {{ p.after.cookMinutes }}</td><td>{{ p.gapMinutes }} → {{ p.after.gapMinutes }}</td></tr></tbody>
        </table>
      </div>
      <details v-if="proposal.changes.length" open><summary>{{ proposal.changes.length }} wijzigingen bekijken</summary>
        <ul><li v-for="(change,i) in proposal.changes" :key="i">{{ change.startTime }}–{{ change.endTime }} · [School {{ change.school }}] · {{ change.module.replaceAll('-', ' ') }} · {{ change.role }}: <strong>{{ change.from }} → {{ change.to }}</strong></li></ul>
      </details>
      <div v-if="proposal.finalizationIssues.length" class="admin-inline-notice" role="alert">
        <strong>Alleen als concept · definitief maken geblokkeerd</strong>
        <p>Bij vier modules vereist Klimaat Experience GeoFort-begeleiding. Je kunt dit voorstel als concept toepassen en naar Excel exporteren.</p>
        <ul><li v-for="(issue,i) in proposal.finalizationIssues" :key="i">School {{ issue.school }} · {{ issue.startTime }}–{{ issue.endTime }} · KE met schoolbegeleiding.</li></ul>
      </div>
      <AdminButton variant="secondary" :disabled="busy" @click="proposal = null">Huidige rooster behouden</AdminButton>
      <p v-if="proposal.after.unfilledAssignments > 0" class="admin-inline-notice">Er zijn nog verplichte docentplekken open. Pas de selectie of lesindeling aan voordat je dit voorstel toepast.</p>
      <AdminButton v-if="proposal.improved" :disabled="busy || proposal.after.unfilledAssignments > 0" @click="run(true)">{{ proposal.finalizationIssues.length ? 'Optimalisatie als concept toepassen' : 'Optimalisatie toepassen op beide scholen' }}</AdminButton>
    </div>
  </section>
</template>
<style scoped>
.joint-optimization { display:grid; gap:1rem; margin-bottom:1rem; }
.joint-optimization__result { display:grid; gap:1rem; }
.joint-optimization__metrics { display:grid; grid-template-columns:repeat(auto-fit,minmax(12rem,1fr)); gap:1rem; }
.joint-optimization__metrics p { display:grid; gap:.5rem; background:var(--admin-surface-soft); padding:1rem; border-radius:.5rem; }
.joint-optimization__table { overflow:auto; }
table { border-collapse:collapse; width:100%; } th,td { text-align:left; padding:.65rem; border-bottom:1px solid var(--admin-border); }
caption { text-align:left; padding-bottom:.75rem; } li { margin:.5rem 0; }
</style>

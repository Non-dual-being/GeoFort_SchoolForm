<script setup lang="ts">
import { computed, inject, onBeforeUnmount, ref, watch } from 'vue';
import { getPlannerRosterAttachment, preparePlannerRoster, downloadPlannerRoster } from '../../services/dashboardRosterSchoolApi';
import AdminButton from '../form/AdminButton.vue';
import { adminBootstrapKey } from '../../types/admin';
import type { DashboardRosterPlan } from '../../types/roster';
import { jointRosterLifecycle, type JointControl } from '../../services/dashboardRosterLifecycleJointApi';
const props = defineProps<{ plans: DashboardRosterPlan[] }>();
const emit = defineEmits<{ changed: [] }>();
const bootstrap = inject(adminBootstrapKey)!;
const documentBusy = ref<number | null>(null);
const busy = ref(false);
const error = ref('');
const success = ref('');
const control = ref<JointControl | null>(null);
const accepted = ref(false);
const reopenAccepted = ref(false);
let successTimer: ReturnType<typeof setTimeout> | undefined;
const version = computed(() => props.plans.map(p => `${p.id}:${p.revision}:${p.planning.catalogRevision}:${p.jointPlanning?.id}`).join(','));
const linked = computed(() => props.plans.length === 2 && props.plans.every(p => p.jointPlanning && p.jointPlanning.id === props.plans[0]?.jointPlanning?.id));
const published = computed(() => linked.value && props.plans.every(p => p.status === 'published'));
const ready = computed(() => linked.value && props.plans.every(p => p.status === 'concept' && p.planning.sessions.length > 0));
watch(version, () => { control.value = null; accepted.value = false; reopenAccepted.value = false; });
async function run(mode: 'preview' | 'finalize' | 'reopen') {
  if (busy.value || !linked.value) return;
  if (mode === 'finalize' && (!control.value?.canFinalize || (control.value.warnings.length > 0 && !accepted.value))) return;
  if (mode === 'reopen' && !reopenAccepted.value) return;
  const requested = version.value;
  busy.value = true; error.value = ''; success.value = '';
  try {
    const result = await jointRosterLifecycle(props.plans, bootstrap.rosterJointLifecycleCsrfToken!, mode, control.value?.signature, accepted.value);
    if (mode === 'preview') {
      if (requested === version.value) { control.value = result; accepted.value = false; }
    } else {
      control.value = null; accepted.value = false; reopenAccepted.value = false;
      success.value = mode === 'finalize' ? 'Beide roosters zijn samen definitief opgeslagen.' : 'Beide roosters zijn heropend als concept. De definitieve historie blijft bewaard.';
      clearTimeout(successTimer); successTimer = setTimeout(() => { success.value = ''; }, 8000);
      emit('changed');
    }
  } catch (e) {
    control.value = null; accepted.value = false;
    error.value = e instanceof Error ? e.message : 'De gezamenlijke roosteractie is niet gelukt.';
    emit('changed');
  } finally { busy.value = false; }
}
async function schoolPdf(plan: DashboardRosterPlan) {
  if (!plan.bookingId || documentBusy.value !== null || busy.value) return;
  documentBusy.value = plan.id; error.value = '';
  try {
    const document = await getPlannerRosterAttachment(plan.bookingId);
    if (!document) throw new Error('Het definitieve schoolrooster is niet meer beschikbaar. Controleer beide roosters opnieuw.');
    await preparePlannerRoster(plan.bookingId, document.snapshotId, bootstrap.rosterDocumentCsrfToken!);
    await downloadPlannerRoster(plan.bookingId, document.snapshotId);
  } catch (e) { error.value = e instanceof Error ? e.message : 'Schoolrooster downloaden is niet gelukt.'; }
  finally { documentBusy.value = null; }
}
onBeforeUnmount(() => clearTimeout(successTimer));
</script>
<template>
  <section class="admin-card joint-finalization" aria-label="Gezamenlijk finaliseren">
    <h2>{{ published ? 'Beide roosters zijn definitief' : 'Gezamenlijk finaliseren' }}</h2>
    <p v-if="!linked">Koppel eerst twee schoolroosters.</p>
    <template v-else-if="published">
      <p>De definitieve les- en docentenroosters zijn vastgelegd. Heropen beide scholen samen om ze weer te bewerken.</p>
      <p>Elke school krijgt haar eigen lesrooster uit deze definitieve planning. Controleer hieronder beide PDF’s. De definitieve mail verstuur je per aanvraag; daarbij staat dit schoolrooster alvast geselecteerd.</p>
      <div v-for="(plan, index) in plans" :key="plan.id">
        <AdminButton v-if="plan.bookingId" variant="secondary" :loading="documentBusy === plan.id" :disabled="busy || documentBusy !== null" @click="schoolPdf(plan)">School {{ index + 1 }} · lesrooster downloaden (PDF)</AdminButton>
        <p v-else>School {{ index + 1 }} heeft geen gekoppelde aanvraag voor een schoolmail.</p>
      </div>
      <label><input v-model="reopenAccepted" type="checkbox" :disabled="busy"> Ik wil beide roosters terugzetten naar concept.</label>
      <AdminButton variant="secondary" :disabled="busy || documentBusy !== null || !reopenAccepted" @click="run('reopen')">Beide roosters heropenen</AdminButton>
    </template>
    <template v-else>
      <p>Controleer de opgeslagen lessen en personeelsinzet. Bij finaliseren worden beide scholen samen definitief; bij een fout blijft alles concept.</p>
      <p v-if="!ready">Beschikbaar zodra beide gekoppelde scholen een opgeslagen lesrooster in concept hebben.</p>
      <AdminButton variant="secondary" :disabled="busy || !ready" :loading="busy" @click="run('preview')">Gezamenlijke eindcontrole</AdminButton>
      <div v-if="control" aria-live="polite">
        <p v-if="control.canFinalize" class="admin-inline-notice">✓ De opgeslagen roosters voldoen aan de harde regels.</p>
        <div v-if="control.errors.length" class="admin-inline-error"><strong>Eerst oplossen</strong><ul><li v-for="(issue,i) in control.errors" :key="i">{{ issue.message }}</li></ul></div>
        <div v-if="control.warnings.length" class="joint-finalization__warnings"><strong>Aandachtspunten</strong><ul><li v-for="(issue,i) in control.warnings" :key="i">{{ issue.message }}</li></ul>
          <label><input v-model="accepted" type="checkbox" :disabled="busy"> Ik heb deze waarschuwingen beoordeeld en accepteer ze voor beide roosters.</label>
        </div>
        <AdminButton :disabled="busy || !control.canFinalize || (control.warnings.length > 0 && !accepted)" @click="run('finalize')">Beide roosters definitief maken</AdminButton>
      </div>
    </template>
    <p v-if="error" class="admin-inline-error" role="alert">{{ error }}</p>
    <p v-if="success" class="admin-inline-notice" role="status">{{ success }}</p>
  </section>
</template>
<style scoped>
.joint-finalization { display:grid; gap:1rem; }
.joint-finalization label { display:flex; gap:.6rem; align-items:flex-start; }
.joint-finalization__warnings { background:#fff8e7; color:#765400; border-left:4px solid #d99a16; padding:1rem; margin:1rem 0; }
.joint-finalization li { margin:.45rem 0; }
</style>

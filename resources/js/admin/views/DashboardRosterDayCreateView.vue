<script setup lang="ts">
import { jointLessonsAvailable, rosterStaffingState } from "../services/rosterStaffingState";
import { computed, onBeforeUnmount, ref, watch } from "vue";
import { RouterLink, useRoute, useRouter } from "vue-router";
import RosterJointFinalization from '../components/rosters/RosterJointFinalization.vue';
import RosterJointOverview from '../components/rosters/RosterJointOverview.vue';
import AdminButton from "../components/form/AdminButton.vue";
import DashboardRosterDetailView from "./DashboardRosterDetailView.vue";
import { formatRosterDate } from "../services/rosterDate";
import DashboardRosterCreateView from "./DashboardRosterCreateView.vue";
import RosterPairControls from "../components/rosters/RosterPairControls.vue";
import RosterDayBookingPicker from "../components/rosters/RosterDayBookingPicker.vue";
import {
  fetchDashboardRoster,
  previewDashboardRosterDay,
} from "../services/dashboardRosterApi";
import type {
  DashboardRosterPlan,
  RosterDayPreview,
} from "../types/roster";

const route = useRoute();
const router = useRouter();
const plans = ref<DashboardRosterPlan[]>([]);
const loading = ref(false);
const checking = ref(false);
const error = ref<string | null>(null);
const preview = ref<RosterDayPreview | null>(null);
const invalidSelection = ref(false);
let requestVersion = 0;
let controller: AbortController | undefined;

function positiveId(value: unknown): number | null {
  return typeof value === "string" && /^[1-9]\d*$/.test(value)
    && Number.isSafeInteger(Number(value)) ? Number(value) : null;
}

const schoolCount = computed(() => route.query.schools === "2" ? 2 : route.query.schools === "1" ? 1 : null);
const ids = computed(() => [positiveId(route.query.school1), positiveId(route.query.school2)]
  .filter((id): id is number => id !== null));
const legacyEdit = computed(() => positiveId(route.query.plan) !== null);
const firstSchool = computed(() => plans.value[0]);
const linkedPair = computed(() => firstSchool.value?.jointPlanning);
const jointOverview = computed(() => !!linkedPair.value && route.query.joint === '1');
const jointReady = computed(() => jointLessonsAvailable(plans.value));
const jointPending = computed(() => plans.value.filter(p => !p.planning.sessions.length).map(p => p.school.name));
function openJoint() { if (!jointReady.value || checking.value) return; const { wizard: _wizard, ...query } = route.query; void router.replace({ query: { ...query, joint: '1' } }); }
const editingPlan = computed(() => linkedPair.value ? plans.value.find(p => p.id === positiveId(route.query.wizard)) : undefined);
function startWizard(id: number) { const { joint: _joint, ...query } = route.query; void router.replace({ query: { ...query, wizard: String(id) } }); }
function closeWizard() { const { wizard: _wizard, ...query } = route.query; void router.replace({ query }); }
const awaitingLink = computed(() => schoolCount.value === 2 && plans.value.length === 2 && !linkedPair.value);
const activeSchool = computed(() => plans.value.length + 1);
const pairReady = computed(() => schoolCount.value === 2 && plans.value.length === 2 && !!linkedPair.value);
const missingLessonPlans = computed(() => plans.value.filter((p) => p.planning.sessions.length === 0));
const secondViStartsLater = computed(() => {
  const proposal = preview.value?.lessons.schools.find(s => s.planId === plans.value[1]?.id && s.isProposal);
  const sessions = proposal?.sessions ?? plans.value[1]?.planning.sessions ?? [];
  const vi = sessions.filter(s => s.moduleKey === "Voedsel-Innovatie").map(s => s.startTime).sort()[0];
  const start = sessions.map(s => s.startTime).sort()[0];
  return !!vi && !!start && vi > start;
});
const draftId = computed(() => positiveId(activeSchool.value === 1 ? route.query.draft1 : route.query.draft2));
const savedIssues = computed(() => preview.value ? [
  ...preview.value.savedControl.day.errors,
  ...Object.values(preview.value.savedControl.plans).filter((p) => plans.value.some((plan) => plan.id === p.planId && plan.planning.sessions.length > 0)).flatMap((p) => p.errors.map((issue) => ({
    ...issue, message: `${plans.value.find((school) => school.id === p.planId)?.school.name ?? `School #${p.planId}`}: ${issue.message}`,
  }))),
] : []);
const savedWarnings = computed(() => preview.value ? [
  ...preview.value.savedControl.day.warnings,
  ...Object.values(preview.value.savedControl.plans).flatMap((p) => p.warnings),
] : []);

async function selectCount(count: 1 | 2): Promise<void> {
  await router.replace({ name: "roster-create", query: { schools: String(count) } });
}

async function rememberDraft(plan: DashboardRosterPlan): Promise<void> {
  await router.replace({ query: { ...route.query, [activeSchool.value === 1 ? "draft1" : "draft2"]: String(plan.id) } });
}

async function completeSchool(plan: DashboardRosterPlan): Promise<void> {
  error.value = null;
  if (schoolCount.value === 1) {
    await router.push({ name: "roster-detail", params: { id: plan.id } });
    return;
  }
  if (plan.jointPlanning) {
    await router.replace({ name: "roster-create", query: { schools: "2", school1: String(plan.jointPlanning.planIds[0]), school2: String(plan.jointPlanning.planIds[1]) } });
    return;
  }
  if (plans.value.some((p) => p.id === plan.id)
    || (firstSchool.value && firstSchool.value.visitDate !== plan.visitDate)) {
    error.value = "Kies een ander schoolrooster op dezelfde bezoekdatum.";
    return;
  }
  const next = [...plans.value, plan];
  await router.replace({ name: "roster-create", query: {
    schools: "2", school1: String(next[0]!.id),
    ...(next[1] ? { school2: String(next[1].id) } : {}),
  } });
}

async function checkDay(): Promise<void> {
  const version = ++requestVersion;
  controller?.abort();
  const currentController = new AbortController();
  controller = currentController;
  checking.value = true;
  error.value = null;
  preview.value = null;
  try {
    const current = await Promise.all(ids.value.map((id) => fetchDashboardRoster(id, currentController.signal)));
    if (version !== requestVersion) return;
    plans.value = current.map((r) => r.plan);
    const first = plans.value[0];
    if (!first || (schoolCount.value === 2 && !first.jointPlanning)) return;
    if (plans.value.length === 2) {
      const result = await previewDashboardRosterDay(plans.value, currentController.signal);
      if (version === requestVersion) preview.value = result;
    }
  } catch (e) {
    if (version === requestVersion && !currentController.signal.aborted) {
      error.value = e instanceof Error ? e.message : "De gezamenlijke controle is niet gelukt.";
    }
  } finally {
    if (version === requestVersion) checking.value = false;
  }
}

watch(() => `${route.query.schools ?? ""}:${route.query.school1 ?? ""}:${route.query.school2 ?? ""}`, async () => {
  const version = ++requestVersion;
  controller?.abort();
  controller = new AbortController();
  const signal = controller.signal;
  loading.value = true;
  checking.value = false;
  error.value = null;
  preview.value = null;
  plans.value = [];
  invalidSelection.value = false;
  try {
    for (const key of ["school1", "school2"] as const) {
      if (route.query[key] !== undefined && positiveId(route.query[key]) === null) throw new Error("Het schoolrooster in deze link is ongeldig.");
    }
    if (route.query.school2 && !route.query.school1) throw new Error("Kies eerst school 1.");
    const current = await Promise.all(ids.value.map((id) => fetchDashboardRoster(id, signal)));
    if (version !== requestVersion) return;
    const next = current.map((r) => r.plan);
    if (next.length === 2 && (next[0]!.id === next[1]!.id || next[0]!.visitDate !== next[1]!.visitDate)) {
      throw new Error("Kies twee verschillende schoolroosters op dezelfde bezoekdatum.");
    }
    if (next.some(p => p.status === "archived")) throw new Error("Deze gezamenlijke planning is opgeheven. Kies de aanvragen opnieuw voor een nieuwe planning.");
    const linked = next.find(p => p.jointPlanning)?.jointPlanning;
    if (linked && linked.planIds.join(",") !== next.map(p => p.id).join(",")) {
      await router.replace({ name: "roster-create", query: { schools: "2", school1: String(linked.planIds[0]), school2: String(linked.planIds[1]) } });
      return;
    }
    plans.value = next;
    loading.value = false;
    if (next.length > 0) await checkDay();
  } catch (e) {
    if (!signal.aborted) {
      invalidSelection.value = true;
      error.value = e instanceof Error ? e.message : "De scholen konden niet worden geladen.";
    }
  } finally {
    if (!signal.aborted) loading.value = false;
  }
}, { immediate: true });

onBeforeUnmount(() => { requestVersion++; controller?.abort(); });
</script>

<template>
  <DashboardRosterCreateView v-if="legacyEdit" />
  <section v-else class="admin-roster-day-create" aria-labelledby="day-create-title">
    <RouterLink class="admin-booking-detail__back" :to="{ name: 'rosters' }">&larr; Terug naar roosters</RouterLink>
    <header>
      <p class="admin-eyebrow">Onderwijsplanning</p>
      <h1 id="day-create-title">{{ jointOverview ? "Twee scholen · gezamenlijk overzicht" : editingPlan ? "Twee scholen · wizard" : awaitingLink ? "Twee scholen koppelen" : pairReady ? "Twee scholen · gezamenlijke controle" : "Roosters plannen" }}</h1>
      <p v-if="schoolCount === null">Hoeveel scholen wil je op deze dag plannen?</p>
      <p v-else-if="schoolCount === 2 && !pairReady && !awaitingLink && !editingPlan">School {{ activeSchool }} van 2. Beide scholen houden hun eigen programma, groepen en rooster.</p>
    </header>

    <p v-if="error" class="admin-inline-error" role="alert">{{ error }}</p>
    <div v-if="schoolCount === null" class="admin-roster-day-create__choices">
      <article class="admin-card">
        <h2>Eén school</h2><p>Plan één school met de bestaande roosterwizard.</p>
        <AdminButton @click="selectCount(1)">Eén school plannen</AdminButton>
      </article>
      <article class="admin-card">
        <h2>Twee scholen</h2><p>Vul school 1 en school 2 in en controleer hun gezamenlijke lescapaciteit en docentindeling.</p>
        <AdminButton @click="selectCount(2)">Twee scholen plannen</AdminButton>
      </article>
    </div>

    <RosterPairControls v-if="schoolCount === 2 && plans.length === 2 && !invalidSelection" :plans="plans" @changed="checkDay" />
    <section v-if="linkedPair && firstSchool" class="admin-roster-day-create__group" aria-labelledby="joint-visit-date">
      <header class="admin-roster-day-create__date">
        <p class="admin-eyebrow">Gezamenlijke bezoekdag · gekoppelde roosters</p>
        <h2 id="joint-visit-date"><time :datetime="firstSchool.visitDate">{{ formatRosterDate(firstSchool.visitDate) }}</time></h2>
      </header>
      <p>Begin met de school van jouw keuze. Het volgende lesvoorstel houdt rekening met de opgeslagen indeling van de andere school.</p>
      <div class="admin-roster-day-create__choices">
        <article v-for="(plan, index) in plans" :key="plan.id" class="admin-card">
          <p class="admin-eyebrow">School {{ index + 1 }}</p>
          <h2>{{ plan.school.name }}</h2>
          <p>{{ plan.education.programLabel }} · {{ plan.groups.length }} groepen</p>
          <p>{{ rosterStaffingState(plan.planning.sessions, plan.planning.staffingSettings).label }}</p>
          <AdminButton :aria-label="`Wizard starten voor school ${index + 1}: ${plan.school.name}`" @click="startWizard(plan.id)">Wizard starten</AdminButton>
        </article>
      </div>
      <p class="check-tag" role="status">{{ jointReady ? "✓ Overzicht beschikbaar" : plans.some(p => p.planning.sessions.length) ? "◷ Planning in uitvoering" : "○ Overzicht nog gesloten" }}</p>
      <AdminButton v-if="!jointOverview" :disabled="!jointReady || checking" @click="openJoint">Gezamenlijk overzicht openen</AdminButton>
      <p v-if="!jointReady">Sla eerst de lesindeling op voor {{ jointPending.join(' en ') }}. Daarna kun je beide scholen samen controleren.</p>
    </section>
    <p v-if="loading" role="status">Schoolroosters laden…</p>
    <AdminButton v-if="invalidSelection" variant="secondary" @click="selectCount(2)">Scholen opnieuw kiezen</AdminButton>
    <template v-else-if="jointOverview">
      <RosterJointOverview v-if="jointReady" :plans="plans" :preview="preview" :checking="checking" :start-optimization="route.query.optimize === '1'" @edit="startWizard" @refresh="checkDay" />
      <p v-else-if="!loading" class="admin-inline-notice admin-inline-notice--plain">Het gezamenlijke overzicht wordt beschikbaar zodra beide scholen een opgeslagen lesindeling hebben. Docenten kun je daarna indelen.</p>
    </template>
    <section v-else-if="editingPlan" class="admin-roster-day-create__workspace" aria-label="Wizard gekoppelde scholen">
      <AdminButton variant="secondary" @click="closeWizard">Terug naar gezamenlijke controle</AdminButton>
      <p class="admin-inline-notice admin-inline-notice--plain">Wizard · {{ editingPlan.school.name }}. Deze school blijft onderdeel van de gezamenlijke planning hierboven.</p>
      <div v-if="preview && preview.savedControl.day.errors.length" class="admin-inline-error" role="alert">
        <strong>Gezamenlijke controle</strong>
        <ul><li v-for="(issue, i) in preview.savedControl.day.errors" :key="i">{{ issue.message }}</li></ul>
      </div>
      <DashboardRosterDetailView :key="editingPlan.id" :embedded-plan-id="editingPlan.id" @changed="checkDay" />
    </section>
    <template v-else-if="awaitingLink"></template>
    <template v-else-if="schoolCount !== null && !pairReady">
      <aside v-if="firstSchool" class="admin-inline-notice admin-inline-notice--plain">
        <strong>School 1 · {{ firstSchool.school.name }}</strong>
        <p>Bezoekdatum: {{ formatRosterDate(firstSchool.visitDate) }}. School 2 wordt op dezelfde dag gepland.</p>
      </aside>
      <RosterDayBookingPicker
        v-if="schoolCount === 2 && !draftId"
        :key="activeSchool"
        :school-number="activeSchool"
        :fixed-visit-date="firstSchool?.visitDate"
        :excluded-booking-id="firstSchool?.bookingId ?? undefined"
        @selected="completeSchool"
      />
      <DashboardRosterCreateView
        v-else
        :key="activeSchool"
        embedded
        :resume-plan-id="draftId"
        :fixed-visit-date="firstSchool?.visitDate"
        :excluded-booking-id="firstSchool?.bookingId ?? undefined"
        :school-number="activeSchool"
        :two-school-days-only="schoolCount === 2"
        @progress="rememberDraft"
        @complete="completeSchool"
      />
    </template>

    <section v-else-if="pairReady" class="joint-checks" aria-label="Gezamenlijke controle en afronding">
      <header class="admin-roster-day-create__control-bar">
        <div><h2>Gezamenlijke controle</h2><p>Lessen en personeelsinzet van beide scholen.</p></div>
        <AdminButton variant="secondary" :disabled="checking" @click="checkDay">{{ checking ? "Gezamenlijk controleren…" : "Beide roosters opnieuw controleren" }}</AdminButton>
      </header>
      <p v-if="checking" role="status">Lescapaciteit en opgeslagen docentindelingen controleren…</p>
      <div v-if="preview && !checking" class="joint-checks__grid">
        <section class="joint-checks__item" aria-labelledby="day-lessons-title">
          <span class="check-tag" :class="preview.lessons.status === 'candidate_found' ? 'check-tag--ok' : 'check-tag--error'">{{ preview.lessons.status === 'candidate_found' ? '✓ Capaciteit akkoord' : '! Aanpassing nodig' }}</span>
          <h2 id="day-lessons-title">1. Passen de lesroosters samen?</h2>
          <div v-if="preview.lessons.status === 'candidate_found'" class="joint-checks__success">
            <strong>Een gezamenlijke lesindeling past binnen de modulecapaciteit.</strong>
            <p>Dit controleert de lesprogramma’s. Docenten en finalisatie worden apart gecontroleerd.</p>
          </div>
          <div v-else class="joint-checks__error" role="alert">
            <strong>{{ preview.lessons.status === 'saved_lessons_invalid' ? "De lesindeling heeft nog fouten." : "Geen passende gezamenlijke lesindeling gevonden." }}</strong>
            <p v-if="preview.lessons.status === 'no_candidate_found'">Binnen de onderzochte voorstellen is geen passende combinatie gevonden. Pas groepen, programma of tijden aan en controleer opnieuw. Dit bewijst nog niet dat elke indeling onmogelijk is.</p>
          </div>
          <ul v-if="preview.lessons.errors.length || preview.lessons.conflicts.length">
            <li v-for="(issue, index) in [...preview.lessons.errors, ...preview.lessons.conflicts]" :key="index">{{ issue.message }}</li>
          </ul>
          <details v-for="school in preview.lessons.schools.filter((s) => s.isProposal)" :key="school.planId">
            <summary>Voorbeeld lesindeling · {{ school.schoolName }}</summary>
            <p>Dit is een controlevoorstel en is nog niet opgeslagen. Bestaande lesindelingen blijven behouden.</p>
            <div class="admin-roster-day-create__table">
              <table><thead><tr><th scope="col">Tijd</th><th scope="col">Module</th><th scope="col">Groepen</th></tr></thead>
                <tbody><tr v-for="(session, index) in school.sessions" :key="index"><td>{{ session.startTime }}–{{ session.endTime }}</td><td>{{ session.moduleLabel }}</td><td>{{ session.groupLabels.join(', ') }}</td></tr></tbody>
              </table>
            </div>
          </details>
          <p v-if="secondViStartsLater" class="joint-checks__warning">Waarschuwing: Voedsel Innovatie begint bij school 2 later om ruimte voor school 1 te houden. Dit is een uitzondering op de voorkeur voor een vroege start; de maximale capaciteit blijft gelden.</p>
        </section>
        <section class="joint-checks__item" aria-labelledby="day-staff-title">
          <span class="check-tag" :class="missingLessonPlans.length ? '' : preview.savedControl.canFinalize ? 'check-tag--ok' : 'check-tag--error'">{{ missingLessonPlans.length ? '○ Nog niet gereed' : preview.savedControl.canFinalize ? '✓ Harde regels akkoord' : '! Aanpassing nodig' }}</span>
          <h2 id="day-staff-title">2. Opgeslagen lessen en docenten controleren</h2>
          <p v-if="missingLessonPlans.length">Maak eerst de lesindeling van {{ missingLessonPlans.map(p => p.school.name).join(' en ') }}. Daarna controleren we de volledigheid en de gezamenlijke docentindeling.</p>
          <p v-else-if="preview.savedControl.canFinalize" class="joint-checks__success">De huidige roosters voldoen samen aan de gecontroleerde harde regels.</p>
          <p v-else>De concepten blijven bewerkbaar. Los de onderstaande fouten op voordat je definitief plant.</p>
          <ul v-if="!missingLessonPlans.length && savedIssues.length"><li v-for="(issue, index) in savedIssues" :key="index">{{ issue.message }}</li></ul>
          <ul v-if="savedWarnings.length" class="joint-checks__warning"><li v-for="(issue, index) in savedWarnings" :key="index">Waarschuwing: {{ issue.message }}</li></ul>
        </section>
      </div>
      <p v-else-if="!checking">Nog geen actuele controle beschikbaar. Controleer beide roosters opnieuw.</p>
      <RosterJointFinalization :plans="plans" @changed="checkDay" />
    </section>
  </section>
</template>

<style scoped>
.joint-checks { border:1px solid var(--admin-border); border-radius:.8rem; background:#fff; overflow:hidden; }
.joint-checks__grid { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); }
.joint-checks__item { padding:1.25rem; min-width:0; display:flex; flex-direction:column; gap:.75rem; }
.joint-checks__item + .joint-checks__item { border-left:1px solid var(--admin-border); }
.joint-checks__item p { margin:0; }
.check-tag { display:inline-flex; width:fit-content; padding:.3rem .6rem; border-radius:.35rem; background:#eef0f2; color:#4b5563; font-weight:600; font-size:.85rem; }
.check-tag--ok { background:#e7f4eb; color:#166534; }
.check-tag--error { background:#fce9e9; color:#991b1b; }
.joint-checks__success { background:#fff; color:#1b3264; padding:.75rem 1rem; border:1px solid #d6deed; border-left:3px solid #284878; border-radius:.25rem; }
.joint-checks__error { color:#991b1b; }
.joint-checks__warning { background:#fff8e7; color:#805600; padding:.75rem 1rem; border-left:3px solid #d59b20; border-radius:.25rem; }
ul.joint-checks__warning { padding-left:2rem; }
.joint-checks__footer { display:flex; align-items:center; gap:1rem; justify-content:space-between; padding:1rem 1.25rem; border-top:1px solid var(--admin-border); background:#fafafa; }
.joint-checks__footer p { margin:.3rem 0 0; color:var(--admin-text-muted); font-size:.9rem; }
.joint-checks .admin-roster-day-create__control-bar { border:0; border-radius:0; border-bottom:1px solid var(--admin-border); }
@media(max-width:760px) { .joint-checks__grid { grid-template-columns:1fr; } .joint-checks__item + .joint-checks__item { border-left:0; border-top:1px solid var(--admin-border); } .joint-checks__footer { align-items:start; flex-direction:column; } }

.admin-roster-day-create__control-bar { display:grid; grid-template-columns:minmax(0,1fr) auto; align-items:center; gap:1rem; padding:1rem 1.25rem; border:1px solid var(--admin-border); border-radius:.8rem; background:var(--admin-surface); }
.admin-roster-day-create__control-bar p { color:var(--admin-text-muted); }
@media(max-width:680px) { .admin-roster-day-create__control-bar { grid-template-columns:1fr; } .admin-roster-day-create__control-bar button { justify-self:start; } }
.admin-roster-day-create__group { display: grid; gap: 1rem; padding: 1rem; background: var(--admin-surface-soft); border: 1px solid var(--admin-border); border-radius: .8rem; }
.admin-roster-day-create__date { padding: .25rem; }
.admin-roster-day-create__workspace { display: grid; gap: 1rem; min-width: 0; }
.admin-roster-day-create { display: grid; gap: 1rem; }
.admin-roster-day-create .admin-card { padding: 1.25rem; }
.admin-roster-day-create h1 { font-size: 1.9rem; }
.admin-roster-day-create h2 { font-size: 1.25rem; margin: 0; }
.admin-roster-day-create header p { margin: .3rem 0 0; }
.admin-roster-day-create .admin-inline-notice { display: grid; gap: .5rem; }
.admin-roster-day-create .admin-inline-notice p { margin: 0; }
.admin-roster-day-create__choices { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 1rem; }
.admin-roster-day-create__choices { grid-template-rows: auto 1fr auto; }
.admin-roster-day-create__choices .admin-card { display: grid; grid-row: span 3; grid-template-rows: subgrid; gap: .65rem; margin: 0; }
.admin-roster-day-create__choices .admin-card p { margin: 0; }
.admin-roster-day-create__choices .admin-card .admin-eyebrow { grid-row: 1; }
.admin-roster-day-create__choices:has(.admin-eyebrow) { grid-template-rows: auto auto 1fr auto; }
.admin-roster-day-create__choices .admin-card:has(.admin-eyebrow) { grid-row: span 4; }
.admin-roster-day-create__actions { display: flex; flex-wrap: wrap; gap: .75rem; }
.admin-roster-day-create__existing { display: grid; gap: .75rem; padding: 0; list-style: none; }
.admin-roster-day-create__table { overflow-x: auto; }
.admin-roster-day-create table { width: 100%; border-collapse: collapse; }
.admin-roster-day-create th, .admin-roster-day-create td { padding: .65rem; text-align: left; border-bottom: 1px solid var(--admin-border); }
.admin-roster-day-create details { margin-top: 1rem; }
.admin-roster-day-create summary { cursor: pointer; font-weight: 600; }
@media (max-width: 680px) {
  .admin-roster-day-create__choices, .admin-roster-day-create__choices:has(.admin-eyebrow) { grid-template-columns: 1fr; grid-template-rows: none; }
  .admin-roster-day-create__choices .admin-card, .admin-roster-day-create__choices .admin-card:has(.admin-eyebrow) { grid-row: auto; grid-template-rows: auto 1fr auto; }
}
</style>

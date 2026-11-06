<script setup lang="ts">
import { rosterStaffingState } from "../services/rosterStaffingState";
import { computed, inject, onBeforeUnmount, ref, watch } from "vue";
import { RouterLink, useRoute, useRouter } from "vue-router";
import RosterJointWorkspace from '../components/rosters/RosterJointWorkspace.vue';
import { jointWorkspace, type JointLoad } from '../services/dashboardRosterWorkspaceApi';
import { adminBootstrapKey } from '../types/admin';
import AdminButton from "../components/form/AdminButton.vue";
import DashboardRosterDetailView from "./DashboardRosterDetailView.vue";
import { formatRosterDate } from "../services/rosterDate";
import DashboardRosterCreateView from "./DashboardRosterCreateView.vue";
import RosterPairControls from "../components/rosters/RosterPairControls.vue";
import RosterDayBookingPicker from "../components/rosters/RosterDayBookingPicker.vue";
import {
  fetchDashboardRoster,
} from "../services/dashboardRosterApi";
import type {
  DashboardRosterPlan,
} from "../types/roster";

const route = useRoute();
const router = useRouter();
const plans = ref<DashboardRosterPlan[]>([]);
const bootstrap = inject(adminBootstrapKey)!;
const joint = ref<JointLoad | null>(null);
const loading = ref(false);
const checking = ref(false);
const error = ref<string | null>(null);
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
const jointOverview = computed(() => !!linkedPair.value && route.query.joint === '1' && jointReady.value);
const jointReady = computed(() => joint.value?.readiness.canOpenJoint === true);
function openJoint() { if (!jointReady.value || checking.value) return; const { wizard: _wizard, ...query } = route.query; void router.replace({ query: { ...query, joint: '1' } }); }
const editingPlan = computed(() => linkedPair.value ? plans.value.find(p => p.id === positiveId(route.query.wizard)) : undefined);
function startWizard(id: number) { const { joint: _joint, ...query } = route.query; void router.replace({ query: { ...query, wizard: String(id) } }); }
function closeWizard() { const { wizard: _wizard, joint: _joint, optimize: _optimize, ...query } = route.query; void router.replace({ query }); }
const awaitingLink = computed(() => schoolCount.value === 2 && plans.value.length === 2 && !linkedPair.value);
const activeSchool = computed(() => plans.value.length + 1);
const pairReady = computed(() => schoolCount.value === 2 && plans.value.length === 2 && !!linkedPair.value);
const draftId = computed(() => positiveId(activeSchool.value === 1 ? route.query.draft1 : route.query.draft2));
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

async function checkDay(reloadPlans = true): Promise<void> {
  const version = ++requestVersion;
  controller?.abort();
  const currentController = new AbortController();
  controller = currentController;
  checking.value = true;
  error.value = null;
  try {
    if (reloadPlans) {
      const current = await Promise.all(ids.value.map((id) => fetchDashboardRoster(id, currentController.signal)));
      if (version !== requestVersion) return;
      plans.value = current.map((r) => r.plan);
    }
    const first = plans.value[0];
    if (!first || (schoolCount.value === 2 && !first.jointPlanning)) return;
    if (plans.value.length === 2) {
      const result = await jointWorkspace<JointLoad>(plans.value, bootstrap.rosterJointWorkspaceCsrfToken!, 'read', null, null, undefined, currentController.signal);
      if (version === requestVersion) joint.value = result;
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
  plans.value = [];
  joint.value = null;
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
    if (next.length > 0) await checkDay(false);
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
      <h1 id="day-create-title">{{ jointOverview ? "Gezamenlijk roosteren" : editingPlan ? editingPlan.school.name + " · individuele wizard" : awaitingLink ? "Twee scholen koppelen" : pairReady ? "Schoolbeheer" : "Roosters plannen" }}</h1>
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

    <details v-if="schoolCount === 2 && plans.length === 2 && !invalidSelection && !jointOverview && !editingPlan"><summary>{{ linkedPair ? "Koppeling beheren" : "Scholen koppelen met behoud van roosters" }}</summary><RosterPairControls :plans="plans" @changed="checkDay()" /></details>
    <section v-if="linkedPair && firstSchool && !editingPlan && !jointOverview" class="admin-roster-day-create__group" aria-labelledby="joint-visit-date">
      <header class="admin-roster-day-create__date">
        <p class="admin-eyebrow">Gezamenlijke bezoekdag · gekoppelde roosters</p>
        <h2 id="joint-visit-date"><time :datetime="firstSchool.visitDate">{{ formatRosterDate(firstSchool.visitDate) }}</time></h2>
      </header>
      <p>Maak eerst beide lesindelingen in de individuele wizards. Daarna kun je de personeelsinzet gezamenlijk indelen en verbeteren.</p>
      <div class="admin-roster-day-create__choices">
        <article v-for="(plan, index) in plans" :key="plan.id" class="admin-card">
          <p class="admin-eyebrow">School {{ index + 1 }}</p>
          <h2>{{ plan.school.name }}</h2>
          <p>{{ plan.education.programLabel }} · {{ plan.groups.length }} groepen</p>
          <p><strong>Lesindeling:</strong> {{ joint?.readiness.schools.find(s => s.id === plan.id)?.ready ? 'Compleet en opgeslagen' : 'Nog niet compleet' }}</p>
          <p><strong>Personeel:</strong> {{ rosterStaffingState(plan.planning.sessions, plan.planning.staffingSettings).label }}</p>
          <AdminButton :aria-label="`Wizard starten voor school ${index + 1}: ${plan.school.name}`" @click="startWizard(plan.id)">Wizard starten</AdminButton>
        </article>
      </div>
      <p class="check-tag" role="status">{{ jointReady ? "✓ Overzicht beschikbaar" : plans.some(p => p.planning.sessions.length) ? "◷ Planning in uitvoering" : "○ Overzicht nog gesloten" }}</p>
      <AdminButton v-if="!jointOverview" :disabled="!jointReady || checking" @click="openJoint">Gezamenlijk roosteren openen</AdminButton>
      <section v-if="joint" class="joint-readiness" aria-label="Checklist gezamenlijk roosteren"><h3>{{jointReady?'Klaar voor gezamenlijk roosteren':'Nog nodig voor gezamenlijk roosteren'}}</h3><div v-for="school in joint.readiness.schools" :key="school.id"><strong>{{school.ready?'✓':'○'}} {{plans.find(p=>p.id===school.id)?.school.name}}</strong><p v-if="school.ready">Volledige lesindeling opgeslagen. Docenten mogen nog ontbreken.</p><ul v-else><li v-for="(issue,i) in school.issues" :key="i">{{issue.message}} <button @click="startWizard(school.id)">Oplossen in schoolwizard</button></li></ul></div></section>
    </section>
    <p v-if="loading" role="status">Schoolroosters laden…</p>
    <AdminButton v-if="invalidSelection" variant="secondary" @click="selectCount(2)">Scholen opnieuw kiezen</AdminButton>
    <template v-else-if="jointOverview">
      <RosterJointWorkspace v-if="jointReady && joint" :key="plans.map(p=>`${p.id}:${p.revision}`).join(',')" :plans="plans" :loaded="joint" :initial-school-id="positiveId(route.query.focus) ?? undefined" :start-optimization="route.query.optimize === '1'" @back="closeWizard" @refresh="checkDay()" />
      <p v-else-if="!loading" class="admin-inline-notice admin-inline-notice--plain">Het gezamenlijke overzicht wordt beschikbaar zodra beide scholen een opgeslagen lesindeling hebben. Docenten kun je daarna indelen.</p>
    </template>
    <section v-else-if="editingPlan" class="admin-roster-day-create__workspace" aria-label="Wizard gekoppelde scholen">
      <AdminButton variant="secondary" @click="closeWizard">Terug naar schoolbeheer</AdminButton>
      <p class="admin-inline-notice admin-inline-notice--plain">Wizard · {{ editingPlan.school.name }}. De andere gekoppelde school blijft bij de controles meetellen.</p>
      <DashboardRosterDetailView :key="editingPlan.id" :embedded-plan-id="editingPlan.id" @changed="checkDay()" />
    </section>
    <template v-else-if="awaitingLink"></template>
    <template v-else-if="schoolCount !== null && !pairReady && !loading">
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

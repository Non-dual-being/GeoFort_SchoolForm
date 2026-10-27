<script setup lang="ts">
import { computed, inject, nextTick, onBeforeUnmount, ref, watch } from "vue";
import { RouterLink, useRoute, useRouter } from "vue-router";

import { scrollToRosterAction } from "../services/scrollToRosterAction";
import AdminButton from "../components/form/AdminButton.vue";
import DashboardRosterCreateView from "./DashboardRosterCreateView.vue";
import RosterAutoGeneratorPanel from "../components/rosters/RosterAutoGeneratorPanel.vue";
import RosterDaySchedule from "../components/rosters/RosterDaySchedule.vue";
import RosterManualGrid from "../components/rosters/RosterManualGrid.vue";
import RosterScheduleMatrix from "../components/rosters/RosterScheduleMatrix.vue";
import RosterStaffingDemand from "../components/rosters/RosterStaffingDemand.vue";
import RosterTeacherSchedule from "../components/rosters/RosterTeacherSchedule.vue";
import RosterTotalOverview from "../components/rosters/RosterTotalOverview.vue";
import RosterValidationSummary from "../components/rosters/RosterValidationSummary.vue";
import RosterStaffIssues from "../components/rosters/RosterStaffIssues.vue";
import RosterResetSessionsDialog from "../components/rosters/RosterResetSessionsDialog.vue";
import RosterBookingReview from "../components/rosters/RosterBookingReview.vue";
import { rosterStaffingState } from "../services/rosterStaffingState";
import { rosterStaffIssues } from "../services/rosterStaffIssues";
import {
  convertDashboardRosterToCustom,
  fetchDashboardRoster,
  validateDashboardRoster,
  changeDashboardRosterLifecycle,
  RosterLifecycleApiError,
  resetRosterSessions,
} from "../services/dashboardRosterApi";
import type { DashboardRosterPlan, RosterValidationResult } from "../types/roster";
import { downloadDashboardRosterXlsx } from "../services/dashboardRosterExportApi";
import { adminBootstrapKey } from "../types/admin";
import { ApiError } from "../../services/http/apiClient";

type WorkspaceMode = "view" | "manage";
type ViewTab = "total" | "day" | "staffing" | "control";
type ManageTab = "wizard" | "manual";

const props = defineProps<{ embeddedPlanId?: number; manualOnly?: boolean }>();
const emit = defineEmits<{ changed: [] }>();
const editingProgram = ref(false);
async function programUpdated() { editingProgram.value = false; await load(); }
const route = useRoute();
const router = useRouter();
const dayWizardQuery = computed(() => {
  const linked = plan.value?.jointPlanning;
  if (linked) return { schools: "2", school1: String(linked.planIds[0]), school2: String(linked.planIds[1]) };
  const a = route.query.school1;
  const b = route.query.school2;
  if (typeof a === "string" && /^[1-9]\d*$/.test(a) && b === undefined && a === String(route.params.id)) return { schools: "2", school1: a };
  return typeof a === "string" && typeof b === "string"
    && /^[1-9]\d*$/.test(a) && /^[1-9]\d*$/.test(b) && a !== b
    && [a, b].includes(String(route.params.id))
    ? { schools: "2", school1: a, school2: b } : null;
});
const bootstrap = inject(adminBootstrapKey);

if (!bootstrap) {
  throw new Error("Admin bootstrapdata ontbreekt.");
}

const rosterPlanCsrfToken = bootstrap.rosterPlanCsrfToken;
const finalizeToken = bootstrap.rosterFinalizeCsrfToken;
const reopenToken = bootstrap.rosterReopenCsrfToken;
const resetSessionsToken = bootstrap.rosterResetSessionsCsrfToken;

const plan = ref<DashboardRosterPlan | null>(null);
const loading = ref(false);
const convertingToCustom = ref(false);
const conversionError = ref<string | null>(null);
const notFound = ref(false);
const error = ref(false);
const workspaceMode = ref<WorkspaceMode>(props.embeddedPlanId ? "manage" : "view");
const viewTab = ref<ViewTab>("total");
const manageTab = ref<ManageTab>(props.manualOnly ? "manual" : "wizard");
const generatorPanel = ref<InstanceType<typeof RosterAutoGeneratorPanel> | null>(null);
const generatorAnchor = ref<HTMLElement | null>(null);
function openWizard(): void {
  workspaceMode.value = "manage";
  manageTab.value = "wizard";
  void scrollToRosterAction(() => generatorAnchor.value);
}
function openManualCorrections(): void {
  if (generatorPanel.value?.hasUnappliedProposal() && !window.confirm("Je voorstel is nog niet toegepast. Doorgaan naar handmatige correcties? Het opgeslagen rooster blijft ongewijzigd.")) return;
  manageTab.value = "manual";
}
async function addTeachersToLessons(): Promise<void> {
  if (!plan.value || plan.value.status !== "concept" || !plan.value.planning.sessions.length) return;
  workspaceMode.value = "manage";
  manageTab.value = "wizard";
  await nextTick();
  generatorPanel.value?.startExistingStaffing(staffingProgress.value?.state === "complete");
}
const manualWarnings = ref<Array<{ code: string; message: string }>>([]);
let controller: AbortController | undefined;
const validation = ref<RosterValidationResult | null>(null);
const lifecycleBusy = ref(false);
const lifecycleError = ref<string | null>(null);
const resetTarget = ref<{ id: number; revision: number } | null>(null);
const resetBusy = ref(false);
const resetError = ref<string | null>(null);
const resetSuccess = ref<string | null>(null);
let resetSuccessTimer: ReturnType<typeof setTimeout> | undefined;

function openResetDialog(): void {
  if (!plan.value || plan.value.status !== "concept" || resetBusy.value || lifecycleBusy.value || loading.value) return;
  resetError.value = null;
  resetSuccess.value = null;
  resetTarget.value = { id: plan.value.id, revision: plan.value.revision };
}

async function confirmReset(): Promise<void> {
  if (!resetTarget.value || resetBusy.value) return;
  const target = resetTarget.value;
  resetBusy.value = true;
  resetError.value = null;
  try {
    const result = await resetRosterSessions(target.id, target.revision, resetSessionsToken);
    if (plan.value?.id !== target.id) return;
    validation.value = null;
    manualWarnings.value = [];
    lifecycleError.value = null;
    workspaceMode.value = "view";
    viewTab.value = "total";
    plan.value = {
      ...plan.value,
      revision: result.revision,
      planning: { ...plan.value.planning, sessions: [] },
    };
    // The revision is already updated, so load() cannot detect this change
    // for the parent. Refresh the linked lesson and teacher views as well.
    emit("changed");
    resetSuccess.value = result.alreadyEmpty
      ? "De lesindeling was al leeg. Er is niets gewijzigd."
      : "De lesindeling is leeggemaakt. Groepen, dagindeling en personeelsinstellingen zijn behouden.";
    clearTimeout(resetSuccessTimer);
    resetSuccessTimer = setTimeout(() => { resetSuccess.value = null; }, 5000);
    await load();
    if (error.value) resetError.value = "De reset is geslaagd, maar het rooster kon niet opnieuw worden geladen. Herlaad de pagina voordat je verder plant.";
  } catch (requestError) {
    resetError.value = requestError instanceof Error ? requestError.message : "De lesindeling kon niet worden leeggemaakt.";
    if (requestError instanceof RosterLifecycleApiError && requestError.status === 409) await load();
  } finally {
    resetBusy.value = false;
    resetTarget.value = null;
  }
}
const canFinalize = computed(() => plan.value?.status === "concept"
  && validation.value?.planId === plan.value.id
  && validation.value.revision === plan.value.revision
  && validation.value.valid && validation.value.errors.length === 0
  && rosterStaffIssues(plan.value).length === 0);

watch(() => [plan.value?.id, plan.value?.revision, plan.value?.planning.catalogRevision], () => { validation.value = null; });

async function checkRoster(): Promise<void> {
  if (!plan.value || lifecycleBusy.value) return;
  const current = { id: plan.value.id, revision: plan.value.revision };
  lifecycleBusy.value = true;
  lifecycleError.value = null;
  workspaceMode.value = "view";
  viewTab.value = "control";
  try {
    const result = await validateDashboardRoster(current.id, current.revision);
    if (plan.value.id === current.id && plan.value.revision === current.revision) validation.value = result.validation;
  } catch (requestError) {
    validation.value = null;
    lifecycleError.value = requestError instanceof Error ? requestError.message : "Controleren is niet gelukt.";
    if (requestError instanceof RosterLifecycleApiError && requestError.status === 409) await load();
  } finally { lifecycleBusy.value = false; }
}

async function changeLifecycle(action: "finalize" | "reopen"): Promise<void> {
  if (!plan.value || lifecycleBusy.value || (action === "finalize" && !canFinalize.value)) return;
  if (action === "reopen" && !window.confirm("Heropenen voor wijzigingen? Dit rooster wordt concept. Een nieuwe controle en finalisatie zijn nodig voordat het als actueel definitief rooster kan worden verzonden. De eerdere definitieve versie blijft bewaard.")) return;
  if (action === "finalize" && validation.value?.warnings.length
    && !window.confirm("Definitief maken met deze waarschuwingen?\n\n" + validation.value.warnings.map(issue => issue.message).join("\n\n") + "\n\nDoor te bevestigen accepteer je deze afwijkingen.")) return;
  lifecycleBusy.value = true;
  lifecycleError.value = null;
  try {
    const result = await changeDashboardRosterLifecycle(action, plan.value.id, plan.value.revision, action === "finalize" ? finalizeToken : reopenToken);
    plan.value = result.plan;
    validation.value = null;
    workspaceMode.value = "view";
  } catch (requestError) {
    lifecycleError.value = requestError instanceof Error ? requestError.message : "De roosterstatus kon niet worden gewijzigd.";
    validation.value = requestError instanceof RosterLifecycleApiError ? requestError.validation : null;
    if (requestError instanceof RosterLifecycleApiError && requestError.status === 409) await load();
    workspaceMode.value = "view";
    viewTab.value = "control";
  } finally { lifecycleBusy.value = false; }
}

const planId = computed(() => {
  if (props.embeddedPlanId) return props.embeddedPlanId;
  const value = Array.isArray(route.params.id) ? route.params.id[0] : route.params.id;
  const id = Number(value);
  return typeof value === "string" && /^\d+$/.test(value) && Number.isSafeInteger(id) && id > 0
    ? id
    : null;
});

const programHelp = ref(false);
const staffingProgress = computed(() => plan.value ? rosterStaffingState(plan.value.planning.sessions, plan.value.planning.staffingSettings) : null);
const canConvertToCustom = computed(() =>
  plan.value?.planningMode === "booking_locked"
  && plan.value.bookingId !== null
  && plan.value.status === "concept"
  && plan.value.planning.sessions.length === 0,
);

const canEditCustomProgram = computed(() =>
  plan.value?.planningMode === "custom"
  && plan.value.status === "concept",
);
const customProgramEditBlocked = computed(() =>
  canEditCustomProgram.value
  && (plan.value?.planning.sessions.length ?? 0) > 0,
);

const completeGroupCount = computed(() => {
  if (!plan.value) return 0;

  return plan.value.groups.filter((group) =>
    plan.value?.planning.modules.every((module) =>
      plan.value?.planning.sessions.some(
        (session) =>
          session.moduleKey === module.key
          && session.groupIds.includes(group.id),
      ),
    ),
  ).length;
});

function formatDate(value: string): string {
  const [year, month, day] = value.split("-");
  return year && month && day ? `${day}-${month}-${year}` : value;
}

function statusLabel(status: string): string {
  if (status === "archived") return "Gearchiveerd";
  if (status === "checked") return "Gecontroleerd (oude status)";
  if (status === "published") return "Definitief";
  return "Concept";
}

const exportingXlsx = ref(false);
const exportError = ref<string | null>(null);

async function exportXlsx(): Promise<void> {
  if (!plan.value || exportingXlsx.value) return;

  exportError.value = null;
  exportingXlsx.value = true;

  try {
    await downloadDashboardRosterXlsx(plan.value.id);
  } catch (requestError) {
    exportError.value = requestError instanceof Error
      ? requestError.message
      : "Excel-exporteren is niet gelukt.";
  } finally {
    exportingXlsx.value = false;
  }
}
async function load(): Promise<void> {
  controller?.abort();
  const requestController = new AbortController();
  controller = requestController;
  notFound.value = planId.value === null;
  error.value = false;

  if (planId.value === null) return;

  loading.value = true;
  try {
    const result = await fetchDashboardRoster(planId.value, requestController.signal);
    if (!props.embeddedPlanId && result.plan.jointPlanning) {
      const pair = result.plan.jointPlanning;
      await router.replace({ name: "roster-create", query: { schools: "2", school1: String(pair.planIds[0]), school2: String(pair.planIds[1]), wizard: String(result.plan.id) } });
      return;
    }
    const changed = plan.value && plan.value.revision !== result.plan.revision;
    plan.value = result.plan;
    if (result.plan.status !== "concept") workspaceMode.value = "view";
    else if (props.manualOnly) workspaceMode.value = "manage";
    if (changed) emit("changed");
  } catch (requestError) {
    if (requestError instanceof DOMException && requestError.name === "AbortError") return;
    notFound.value = requestError instanceof ApiError && [400, 404].includes(requestError.status);
    error.value = !notFound.value;
  } finally {
    if (!requestController.signal.aborted) loading.value = false;
  }
}

async function convertToCustom(): Promise<void> {
  if (!plan.value || convertingToCustom.value) return;
  if (!canConvertToCustom.value) { programHelp.value = true; return; }

  convertingToCustom.value = true;
  conversionError.value = null;

  try {
    const result = await convertDashboardRosterToCustom(
      {
        planId: plan.value.id,
        expectedRevision: plan.value.revision,
      },
      rosterPlanCsrfToken,
    );

    plan.value = result.plan;

    if (props.embeddedPlanId) { editingProgram.value = true; emit("changed"); return; }
    await router.push({
      name: "roster-create",
      query: { plan: String(result.plan.id) },
    });
  } catch (requestError) {
    conversionError.value = requestError instanceof Error
      ? requestError.message
      : "Het rooster kon niet naar maatwerk worden omgezet.";
  } finally {
    convertingToCustom.value = false;
  }
}

async function generatedRosterApplied(): Promise<void> {
  await load();
  workspaceMode.value = "view";
  viewTab.value = "total";
}

async function manualRosterChanged(
  warnings: Array<{ code: string; message: string }>,
): Promise<void> {
  manualWarnings.value = warnings;
  await load();
  workspaceMode.value = "manage";
  manageTab.value = "manual";
}

watch(planId, () => { void load(); }, { immediate: true });
onBeforeUnmount(() => { controller?.abort(); clearTimeout(resetSuccessTimer); });
</script>

<template>
  <aside v-if="dayWizardQuery && !props.embeddedPlanId" class="admin-inline-notice admin-inline-notice--plain">
    <RouterLink :to="{ name: 'roster-create', query: dayWizardQuery }">&larr; Terug naar het plannen van twee scholen</RouterLink>
    <p>Controleer beide schoolroosters opnieuw na het wijzigen van lessen of personeel.</p>
  </aside>
  <section class="admin-roster-detail" aria-labelledby="roster-detail-title">
    <RouterLink v-if="!props.embeddedPlanId" class="admin-booking-detail__back" :to="{ name: 'rosters' }">
      &larr; Terug naar roosters
    </RouterLink>

    <DashboardRosterCreateView v-if="editingProgram && plan" embedded :resume-plan-id="plan.id" @complete="programUpdated" />
    <div v-else-if="loading && !plan" class="admin-bookings__state" role="status">
      <p>Rooster laden&hellip;</p>
    </div>

    <div v-else-if="notFound" class="admin-bookings__state">
      <h1 id="roster-detail-title">Rooster niet gevonden</h1>
      <RouterLink class="admin-button admin-button--secondary" :to="{ name: 'rosters' }">
        &larr; Terug naar roosters
      </RouterLink>
    </div>

    <div v-else-if="error && !plan" class="admin-bookings__state" role="alert">
      <p>Het rooster kon niet worden geladen.</p>
      <AdminButton @click="load">Opnieuw proberen</AdminButton>
    </div>

    <template v-else-if="plan">
      <header
        class="admin-roster-detail__header"
        :class="plan.planningMode === 'custom'
          ? 'admin-roster-detail__header--custom'
          : 'admin-roster-detail__header--standard'"
      >
        <div>
          <div class="admin-roster-detail__identity">
            <span
              class="admin-roster-mode-badge"
              :class="plan.planningMode === 'custom'
                ? 'admin-roster-mode-badge--custom'
                : 'admin-roster-mode-badge--standard'"
            >
              {{ plan.planningMode === "custom" ? "Maatwerk" : "Standaard" }}
            </span>
            <p class="admin-eyebrow">
              {{ plan.planningMode === "custom" ? "Maatwerkrooster" : "Standaardrooster" }}
            </p>
          </div>
          <h1 id="roster-detail-title">
            {{ plan.planningMode === "custom" ? "Maatwerkrooster" : "Standaardrooster" }}
            &middot; {{ plan.school.name }}
          </h1>
          <p>
            {{ formatDate(plan.visitDate) }}
            &middot; {{ plan.education.programLabel }}
          </p>
        </div>
        <div class="admin-roster-detail__header-actions">
          <div class="admin-roster-detail__status">
            <div>
              <span class="admin-roster-detail__action-label">Status</span>
              <span class="admin-status admin-status--option" aria-label="Roosterstatus">{{ statusLabel(plan.status) }}</span>
            </div>
            <span class="admin-roster-detail__revision">Revisie {{ plan.revision }}</span>
          </div>
          <div v-if="plan.status === 'concept' || plan.status === 'published'" class="admin-roster-detail__action-group" role="group" aria-label="Rooster">
            <span class="admin-roster-detail__action-label">Rooster</span>
            <div class="admin-roster-detail__action-grid">
              <template v-if="plan.status === 'concept'">
                <AdminButton variant="secondary" :loading="lifecycleBusy" :disabled="resetBusy" @click="checkRoster">Rooster controleren</AdminButton>
                <AdminButton v-if="!props.manualOnly && !plan.jointPlanning" :disabled="!canFinalize || lifecycleBusy || resetBusy" @click="changeLifecycle('finalize')">Definitief maken</AdminButton>
                <AdminButton variant="secondary" :disabled="lifecycleBusy || loading || resetBusy" @click="openResetDialog">Lesindeling leegmaken</AdminButton>
              </template>
              <AdminButton v-else-if="plan.status === 'published' && !plan.jointPlanning" variant="secondary" :loading="lifecycleBusy" @click="changeLifecycle('reopen')">Heropenen voor wijzigingen</AdminButton>
            </div>
          </div>
          <div class="admin-roster-detail__action-group" role="group" aria-label="Overig">
            <span class="admin-roster-detail__action-label">Overig</span>
            <div class="admin-roster-detail__action-grid">
              <AdminButton
                variant="secondary"
                :loading="exportingXlsx"
                :disabled="plan.planning.sessions.length === 0"
                title="Download het groepsrooster en docentenrooster als Excelbestand."
                @click="exportXlsx"
              >
                Excel exporteren
              </AdminButton>

              <AdminButton
                v-if="plan.planningMode === 'booking_locked' && plan.status === 'concept'"
                variant="secondary"
                :loading="convertingToCustom"
                @click="convertToCustom"
              >
                Omzetten naar maatwerk
              </AdminButton>

              <AdminButton v-else-if="props.embeddedPlanId && canEditCustomProgram && !customProgramEditBlocked" variant="secondary" @click="editingProgram = true">Groepen, programma en dagindeling wijzigen</AdminButton>
              <RouterLink
                v-else-if="canEditCustomProgram && !customProgramEditBlocked"
                class="admin-button admin-button--secondary"
                :to="{ name: 'roster-create', query: { plan: String(plan.id) } }"
              >
                Groepen, programma en dagindeling wijzigen
              </RouterLink>

              <AdminButton
                v-else-if="canEditCustomProgram"
                variant="secondary"
                @click="programHelp = true"
              >
                Groepen, programma en dagindeling wijzigen
              </AdminButton>
            </div>
          </div>
        </div>
      </header>

      <p v-if="exportError" class="admin-inline-error" role="alert">{{ exportError }}</p>
      <p v-if="lifecycleError" class="admin-inline-error" role="alert">{{ lifecycleError }}</p>
      <p v-if="resetError" class="admin-inline-error" role="alert">{{ resetError }}</p>
      <p v-if="resetSuccess" class="admin-inline-notice admin-inline-notice--plain" role="status">{{ resetSuccess }}</p>
      <p v-if="plan.status === 'archived'" class="admin-inline-notice admin-inline-notice--plain">Dit rooster is gearchiveerd nadat de gezamenlijke planning is opgeheven. De historie blijft bewaard. De aanvraag kan opnieuw individueel worden gepland.</p>
      <p v-if="plan.status === 'published'" class="admin-inline-notice admin-inline-notice--plain">
        Wil je de lesindeling leegmaken? Heropen dit definitieve rooster eerst voor wijzigingen.
      </p>
      <p v-if="plan.finalization?.trusted" class="admin-inline-notice admin-inline-notice--plain" role="status">
        Definitieve revisie {{ plan.finalization.publishedRevision }}, vastgelegd op
        {{ new Date(plan.finalization.finalizedAt!).toLocaleString('nl-NL') }}.
      </p>
      <p v-else-if="plan.status !== 'concept' && plan.status !== 'archived'" class="admin-inline-notice admin-inline-notice--plain admin-inline-notice--warning">
        Dit rooster heeft een oude status zonder betrouwbare definitieve snapshot.
        <template v-if="plan.status === 'published'">Heropen het voor een nieuwe controle en finalisatie.</template>
      </p>

      <p v-if="conversionError" class="admin-inline-error" role="alert">
        {{ conversionError }}
      </p>

      <div v-if="programHelp" class="admin-inline-notice admin-inline-notice--plain" role="status">
        <p>Dit rooster bevat al lessen. Wijzig losse lessen via Handmatige correcties. Wil je de hele groeps- of programma-indeling vervangen, gebruik dan de aparte actie Lesindeling leegmaken en controleer eerst de gevolgen. Er is nu niets gewijzigd.</p>
        <AdminButton variant="secondary" @click="programHelp = false">Sluiten</AdminButton>
      </div>
      <RosterBookingReview v-if="plan.needsReview && plan.status === 'concept'" :plan="plan" @changed="load" />

            <details class="admin-roster-configuration">
        <summary>Roosterconfiguratie</summary>
        <div class="admin-roster-configuration__body">
          <dl class="admin-details">
            <dt>Roostertype</dt>
            <dd>{{ plan.planningMode === "custom" ? "Maatwerk" : "Standaard" }}</dd>
            <dt>Bron</dt>
            <dd>{{ plan.bookingId !== null ? `Aanvraag #${plan.bookingId}` : "Zelfstandig rooster" }}</dd>
            <dt>Operationele bron</dt>
            <dd>
              {{ plan.planningMode === "custom"
                ? "De opgeslagen roosterconfiguratie bepaalt programma, modules en dagindeling."
                : "De gekoppelde aanvraag bepaalt programma, modules en dagindeling." }}
            </dd>
            <dt>Modules</dt>
            <dd>{{ plan.planning.modules.length }}</dd>
            <dt>Roostergroepen</dt>
            <dd>{{ plan.groups.length }}</dd>
          </dl>

          <p>
            Bij personeelsgeneratie worden werkuren zo gelijkwaardig mogelijk verdeeld.
            Interne tussenuren van betaalde krachten worden sterker vermeden; bij vrijwilligers
            kan een tussenuur worden gebruikt wanneer dat nodig is.
          </p>
          <p>
            Dagvoorkeuren zijn zachte voorkeuren. Harde roosterregels en een betere totale
            personeelsverdeling mogen daarvan afwijken.
          </p>
        </div>
      </details>
<section v-if="!props.manualOnly" class="admin-roster-workspace-bar">
        <div class="admin-roster-workspace-bar__mode" role="group" aria-label="Werkmodus">
          <button
            type="button"
            :aria-pressed="workspaceMode === 'view'"
            @click="workspaceMode = 'view'"
          >
            Bekijken
          </button>
          <button
            type="button"
            :aria-pressed="workspaceMode === 'manage'"
            :disabled="plan.status !== 'concept' || lifecycleBusy"
            @click="openWizard"
          >
            Beheren
          </button>
        </div>

        <div class="admin-roster-workspace-bar__summary">
          <span>{{ plan.planning.sessions.length }} sessies</span>
          <span>Lesindeling: {{ completeGroupCount }}/{{ plan.groups.length }} groepen compleet</span>
          <span>{{ plan.planning.modules.length }} modules</span>
          <span>
            {{ staffingProgress?.label }}
          </span>
        </div>
      </section>

      <div v-if="!props.manualOnly && plan.status === 'concept' && plan.planning.sessions.length && staffingProgress && (staffingProgress.state !== 'complete' || staffingProgress.hasStaff)"
        class="admin-roster-existing-staffing">
        <div>
          <strong>{{ staffingProgress.label }}</strong>
          <p>{{ staffingProgress.state === 'complete' ? 'Bekijk of de wizard tussenuren kan verminderen. Je ziet de wijzigingen vóór het toepassen.' : 'De wizard gebruikt de opgeslagen lessen als uitgangspunt. Controleer het voorstel voordat je het toepast.' }}</p>
        </div>
        <AdminButton :variant="staffingProgress.state === 'complete' ? 'secondary' : 'primary'" :disabled="loading || lifecycleBusy || plan.needsReview" @click="addTeachersToLessons">
          {{ staffingProgress.action }}
        </AdminButton>
      </div>
      <RosterStaffIssues :plan="plan" @changed="load" />
      <template v-if="workspaceMode === 'view'">
        <nav v-if="!props.manualOnly" class="admin-roster-workspace-tabs" aria-label="Roosterweergave">
          <button
            type="button"
            :aria-pressed="viewTab === 'total'"
            @click="viewTab = 'total'"
          >
            Totaaloverzicht
          </button>
          <button
            type="button"
            :aria-pressed="viewTab === 'day'"
            @click="viewTab = 'day'"
          >
            Dagrooster
          </button>
          <button
            type="button"
            :aria-pressed="viewTab === 'staffing'"
            @click="viewTab = 'staffing'"
          >
            Personeel
          </button>
          <button
            type="button"
            :aria-pressed="viewTab === 'control'"
            @click="viewTab = 'control'"
          >
            Controle
          </button>
        </nav>

        <RosterTotalOverview
          v-if="viewTab === 'total' && !props.manualOnly"
          :plan="plan"
        />

        <RosterDaySchedule
          v-else-if="viewTab === 'day' || props.manualOnly"
          :groups="plan.groups"
          :sessions="plan.planning.sessions"
          :rounds="plan.planning.rounds"
        />

        <template v-else-if="viewTab === 'staffing'">
          <RosterTeacherSchedule
            v-if="!props.manualOnly"
            :groups="plan.groups"
            :sessions="plan.planning.sessions"
            :staff-catalog="plan.planning.staffCatalog"
            :rounds="plan.planning.rounds"
            :selected-staff-ids="plan.planning.selectedStaffIds"
            :staffing-settings="plan.planning.staffingSettings"
          />
          <RosterStaffingDemand :sessions="plan.planning.sessions" />
        </template>

        <template v-else>
          <RosterValidationSummary
            :result="validation"
            :finalized="plan.finalization?.trusted ?? false"
            :loading="lifecycleBusy"
          />
          <RosterScheduleMatrix
            :groups="plan.groups"
            :modules="plan.planning.modules"
            :sessions="plan.planning.sessions"
          />
        </template>
      </template>

      <template v-else>
        <nav v-if="!props.manualOnly" class="admin-roster-workspace-tabs" aria-label="Roosterbeheer">
          <button
            type="button"
            :aria-pressed="manageTab === 'wizard'"
            @click="openWizard"
          >
            Wizard
          </button>
          <button
            type="button"
            :aria-pressed="manageTab === 'manual'"
            @click="openManualCorrections"
          >
            Handmatige correcties
          </button>
        </nav>

        <div ref="generatorAnchor" />
        <RosterAutoGeneratorPanel
          ref="generatorPanel"
          v-if="!props.manualOnly && manageTab === 'wizard'"
          :plan="plan"
          @applied="generatedRosterApplied"
        />

        <template v-else>
          <div
            v-if="manualWarnings.length"
            class="admin-inline-notice admin-inline-notice--plain admin-inline-notice--warning"
          >
            <strong>Opgeslagen met zachte waarschuwing</strong>
            <ul>
              <li v-for="warning in manualWarnings" :key="warning.code">
                {{ warning.message }}
              </li>
            </ul>
          </div>

          <RosterManualGrid
            :plan="plan"
            @changed="manualRosterChanged"
          />

          <RosterTeacherSchedule
            v-if="!props.manualOnly"
            :groups="plan.groups"
            :sessions="plan.planning.sessions"
            :staff-catalog="plan.planning.staffCatalog"
            :rounds="plan.planning.rounds"
            :selected-staff-ids="plan.planning.selectedStaffIds"
            :staffing-settings="plan.planning.staffingSettings"
          />
        </template>
      </template>
    </template>
    <RosterResetSessionsDialog
      v-if="resetTarget"
      :busy="resetBusy"
      @cancel="resetTarget = null"
      @confirm="confirmReset"
    />
  </section>
</template>

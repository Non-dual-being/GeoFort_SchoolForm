<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from "vue";
import { RouterLink, useRoute } from "vue-router";

import AdminButton from "../components/form/AdminButton.vue";
import RosterAutoGeneratorPanel from "../components/rosters/RosterAutoGeneratorPanel.vue";
import RosterDaySchedule from "../components/rosters/RosterDaySchedule.vue";
import RosterManualGrid from "../components/rosters/RosterManualGrid.vue";
import RosterScheduleMatrix from "../components/rosters/RosterScheduleMatrix.vue";
import RosterStaffingDemand from "../components/rosters/RosterStaffingDemand.vue";
import RosterTeacherSchedule from "../components/rosters/RosterTeacherSchedule.vue";
import RosterTotalOverview from "../components/rosters/RosterTotalOverview.vue";
import RosterValidationSummary from "../components/rosters/RosterValidationSummary.vue";
import { fetchDashboardRoster } from "../services/dashboardRosterApi";
import type { DashboardRosterPlan } from "../types/roster";
import { ApiError } from "../../services/http/apiClient";

type WorkspaceMode = "view" | "manage";
type ViewTab = "total" | "day" | "staffing" | "control";
type ManageTab = "wizard" | "manual";

const route = useRoute();
const plan = ref<DashboardRosterPlan | null>(null);
const loading = ref(false);
const notFound = ref(false);
const error = ref(false);
const workspaceMode = ref<WorkspaceMode>("view");
const viewTab = ref<ViewTab>("total");
const manageTab = ref<ManageTab>("wizard");
const manualWarnings = ref<Array<{ code: string; message: string }>>([]);
let controller: AbortController | undefined;

const planId = computed(() => {
  const value = Array.isArray(route.params.id) ? route.params.id[0] : route.params.id;
  const id = Number(value);
  return typeof value === "string" && /^\d+$/.test(value) && Number.isSafeInteger(id) && id > 0
    ? id
    : null;
});

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
  if (status === "checked") return "Gecontroleerd";
  if (status === "published") return "Gepubliceerd";
  return "Concept";
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
    plan.value = result.plan;
  } catch (requestError) {
    if (requestError instanceof DOMException && requestError.name === "AbortError") return;
    notFound.value = requestError instanceof ApiError && [400, 404].includes(requestError.status);
    error.value = !notFound.value;
  } finally {
    if (!requestController.signal.aborted) loading.value = false;
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
onBeforeUnmount(() => controller?.abort());
</script>

<template>
  <section class="admin-roster-detail" aria-labelledby="roster-detail-title">
    <RouterLink class="admin-booking-detail__back" :to="{ name: 'rosters' }">
      &larr; Terug naar roosters
    </RouterLink>

    <div v-if="loading && !plan" class="admin-bookings__state" role="status">
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
      <header class="admin-roster-detail__header">
        <div>
          <p class="admin-eyebrow">Operationeel onderwijsrooster</p>
          <h1 id="roster-detail-title">{{ plan.school.name }}</h1>
          <p>
            {{ formatDate(plan.visitDate) }}
            &middot; {{ plan.education.programLabel }}
            &middot; revisie {{ plan.revision }}
          </p>
        </div>
        <span class="admin-status admin-status--option">{{ statusLabel(plan.status) }}</span>
      </header>

      <div
        v-if="plan.needsReview"
        class="admin-inline-notice admin-inline-notice--warning"
        role="status"
      >
        De gekoppelde aanvraag is gewijzigd sinds dit rooster werd gestart.
        Controleer de wizard en het rooster voordat het later wordt gepubliceerd.
      </div>

      <section class="admin-roster-workspace-bar">
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
            @click="workspaceMode = 'manage'"
          >
            Beheren
          </button>
        </div>

        <div class="admin-roster-workspace-bar__summary">
          <span>{{ plan.planning.sessions.length }} sessies</span>
          <span>{{ completeGroupCount }}/{{ plan.groups.length }} groepen compleet</span>
          <span>{{ plan.planning.modules.length }} modules</span>
          <span>
            {{ plan.planning.staffingSettings.staffingMode === "lesson_only"
              ? "alleen lesrooster"
              : "met personeel" }}
          </span>
        </div>
      </section>

      <template v-if="workspaceMode === 'view'">
        <nav class="admin-roster-workspace-tabs" aria-label="Roosterweergave">
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
          v-if="viewTab === 'total'"
          :plan="plan"
        />

        <RosterDaySchedule
          v-else-if="viewTab === 'day'"
          :groups="plan.groups"
          :sessions="plan.planning.sessions"
        />

        <template v-else-if="viewTab === 'staffing'">
          <RosterTeacherSchedule
            :sessions="plan.planning.sessions"
            :staff-catalog="plan.planning.staffCatalog"
            :selected-staff-ids="plan.planning.selectedStaffIds"
            :staffing-settings="plan.planning.staffingSettings"
          />
          <RosterStaffingDemand :sessions="plan.planning.sessions" />
        </template>

        <template v-else>
          <RosterValidationSummary
            :groups="plan.groups"
            :modules="plan.planning.modules"
            :sessions="plan.planning.sessions"
          />
          <RosterScheduleMatrix
            :groups="plan.groups"
            :modules="plan.planning.modules"
            :sessions="plan.planning.sessions"
          />
        </template>
      </template>

      <template v-else>
        <nav class="admin-roster-workspace-tabs" aria-label="Roosterbeheer">
          <button
            type="button"
            :aria-pressed="manageTab === 'wizard'"
            @click="manageTab = 'wizard'"
          >
            Wizard
          </button>
          <button
            type="button"
            :aria-pressed="manageTab === 'manual'"
            @click="manageTab = 'manual'"
          >
            Handmatige correcties
          </button>
        </nav>

        <RosterAutoGeneratorPanel
          v-if="manageTab === 'wizard'"
          :plan="plan"
          @applied="generatedRosterApplied"
        />

        <template v-else>
          <div
            v-if="manualWarnings.length"
            class="admin-inline-notice admin-inline-notice--warning"
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
            :sessions="plan.planning.sessions"
            :staff-catalog="plan.planning.staffCatalog"
            :selected-staff-ids="plan.planning.selectedStaffIds"
            :staffing-settings="plan.planning.staffingSettings"
          />
        </template>
      </template>
    </template>
  </section>
</template>

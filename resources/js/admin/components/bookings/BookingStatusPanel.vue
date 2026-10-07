<script setup lang="ts">
import { computed, inject, onBeforeUnmount, ref, watch } from "vue";
import AdminButton from "../form/AdminButton.vue";
import AdminSelect from "../form/AdminSelect.vue";
import BookingRuleOverrideDialog from "./BookingRuleOverrideDialog.vue";
import { adminBootstrapKey } from "../../types/admin";
import {
  BOOKING_STATUSES,
  type BookingStatus,
  type BookingStatusChangeCode,
  type BookingStatusMailMode,
  type BookingValidationIssue,
  type PlannerRosterAttachment,
  type RosterAttachmentMode,
} from "../../types/bookingStatus";
import {
  BookingStatusApiError,
  updateDashboardBookingStatus,
} from "../../services/dashboardBookingStatusApi";
import {
  shouldRefreshAfterStatusResult,
  statusChangeMessage,
  statusConfirmation,
  statusValidationIssueMessage,
} from "../../services/bookingStatusPresentation";
import { downloadPlannerRoster, getPlannerRosterAttachment, preparePlannerRoster, type PreparedPlannerRosterDocument } from "../../services/dashboardRosterSchoolApi";

const props = defineProps<{
  bookingId: number;
  currentStatus: BookingStatus;
}>();

const emit = defineEmits<{
  completed: [code: BookingStatusChangeCode, message: string, refresh: boolean];
}>();

const bootstrap = inject(adminBootstrapKey);
if (!bootstrap) throw new Error("Admin bootstrapdata ontbreekt.");

const bookingStatusCsrfToken = bootstrap.bookingStatusCsrfToken;
const rosterDocumentCsrfToken = bootstrap.rosterDocumentCsrfToken;

const target = ref<BookingStatus | "">("");
const submitting = ref(false);
const dialog = ref<HTMLDialogElement | null>(null);
const pendingMode = ref<BookingStatusMailMode>("none");
const overrideIssues = ref<BookingValidationIssue[]>([]);
const overrideReasons = ref<Record<string, string>>({});
const overrideDialogOpen = ref(false);
const rosterMode = ref<RosterAttachmentMode>("example");
const plannerRoster = ref<PlannerRosterAttachment | null>(null);
const rosterLoading = ref(false);
const rosterDownloading = ref(false);
const rosterPreparing = ref(false);
const preparedRoster = ref<PreparedPlannerRosterDocument | null>(null);
const rosterError = ref("");
let rosterRequest = 0;
let rosterPreparationRequest = 0;
const showRosterChoice = computed(() => target.value === "Definitief" && pendingMode.value === "send");
const plannerRosterReady = computed(() => showRosterChoice.value && rosterMode.value === "planner_final"
  && !!plannerRoster.value && preparedRoster.value?.snapshotId === plannerRoster.value.snapshotId);
const plannerRosterBlocked = computed(() => showRosterChoice.value && rosterMode.value === "planner_final" && !plannerRosterReady.value);
const finalizedLabel = computed(() => plannerRoster.value
  ? new Date(plannerRoster.value.finalizedAt).toLocaleString("nl-NL", { dateStyle: "long", timeStyle: "short" }) : "");

watch(() => [props.bookingId, props.currentStatus], () => {
  closeConfirmation();
  rosterMode.value = "example";
  plannerRoster.value = null;
  overrideDialogOpen.value = false;
  overrideIssues.value = [];
  overrideReasons.value = {};
});
onBeforeUnmount(() => { rosterRequest++; resetPreparation(); });
watch(rosterMode, () => {
  resetPreparation();
  rosterError.value = "";
  if (rosterMode.value === "planner_final") void prepareSelectedRoster();
});

const options = computed(() =>
  BOOKING_STATUSES
    .filter((status) => status !== props.currentStatus)
    .map((status) => ({
      value: status,
      label: status,
    })),
);

const supportsMail = computed(
  () => target.value === "Definitief" || target.value === "Afgewezen",
);

const currentStatusClass = computed(() => {
  switch (props.currentStatus) {
    case "Definitief":
      return "admin-status-pill admin-status-pill--success";
    case "Afgewezen":
      return "admin-status-pill admin-status-pill--danger";
    case "In optie":
    default:
      return "admin-status-pill admin-status-pill--warning";
  }
});

const helperMessage = computed(() => {
  if (target.value === "In optie") {
    return "Voor In optie wordt geen e-mail verstuurd.";
  }

  if (supportsMail.value) {
    return "Je kunt alleen de status wijzigen of direct ook een e-mail naar de contactpersoon versturen.";
  }

  return "";
});

const helperClass = computed(() => {
  if (target.value === "In optie") {
    return "admin-status-panel__note admin-status-panel__note--warning";
  }

  if (supportsMail.value) {
    return "admin-status-panel__note admin-status-panel__note--info";
  }

  return "admin-status-panel__note";
});

async function openConfirmation(mailMode: BookingStatusMailMode): Promise<void> {
  if (!target.value || submitting.value) return;

  pendingMode.value = mailMode;
  resetPreparation();
  rosterMode.value = "example";
  plannerRoster.value = null;
  rosterError.value = "";
  const request = ++rosterRequest;
  rosterLoading.value = showRosterChoice.value;
  dialog.value?.showModal();
  if (!showRosterChoice.value) return;
  try {
    const roster = await getPlannerRosterAttachment(props.bookingId);
    if (request === rosterRequest) plannerRoster.value = roster;
  } catch (error) {
    if (request === rosterRequest) rosterError.value = error instanceof Error ? error.message : "De roosterkeuze kon niet worden geladen.";
  } finally {
    if (request === rosterRequest) rosterLoading.value = false;
  }
}

function closeConfirmation(): void {
  rosterRequest++;
  rosterLoading.value = false;
  resetPreparation();
  dialog.value?.close();
}

function resetPreparation(): void {
  rosterPreparationRequest++;
  preparedRoster.value = null;
  rosterPreparing.value = false;
  rosterDownloading.value = false;
}

async function prepareSelectedRoster(): Promise<void> {
  if (!showRosterChoice.value || rosterMode.value !== "planner_final" || !plannerRoster.value || rosterPreparing.value) return;
  const request = ++rosterPreparationRequest;
  const bookingId = props.bookingId;
  const snapshotId = plannerRoster.value.snapshotId;
  preparedRoster.value = null;
  rosterPreparing.value = true;
  rosterError.value = "";
  try {
    const document = await preparePlannerRoster(bookingId, snapshotId, rosterDocumentCsrfToken);
    if (request === rosterPreparationRequest) preparedRoster.value = document;
  } catch (error) {
    if (request === rosterPreparationRequest) rosterError.value = error instanceof Error ? error.message : "Het schoolrooster kon niet worden voorbereid.";
  } finally {
    if (request === rosterPreparationRequest) rosterPreparing.value = false;
  }
}

async function previewRoster(): Promise<void> {
  if (!plannerRosterReady.value || !plannerRoster.value || rosterDownloading.value) return;
  const request = rosterPreparationRequest;
  rosterDownloading.value = true;
  rosterError.value = "";
  try {
    await downloadPlannerRoster(props.bookingId, plannerRoster.value.snapshotId);
  } catch (error) {
    if (request === rosterPreparationRequest) {
      preparedRoster.value = null;
      rosterError.value = error instanceof Error ? error.message : "Het schoolrooster kon niet worden gedownload.";
    }
  } finally { if (request === rosterPreparationRequest) rosterDownloading.value = false; }
}

async function submitStatus(withOverrides = false): Promise<void> {
  if (!target.value || submitting.value || rosterLoading.value || rosterDownloading.value) return;
  if (plannerRosterBlocked.value) return;

  if (!withOverrides) dialog.value?.close();
  submitting.value = true;

  let code: BookingStatusChangeCode = "DATABASE_ERROR";
  let validationMessage: string | null = null;

  try {
    const result = await updateDashboardBookingStatus(
      {
        bookingId: props.bookingId,
        expectedCurrentStatus: props.currentStatus,
        targetStatus: target.value,
        mailMode: pendingMode.value,
        overrides: withOverrides ? overrideIssues.value.map((issue) => ({ ruleCode: issue.code, reason: (overrideReasons.value[issue.code] ?? "").trim() })) : [],
        ...(showRosterChoice.value ? {
          rosterAttachmentMode: rosterMode.value,
          ...(rosterMode.value === "planner_final" && plannerRoster.value ? { rosterSnapshotId: plannerRoster.value.snapshotId } : {}),
        } : {}),
      },
      bookingStatusCsrfToken,
    );

    code = result.code;
  } catch (error) {
    if (error instanceof BookingStatusApiError && error.result) {
      code = error.result.code;
      if (code === "OVERRIDE_REQUIRED" || (code === "INVALID_OVERRIDE_REQUEST" && error.result.validationIssues.some((issue) => issue.overridable))) {
        const currentIssues = error.result.validationIssues.filter((issue) => issue.overridable);
        const nextReasons: Record<string, string> = {};
        for (const issue of currentIssues) nextReasons[issue.code] = overrideReasons.value[issue.code] ?? "";
        overrideReasons.value = nextReasons;
        overrideIssues.value = currentIssues;
        overrideDialogOpen.value = true;
      }
      const hardIssue = error.result.validationIssues.find((issue) => !issue.overridable);
      if (hardIssue) validationMessage = statusValidationIssueMessage(hardIssue);
    }
  } finally {
    submitting.value = false;
  }

  if (code === "SUCCESS") {
    overrideDialogOpen.value = false;
    overrideIssues.value = [];
    overrideReasons.value = {};
  }
  const refresh = shouldRefreshAfterStatusResult(code);

  if (refresh && code !== "MAIL_SEND_FAILED") {
    target.value = "";
  }

  emit("completed", code, validationMessage ?? statusChangeMessage(code, pendingMode.value), refresh);
}

function confirm(): void { void submitStatus(false); }
function confirmOverrides(): void { void submitStatus(true); }
</script>

<template>
  <section
    class="admin-card admin-booking-detail__wide admin-status-panel"
    aria-labelledby="status-panel-title"
  >
    <div class="admin-status-panel__intro">
      <h2 id="status-panel-title">Status beheren</h2>
      <p class="admin-booking-detail__muted">
        Wijzig de status van deze aanvraag, eventueel met een e-mail naar de contactpersoon.
      </p>
    </div>

    <div class="admin-status-panel__grid">
      <div class="admin-status-panel__field">
        <div class="admin-status-panel__field-title">
          Huidige status
        </div>

        <div class="admin-status-panel__field-body admin-status-panel__field-body--center">
          <span :class="currentStatusClass">
            {{ currentStatus }}
          </span>
        </div>
      </div>

      <div class="admin-status-panel__field">
        <div class="admin-status-panel__field-title">
          Status veranderen
        </div>

        <div class="admin-status-panel__field-body admin-status-panel__field-body--center">
          <AdminSelect
            v-model="target"
            class="admin-status-panel__select"
            name="status-target"
            label=""
            placeholder="Kies een status"
            :options="options"
            :disabled="submitting"
          />
        </div>
      </div>

      <div class="admin-status-panel__field">
        <div class="admin-status-panel__field-title">
          Acties
        </div>

        <div class="admin-status-panel__field-body">
          <div class="admin-status-panel__buttons">
            <AdminButton
              variant="secondary"
              :disabled="!target || submitting"
              :loading="submitting && pendingMode === 'none'"
              @click="openConfirmation('none')"
            >
              {{
                submitting && pendingMode === "none"
                  ? "Status wijzigen…"
                  : "Alleen status wijzigen"
              }}
            </AdminButton>

            <AdminButton
              v-if="supportsMail"
              :disabled="!target || submitting"
              :loading="submitting && pendingMode === 'send'"
              @click="openConfirmation('send')"
            >
              {{
                submitting && pendingMode === "send"
                  ? "Status wijzigen en e-mail sturen…"
                  : "Status wijzigen en e-mail sturen"
              }}
            </AdminButton>
          </div>

          <p
            v-if="helperMessage"
            :class="helperClass"
          >
            {{ helperMessage }}
          </p>
        </div>
      </div>
    </div>

    <dialog
      ref="dialog"
      class="admin-status-dialog"
      @cancel="closeConfirmation"
    >
      <form method="dialog">
        <h2>Statuswijziging bevestigen</h2>

        <p v-if="target">
          {{ statusConfirmation(target, pendingMode) }}
        </p>

        <div v-if="showRosterChoice" class="admin-status-dialog__roster">
          <p v-if="rosterLoading" role="status">Beschikbare roosters controleren…</p>
          <p v-if="rosterPreparing" role="status">Schoolrooster voorbereiden…</p>
          <p v-if="rosterError" class="admin-status-feedback admin-status-feedback--error" role="alert">{{ rosterError }}</p>
          <fieldset v-if="plannerRoster" class="admin-status-dialog__roster-options" :disabled="rosterDownloading">
            <legend>Rooster voor de school</legend>
            <div class="admin-choice-grid">
              <label class="admin-choice-card" :class="{ 'admin-choice-card--selected': rosterMode === 'example' }">
                <input v-model="rosterMode" type="radio" name="roster-attachment" value="example">
                <span class="admin-choice-card__body">Voorbeeldrooster</span>
              </label>
              <label class="admin-choice-card" :class="{ 'admin-choice-card--selected': rosterMode === 'planner_final' }">
                <input v-model="rosterMode" type="radio" name="roster-attachment" value="planner_final">
                <span class="admin-choice-card__body">
                  Definitief rooster uit planner
                  <small>Revisie {{ plannerRoster.revision }} · definitief gemaakt op {{ finalizedLabel }}</small>
                </span>
              </label>
            </div>
          </fieldset>
          <AdminButton v-if="plannerRosterReady" type="button" variant="secondary" :loading="rosterDownloading" :disabled="rosterDownloading" @click="previewRoster">
            Schoolrooster downloaden (PDF)
          </AdminButton>
          <AdminButton v-else-if="plannerRoster && rosterMode === 'planner_final' && !rosterPreparing" type="button" variant="secondary" @click="prepareSelectedRoster">
            Opnieuw voorbereiden
          </AdminButton>
        </div>

        <div class="admin-status-dialog__actions">
          <AdminButton type="button" variant="secondary" @click="closeConfirmation">
            Annuleren
          </AdminButton>

          <AdminButton type="button" :disabled="rosterLoading || rosterDownloading || plannerRosterBlocked" @click="confirm">
            Bevestigen
          </AdminButton>
        </div>
      </form>
    </dialog>

    <BookingRuleOverrideDialog
      :open="overrideDialogOpen"
      :issues="overrideIssues"
      :submitting="submitting"
      :reasons="overrideReasons"
      @close="overrideDialogOpen = false"
      @update:reason="(code, value) => overrideReasons[code] = value"
      @confirm="confirmOverrides"
    />
  </section>
</template>

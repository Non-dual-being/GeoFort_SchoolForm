<script setup lang="ts">
import { computed, inject, ref } from "vue";
import AdminButton from "../form/AdminButton.vue";
import AdminSelect from "../form/AdminSelect.vue";
import AdminDialog from "../feedback/AdminDialog.vue";
import { adminBootstrapKey } from "../../types/admin";
import {
  BOOKING_STATUSES,
  type BookingStatus,
  type BookingStatusChangeCode,
  type BookingStatusMailMode,
  type BookingValidationIssue,
} from "../../types/bookingStatus";
import {
  BookingStatusApiError,
  updateDashboardBookingStatus,
} from "../../services/dashboardBookingStatusApi";
import {
  shouldRefreshAfterStatusResult,
  statusChangeMessage,
  statusConfirmation,
} from "../../services/bookingStatusPresentation";

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

const target = ref<BookingStatus | "">("");
const submitting = ref(false);
const dialog = ref<HTMLDialogElement | null>(null);
const pendingMode = ref<BookingStatusMailMode>("none");
const overrideIssues = ref<BookingValidationIssue[]>([]);
const overrideReasons = ref<Record<string, string>>({});
const overrideDialogOpen = ref(false);

const overrideReasonsValid = computed(() => overrideIssues.value.length > 0 && overrideIssues.value.every((issue) => {
  const length = (overrideReasons.value[issue.code] ?? "").trim().length;
  return length >= 15 && length <= 500;
}));

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

function openConfirmation(mailMode: BookingStatusMailMode): void {
  if (!target.value || submitting.value) return;

  pendingMode.value = mailMode;
  dialog.value?.showModal();
}

async function submitStatus(withOverrides = false): Promise<void> {
  if (!target.value || submitting.value) return;

  if (!withOverrides) dialog.value?.close();
  submitting.value = true;

  let code: BookingStatusChangeCode = "DATABASE_ERROR";

  try {
    const result = await updateDashboardBookingStatus(
      {
        bookingId: props.bookingId,
        expectedCurrentStatus: props.currentStatus,
        targetStatus: target.value,
        mailMode: pendingMode.value,
        overrides: withOverrides ? overrideIssues.value.map((issue) => ({ ruleCode: issue.code, reason: (overrideReasons.value[issue.code] ?? "").trim() })) : [],
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

  emit("completed", code, statusChangeMessage(code, pendingMode.value), refresh);
}

function confirm(): void { void submitStatus(false); }
function confirmOverrides(): void { if (overrideReasonsValid.value) void submitStatus(true); }

function metadataLabel(key: string): string {
  return ({
    confirmedStudents: "Al definitieve leerlingen", bookingStudents: "Leerlingen in deze aanvraag",
    projectedStudents: "Totaal na bevestiging", maximumStudents: "Maximum leerlingen",
    confirmedSchools: "Al definitieve scholen", projectedSchools: "Totaal na bevestiging",
    maximumSchools: "Maximum scholen", supervisorCount: "Opgegeven begeleiders",
    minimumSupervisors: "Minimaal vereist", studentCount: "Leerlingen",
  } as Record<string, string>)[key] ?? key;
}
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
      @cancel="dialog?.close()"
    >
      <form method="dialog">
        <h2>Statuswijziging bevestigen</h2>

        <p v-if="target">
          {{ statusConfirmation(target, pendingMode) }}
        </p>

        <div class="admin-status-dialog__actions">
          <AdminButton type="button" variant="secondary" @click="dialog?.close()">
            Annuleren
          </AdminButton>

          <AdminButton type="button" @click="confirm">
            Bevestigen
          </AdminButton>
        </div>
      </form>
    </dialog>

    <AdminDialog
      :open="overrideDialogOpen"
      title="Bewust afwijken van boekingsregels"
      description="Je staat op het punt bewust af te wijken van een boekingsregel. Deze keuze en je reden worden vastgelegd in het auditlog."
      :close-on-backdrop="!submitting"
      :close-on-escape="!submitting"
      @close="overrideDialogOpen = false"
    >
      <div class="admin-override-dialog__issues">
        <section v-for="issue in overrideIssues" :key="issue.code" class="admin-override-dialog__issue">
          <h3>{{ issue.title }}</h3>
          <p>{{ issue.description }}</p>
          <dl v-if="Object.keys(issue.metadata).length" class="admin-override-dialog__metadata">
            <template v-for="(value, key) in issue.metadata" :key="key">
              <dt>{{ metadataLabel(String(key)) }}</dt><dd>{{ typeof value === "boolean" ? (value ? "Ja" : "Nee") : value }}</dd>
            </template>
          </dl>
          <label :for="`override-reason-${issue.code}`">Reden voor deze afwijking</label>
          <textarea
            :id="`override-reason-${issue.code}`"
            v-model="overrideReasons[issue.code]"
            rows="4"
            maxlength="500"
            :disabled="submitting"
            required
          />
          <small>Minimaal 15 en maximaal 500 tekens.</small>
        </section>
      </div>
      <template #footer>
        <AdminButton type="button" variant="secondary" :disabled="submitting" @click="overrideDialogOpen = false">Annuleren</AdminButton>
        <AdminButton type="button" :disabled="!overrideReasonsValid || submitting" :loading="submitting" @click="confirmOverrides">Toch doorgaan</AdminButton>
      </template>
    </AdminDialog>
  </section>
</template>

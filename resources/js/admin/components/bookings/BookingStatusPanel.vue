<script setup lang="ts">
import { computed, inject, ref } from "vue";
import AdminButton from "../form/AdminButton.vue";
import AdminSelect from "../form/AdminSelect.vue";
import { adminBootstrapKey } from "../../types/admin";
import {
  BOOKING_STATUSES,
  type BookingStatus,
  type BookingStatusChangeCode,
  type BookingStatusMailMode,
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

async function confirm(): Promise<void> {
  if (!target.value || submitting.value) return;

  dialog.value?.close();
  submitting.value = true;

  let code: BookingStatusChangeCode = "DATABASE_ERROR";

  try {
    const result = await updateDashboardBookingStatus(
      {
        bookingId: props.bookingId,
        expectedCurrentStatus: props.currentStatus,
        targetStatus: target.value,
        mailMode: pendingMode.value,
      },
      bookingStatusCsrfToken,
    );

    code = result.code;
  } catch (error) {
    if (error instanceof BookingStatusApiError && error.result) {
      code = error.result.code;
    }
  } finally {
    submitting.value = false;
  }

  const refresh = shouldRefreshAfterStatusResult(code);

  if (refresh && code !== "MAIL_SEND_FAILED") {
    target.value = "";
  }

  emit("completed", code, statusChangeMessage(code, pendingMode.value), refresh);
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
  </section>
</template>
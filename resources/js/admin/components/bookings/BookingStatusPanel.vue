<script setup lang="ts">
import { computed, inject, ref } from "vue";
import AdminButton from "../form/AdminButton.vue";
import { adminBootstrapKey } from "../../types/admin";
import { BOOKING_STATUSES, type BookingStatus, type BookingStatusChangeCode, type BookingStatusMailMode } from "../../types/bookingStatus";
import { BookingStatusApiError, updateDashboardBookingStatus } from "../../services/dashboardBookingStatusApi";
import { shouldRefreshAfterStatusResult, statusChangeMessage, statusConfirmation } from "../../services/bookingStatusPresentation";

const props = defineProps<{ bookingId: number; currentStatus: BookingStatus }>();
const emit = defineEmits<{ completed: [code: BookingStatusChangeCode, message: string, refresh: boolean] }>();
const bootstrap = inject(adminBootstrapKey);
if (!bootstrap) throw new Error("Admin bootstrapdata ontbreekt.");
const bookingStatusCsrfToken = bootstrap.bookingStatusCsrfToken;

const target = ref<BookingStatus | "">("");
const submitting = ref(false);
const dialog = ref<HTMLDialogElement | null>(null);
const pendingMode = ref<BookingStatusMailMode>("none");
const options = computed(() => BOOKING_STATUSES.filter((status) => status !== props.currentStatus));

const supportsMail = computed(() => target.value === "Definitief" || target.value === "Afgewezen");

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
    const result = await updateDashboardBookingStatus({
      bookingId: props.bookingId,
      expectedCurrentStatus: props.currentStatus,
      targetStatus: target.value,
      mailMode: pendingMode.value,
    }, bookingStatusCsrfToken);
    code = result.code;
  } catch (error) {
    if (error instanceof BookingStatusApiError && error.result) code = error.result.code;
  } finally {
    submitting.value = false;
  }
  const refresh = shouldRefreshAfterStatusResult(code);
  if (refresh && code !== "MAIL_SEND_FAILED") target.value = "";
  emit("completed", code, statusChangeMessage(code, pendingMode.value), refresh);
}
</script>

<template>
  <section class="admin-card admin-booking-detail__wide admin-status-panel" aria-labelledby="status-panel-title">
    <h2 id="status-panel-title">Status beheren</h2>
    <p>Huidige status: <span class="admin-status">{{ currentStatus }}</span></p>
    <label for="status-target">Nieuwe status</label>
    <select id="status-target" v-model="target" :disabled="submitting">
      <option value="">Kies een status</option>
      <option v-for="status in options" :key="status" :value="status">{{ status }}</option>
    </select>
    <div class="admin-status-dialog__actions">
      <AdminButton :disabled="!target" :loading="submitting && pendingMode === 'none'" @click="openConfirmation('none')">{{ submitting && pendingMode === 'none' ? 'Status wijzigen…' : 'Alleen status wijzigen' }}</AdminButton>
      <AdminButton v-if="supportsMail" :disabled="!target" :loading="submitting && pendingMode === 'send'" @click="openConfirmation('send')">{{ submitting && pendingMode === 'send' ? 'Status wijzigen en e-mail sturen…' : 'Status wijzigen en e-mail sturen' }}</AdminButton>
    </div>
    <p v-if="target === 'In optie'" class="admin-booking-detail__muted">Voor In optie wordt geen e-mail verstuurd.</p>

    <dialog ref="dialog" class="admin-status-dialog" @cancel="dialog?.close()">
      <form method="dialog">
        <h2>Statuswijziging bevestigen</h2>
        <p v-if="target">{{ statusConfirmation(target, pendingMode) }}</p>
        <div class="admin-status-dialog__actions">
          <AdminButton variant="secondary" @click="dialog?.close()">Annuleren</AdminButton>
          <AdminButton type="button" @click="confirm">Bevestigen</AdminButton>
        </div>
      </form>
    </dialog>
  </section>
</template>

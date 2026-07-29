<script setup lang="ts">
import { nextTick, ref, watch } from "vue";
import { RouterLink } from "vue-router";
import AdminDialog from "../feedback/AdminDialog.vue";
import AdminButton from "../form/AdminButton.vue";
import AdminConfirmationControl from "../form/AdminConfirmationControl.vue";
import AdminInlineNotice from "../form/AdminInlineNotice.vue";
import AdminInput from "../form/AdminInput.vue";
import type {
  CalendarDateAction,
  CalendarDateManagementIssue,
  CalendarDateManagementPreview,
} from "../../types/calendarDateManagement";

const props = defineProps<{
  open: boolean;
  action: CalendarDateAction;
  startDate: string;
  endDate: string;
  reason: string;
  confirmed: boolean;
  existingBookingsAccepted: boolean;
  preview: CalendarDateManagementPreview | null;
  previewing: boolean;
  submitting: boolean;
  issues: CalendarDateManagementIssue[];
}>();
const emit = defineEmits<{
  close: [];
  preview: [];
  submit: [];
  "update:endDate": [value: string];
  "update:reason": [value: string];
  "update:confirmed": [value: boolean];
  "update:existingBookingsAccepted": [value: boolean];
}>();
const reasonInput = ref<InstanceType<typeof AdminInput> | null>(null);
const errorSummary = ref<HTMLElement | null>(null);
const issue = (field: string) => props.issues.find((item) => item.field === field)?.description ?? null;
const isBlock = () => props.action.startsWith("block_");
const isPeriod = () => props.action.endsWith("_period");

watch(() => props.issues, async (issues) => {
  if (!issues.length) return;
  await nextTick();
  if (issues.some((item) => item.field === "reason")) reasonInput.value?.focus();
  else document.querySelector<HTMLElement>(".admin-dialog [aria-invalid='true']")?.focus() ?? errorSummary.value?.focus();
}, { deep: true });
</script>

<template>
  <AdminDialog
    :open="open"
    :title="isBlock() ? (isPeriod() ? 'Periode blokkeren' : 'Datum blokkeren') : (isPeriod() ? 'Periode vrijgeven' : 'Datum vrijgeven')"
    description="Controleer eerst de serverpreview en bevestig daarna de volledige bewerking."
    :close-on-backdrop="!submitting && !previewing"
    :close-on-escape="!submitting && !previewing"
    :close-disabled="submitting || previewing"
    @close="emit('close')"
  >
    <form class="admin-calendar-management" @submit.prevent="preview ? emit('submit') : emit('preview')">
      <AdminInput :model-value="startDate" label="Begindatum" disabled />
      <AdminInput
        v-if="isPeriod()"
        :model-value="endDate"
        label="Einddatum"
        name="calendar-management-end-date"
        type="date"
        :disabled="submitting || previewing"
        :error="issue('endDate')"
        @update:model-value="emit('update:endDate', $event)"
      />
      <template v-if="isBlock()">
        <AdminInput
          ref="reasonInput"
          :model-value="reason"
          label="Reden"
          name="calendar-block-reason"
          required
          :disabled="submitting || previewing"
          :error="issue('reason')"
          @update:model-value="emit('update:reason', $event)"
        />
      </template>

      <AdminInlineNotice v-if="preview" variant="info" title="Serverpreview">
        <dl class="admin-calendar-management__preview">
          <dt>Kalenderdagen</dt><dd>{{ preview.calendarDayCount }}</dd>
          <dt>{{ isBlock() ? "Nieuwe manual-records" : "Vrij te geven manual-records" }}</dt><dd>{{ preview.categories.affectedDates.length }}</dd>
          <dt>Overgeslagen weekenden</dt><dd>{{ preview.categories.weekendDates.length }}</dd>
          <dt>Bestaande manual-records</dt><dd>{{ preview.categories.existingManualDates.length }}</dd>
          <dt>Andere blokkades</dt><dd>{{ preview.categories.otherBlockedDates.length }}</dd>
        </dl>
        <p v-if="preview.categories.affectedDates.length">
          <strong>Betrokken datums:</strong> {{ preview.categories.affectedDates.join(", ") }}
        </p>
      </AdminInlineNotice>

      <AdminInlineNotice v-if="preview && preview.bookingCount > 0" variant="warning" title="Bestaande boekingen blijven staan">
        <p>Op deze selectie staan {{ preview.bookingCount }} actieve boekingen met in totaal {{ preview.studentCount }} leerlingen. Deze boekingen blijven bestaan. Alleen nieuwe boekingen worden geblokkeerd.</p>
        <ul>
          <li v-for="booking in preview.categories.activeBookings" :key="booking.id">
            <RouterLink :to="{ name: 'booking-detail', params: { id: booking.id } }">Aanvraag #{{ booking.id }}</RouterLink>
            — {{ booking.date }}, {{ booking.status }}, {{ booking.studentCount ?? "?" }} leerlingen
          </li>
        </ul>
      </AdminInlineNotice>

      <AdminConfirmationControl
        v-if="isBlock() && !isPeriod() && (preview?.bookingCount ?? 0) > 0"
        :model-value="existingBookingsAccepted"
        label="Ik begrijp dat bestaande boekingen op deze datum blijven staan."
        :disabled="submitting || previewing"
        :error="issue('existingBookingsAccepted')"
        @update:model-value="emit('update:existingBookingsAccepted', $event)"
      />
      <AdminConfirmationControl
        v-if="preview"
        :model-value="confirmed"
        :label="isBlock() ? 'Ik wil de getoonde datums handmatig blokkeren voor nieuwe boekingen.' : 'Ik wil uitsluitend de getoonde handmatige blokkades verwijderen.'"
        :disabled="submitting || previewing"
        :error="issue('confirmed')"
        @update:model-value="emit('update:confirmed', $event)"
      />

      <div v-if="issues.length" ref="errorSummary" class="admin-calendar-management__errors" role="alert" aria-live="assertive" tabindex="-1">
        <p v-for="item in issues" :key="`${item.code}-${item.field}`">{{ item.description }}</p>
      </div>
    </form>
    <template #footer>
      <AdminButton variant="secondary" :disabled="submitting || previewing" @click="emit('close')">Annuleren</AdminButton>
      <AdminButton v-if="!preview" :loading="previewing" @click="emit('preview')">Preview laden</AdminButton>
      <AdminButton v-else :loading="submitting" :disabled="previewing" @click="emit('submit')">
        {{ isBlock() ? "Blokkeren" : "Vrijgeven" }}
      </AdminButton>
    </template>
  </AdminDialog>
</template>

<script setup lang="ts">
import { computed, inject, onBeforeUnmount, reactive, ref, watch } from "vue";
import { ChevronLeft, ChevronRight } from "lucide-vue-next";
import AdminButton from "../components/form/AdminButton.vue";
import AdminInlineNotice from "../components/form/AdminInlineNotice.vue";
import DashboardCalendarDayCell from "../components/calendar/DashboardCalendarDayCell.vue";
import DashboardCalendarDayDetail from "../components/calendar/DashboardCalendarDayDetail.vue";
import DashboardCalendarDateManagementDialog from "../components/calendar/DashboardCalendarDateManagementDialog.vue";
import DashboardCalendarViewSelector from "../components/calendar/DashboardCalendarViewSelector.vue";
import DashboardCalendarActionCard from "../components/calendar/DashboardCalendarActionCard.vue";
import AdminCollapsibleHelp from "../components/help/AdminCollapsibleHelp.vue";
import { fetchDashboardCalendar } from "../services/dashboardCalendarApi";
import {
  CalendarDateManagementApiError,
  manageDashboardCalendarDate,
  previewDashboardCalendarDateManagement,
} from "../services/dashboardCalendarDateManagementApi";
import type { DashboardCalendarDay } from "../types/dashboardCalendar";
import type {
  CalendarDateAction,
  CalendarDateManagementIssue,
  CalendarDateManagementPreview,
  CalendarManagementMode,
} from "../types/calendarDateManagement";
import { adminBootstrapKey } from "../types/admin";

const visibleMonth = ref(monthStart(new Date()));
const days = ref<DashboardCalendarDay[]>([]);
const selectedDate = ref("");
const rangeEndDate = ref("");
const rangeComplete = ref(false);
const loading = ref(false);
const error = ref(false);
let controller: AbortController | undefined;
let requestSequence = 0;
let previewController: AbortController | undefined;
let previewSequence = 0;
const injectedBootstrap = inject(adminBootstrapKey);
if (!injectedBootstrap) throw new Error("Admin bootstrapdata ontbreekt.");
const bootstrap = injectedBootstrap;

const mode = ref<CalendarManagementMode>("active-bookings");
const dialogOpen = ref(false);
const action = ref<CalendarDateAction>("block_single");
const endDate = ref("");
const reason = ref("");
const confirmed = ref(false);
const existingBookingsAccepted = ref(false);
const preview = ref<CalendarDateManagementPreview | null>(null);
const previewing = ref(false);
const submitting = ref(false);
const issues = ref<CalendarDateManagementIssue[]>([]);
const reasonMinLength = ref(0);
const reasonMaxLength = ref(0);
const maxPeriodDays = ref(0);
const resultMessage = reactive<{ type: "success" | "error"; text: string }>({ type: "success", text: "" });

const range = computed(() => {
  const first = parseDate(visibleMonth.value);
  const mondayOffset = (first.getUTCDay() + 6) % 7;
  const start = addDays(first, -mondayOffset);
  return { start: ymd(start), end: ymd(addDays(start, 41)) };
});
const monthLabel = computed(() => new Intl.DateTimeFormat("nl-NL", {
  month: "long", year: "numeric", timeZone: "UTC",
}).format(parseDate(visibleMonth.value)));
const selectedDay = computed(() => days.value.find((day) => day.date === selectedDate.value) ?? null);
const today = new Intl.DateTimeFormat("sv-SE", { timeZone: "Europe/Amsterdam" }).format(new Date());
const modeDescription = computed(() => ({
  "active-bookings": "Kies één datum met een actieve boeking. Andere datums blijven zichtbaar, maar kunnen in deze weergave niet worden geselecteerd.",
  "available-management": "Selecteer één of meerdere beschikbare werkdagen. Weekenden, schoolvakanties, verstreken datums en andere geblokkeerde datums zijn niet beschikbaar.",
  "manually-blocked": "Selecteer één of meerdere handmatig geblokkeerde datums om deze vrij te geven. Weekenden en schoolvakanties kunnen hier niet worden vrijgegeven.",
}[mode.value]));
const isPeriodMode = computed(() => mode.value !== "active-bookings");
const selectionEnd = computed(() => isPeriodMode.value ? (rangeEndDate.value || selectedDate.value) : selectedDate.value);
const selectionLabel = computed(() => {
  if (!selectedDate.value) return "Nog geen datum geselecteerd";
  return selectionEnd.value === selectedDate.value
    ? formatDate(selectedDate.value)
    : `${formatDate(selectedDate.value)} t/m ${formatDate(selectionEnd.value)}`;
});
const actionTitle = computed(() => mode.value === "active-bookings"
  ? "Eén datum blokkeren"
  : mode.value === "available-management" ? "Periode blokkeren" : "Periode vrijgeven");
const actionLabel = computed(() => mode.value === "manually-blocked"
  ? "Periode vrijgeven"
  : mode.value === "active-bookings" ? "Datum blokkeren" : "Periode blokkeren");
const selectedRangeDays = computed(() => {
  if (!selectedDate.value || !selectionEnd.value) return [];
  return days.value.filter((day) => day.date >= selectedDate.value && day.date <= selectionEnd.value);
});
const availableWorkdayCount = computed(() => selectedRangeDays.value.filter((day) =>
  day.bookingCount === 0 && day.canBlockManually
).length);
const excludedDayCount = computed(() => selectedRangeDays.value.length - availableWorkdayCount.value);
const manualBlockCount = computed(() => selectedRangeDays.value.filter((day) =>
  day.disabledType === "manual" && day.canReleaseManualBlock
).length);
const canOpenSingle = computed(() => {
  const day = selectedDay.value;
  if (!day) return false;
  if (mode.value === "active-bookings") return day.bookingCount > 0 && day.canBlockManually;
  if (!rangeComplete.value) return false;
  if (mode.value === "available-management") return day.bookingCount === 0 && day.canBlockManually;
  return day.disabledType === "manual" && day.canReleaseManualBlock;
});

function parseDate(value: string): Date {
  const [year, month, day] = value.split("-").map(Number);
  return new Date(Date.UTC(year!, month! - 1, day!));
}
function ymd(date: Date): string {
  return `${date.getUTCFullYear()}-${String(date.getUTCMonth() + 1).padStart(2, "0")}-${String(date.getUTCDate()).padStart(2, "0")}`;
}
function addDays(date: Date, count: number): Date {
  const next = new Date(date); next.setUTCDate(next.getUTCDate() + count); return next;
}
function formatDate(value: string): string {
  return new Intl.DateTimeFormat("nl-NL", {
    weekday: "short", day: "numeric", month: "short", year: "numeric", timeZone: "UTC",
  }).format(parseDate(value));
}
function monthStart(value: Date | string): string {
  const date = typeof value === "string" ? parseDate(value) : new Date(Date.UTC(value.getFullYear(), value.getMonth(), 1));
  date.setUTCDate(1); return ymd(date);
}
function changeMonth(delta: number): void {
  const date = parseDate(visibleMonth.value); date.setUTCMonth(date.getUTCMonth() + delta); visibleMonth.value = monthStart(date);
}
function isEligibleInMode(day: DashboardCalendarDay): boolean {
  if (mode.value === "active-bookings") return day.bookingCount > 0 && day.canBlockManually;
  if (mode.value === "available-management") return day.bookingCount === 0 && day.canBlockManually;
  return day.disabledType === "manual" && day.canReleaseManualBlock;
}
function isInSelectionRange(date: string): boolean {
  return Boolean(selectedDate.value && selectionEnd.value
    && date >= selectedDate.value && date <= selectionEnd.value);
}
function selectCalendarDay(day: DashboardCalendarDay): void {
  if (!isEligibleInMode(day)) return;
  resultMessage.text = "";
  if (!isPeriodMode.value) {
    selectedDate.value = day.date;
    rangeEndDate.value = day.date;
    rangeComplete.value = true;
    return;
  }
  if (!selectedDate.value || rangeComplete.value) {
    selectedDate.value = day.date;
    rangeEndDate.value = day.date;
    rangeComplete.value = false;
    return;
  }
  const start = selectedDate.value < day.date ? selectedDate.value : day.date;
  const end = selectedDate.value < day.date ? day.date : selectedDate.value;
  selectedDate.value = start;
  rangeEndDate.value = end;
  rangeComplete.value = true;
}
function clearSelection(): void {
  selectedDate.value = "";
  rangeEndDate.value = "";
  rangeComplete.value = false;
  resultMessage.text = "";
}
async function load(): Promise<void> {
  const sequence = ++requestSequence;
  controller?.abort();
  controller = new AbortController();
  loading.value = true;
  error.value = false;
  try {
    const result = await fetchDashboardCalendar(range.value.start, range.value.end, controller.signal);
    if (sequence !== requestSequence) return;
    days.value = result.calendar.days;
    reasonMinLength.value = result.calendar.managementPolicy.reasonMinLength;
    reasonMaxLength.value = result.calendar.managementPolicy.reasonMaxLength;
    maxPeriodDays.value = result.calendar.managementPolicy.maxPeriodDays;
    if (!days.value.some((day) => day.date === selectedDate.value)) {
      selectedDate.value = "";
      rangeEndDate.value = "";
      rangeComplete.value = false;
    }
  } catch (caught) {
    if (caught instanceof DOMException && caught.name === "AbortError") return;
    if (sequence === requestSequence) error.value = true;
  } finally {
    if (sequence === requestSequence) loading.value = false;
  }
}
function openManagement(nextAction?: CalendarDateAction): void {
  const day = selectedDay.value;
  if (!day) return;
  // De backend blijft ook release_single accepteren; de agenda biedt vrijgeven bewust als één periodeflow aan.
  const resolved = nextAction ?? (mode.value === "active-bookings"
    ? "block_single"
    : mode.value === "manually-blocked" ? "release_period" : "block_period");
  if (resolved.endsWith("_single") && !canOpenSingle.value) return;
  action.value = resolved;
  endDate.value = resolved.endsWith("_period") ? selectionEnd.value : day.date;
  reason.value = "";
  confirmed.value = false;
  existingBookingsAccepted.value = false;
  preview.value = null;
  issues.value = [];
  resultMessage.text = "";
  dialogOpen.value = true;
}
function closeManagement(): void {
  if (!submitting.value && !previewing.value) dialogOpen.value = false;
}
function invalidatePreview(): void {
  preview.value = null;
  confirmed.value = false;
  existingBookingsAccepted.value = false;
  issues.value = [];
}
function validateBeforePreview(): boolean {
  const next: CalendarDateManagementIssue[] = [];
  if (action.value.startsWith("block_")) {
    const length = reason.value.trim().length;
    if (length < reasonMinLength.value) next.push({ code: "MISSING_BLOCK_REASON", field: "reason", title: "Reden is verplicht", description: `Vul een reden van minimaal ${reasonMinLength.value} tekens in.`, metadata: {} });
    if (length > reasonMaxLength.value) next.push({ code: "BLOCK_REASON_TOO_LONG", field: "reason", title: "Reden is te lang", description: `De reden mag maximaal ${reasonMaxLength.value} tekens bevatten.`, metadata: {} });
  }
  if (action.value.endsWith("_period") && endDate.value && selectedDate.value) {
    const dayCount = Math.round((parseDate(endDate.value).getTime() - parseDate(selectedDate.value).getTime()) / 86400000) + 1;
    if (dayCount > maxPeriodDays.value) next.push({ code: "CALENDAR_DATE_PERIOD_TOO_LONG", field: "endDate", title: "Periode is te lang", description: `Een periode mag maximaal ${maxPeriodDays.value} kalenderdagen bevatten.`, metadata: {} });
  }
  issues.value = next;
  return next.length === 0;
}
async function loadPreview(): Promise<void> {
  if (!selectedDay.value || previewing.value || !validateBeforePreview()) return;
  const sequence = ++previewSequence;
  previewController?.abort();
  previewController = new AbortController();
  previewing.value = true;
  issues.value = [];
  try {
    const response = await previewDashboardCalendarDateManagement({
      startDate: selectedDay.value.date,
      endDate: action.value.endsWith("_period") ? endDate.value : selectedDay.value.date,
      action: action.value,
    }, bootstrap.calendarDateManagementCsrfToken, previewController.signal);
    if (sequence === previewSequence) preview.value = response.data ?? null;
  } catch (caught) {
    if (caught instanceof DOMException && caught.name === "AbortError") return;
    if (caught instanceof CalendarDateManagementApiError) issues.value = caught.result.issues;
    else issues.value = [{ code: "NETWORK_ERROR", field: "dateRange", title: "Verbinding mislukt", description: "De preview kon niet worden geladen.", metadata: {} }];
  } finally {
    if (sequence === previewSequence) previewing.value = false;
  }
}
function validateCommit(): boolean {
  const next: CalendarDateManagementIssue[] = [];
  if (!confirmed.value) next.push({ code: "CONFIRMATION_REQUIRED", field: "confirmed", title: "Bevestiging is verplicht", description: "Bevestig de getoonde bewerking.", metadata: {} });
  if (action.value === "block_single" && (preview.value?.bookingCount ?? 0) > 0 && !existingBookingsAccepted.value) {
    next.push({ code: "EXISTING_BOOKINGS_CONFIRMATION_REQUIRED", field: "existingBookingsAccepted", title: "Extra bevestiging is verplicht", description: "Bevestig dat bestaande boekingen blijven staan.", metadata: {} });
  }
  issues.value = next;
  return next.length === 0;
}
async function submitManagement(): Promise<void> {
  if (!preview.value || submitting.value || !validateCommit()) return;
  submitting.value = true;
  issues.value = [];
  try {
    const response = await manageDashboardCalendarDate({
      expected: {
        startDate: preview.value.startDate,
        endDate: preview.value.endDate,
        previewFingerprint: preview.value.fingerprint,
        activeBookingsFingerprint: preview.value.activeBookingsFingerprint,
      },
      proposed: {
        action: action.value,
        reason: action.value.startsWith("block_") ? reason.value : null,
        confirmed: true,
        existingBookingsAccepted: existingBookingsAccepted.value,
      },
    }, bootstrap.calendarDateManagementCsrfToken);
    dialogOpen.value = false;
    await load();
    resultMessage.type = "success";
    resultMessage.text = response.code === "NO_CHANGE"
      ? "Er waren geen handmatige kalenderwijzigingen nodig."
      : response.code === "NO_ELIGIBLE_DATES"
        ? "De selectie bevatte geen blokkeerbare werkdagen."
        : action.value.startsWith("release_")
          ? "De handmatige blokkade is verwijderd. De beschikbaarheid is opnieuw berekend."
          : `${response.data?.affectedCount ?? 0} datum(s) zijn handmatig geblokkeerd. Bestaande boekingen zijn niet gewijzigd.`;
  } catch (caught) {
    if (caught instanceof CalendarDateManagementApiError) {
      issues.value = caught.result.issues;
      const fresh = "data" in caught.result ? caught.result.data : undefined;
      if (["CALENDAR_DATE_CONFLICT", "EXISTING_BOOKINGS_CONFIRMATION_REQUIRED"].includes(caught.result.code)
        && fresh && "preview" in fresh && fresh.preview) {
        preview.value = fresh.preview;
        confirmed.value = false;
        existingBookingsAccepted.value = false;
        resultMessage.type = "error";
        resultMessage.text = caught.result.code === "EXISTING_BOOKINGS_CONFIRMATION_REQUIRED"
          ? "De actieve boekingen zijn gewijzigd. Controleer de vernieuwde boekingenlijst en bevestig opnieuw."
          : "De selectie is inmiddels gewijzigd. Controleer de vernieuwde preview en bevestig opnieuw.";
      }
    } else {
      issues.value = [{ code: "NETWORK_ERROR", field: "dateRange", title: "Verbinding mislukt", description: "De kalenderwijziging kon niet worden opgeslagen.", metadata: {} }];
    }
  } finally {
    submitting.value = false;
  }
}

watch(visibleMonth, () => void load(), { immediate: true });
watch(mode, () => {
  selectedDate.value = "";
  rangeEndDate.value = "";
  rangeComplete.value = false;
  resultMessage.text = "";
});
onBeforeUnmount(() => { controller?.abort(); previewController?.abort(); });
</script>

<template>
  <section class="admin-calendar-page" aria-labelledby="calendar-title">
    <header class="admin-calendar-page__intro">
      <p class="admin-eyebrow">Planning</p>
      <h1 id="calendar-title">Agenda</h1>
      <p>Bekijk beschikbaarheid, capaciteit en aanvragen per datum en beheer handmatige blokkades.</p>
    </header>

    <AdminInlineNotice v-if="resultMessage.text" :variant="resultMessage.type" :title="resultMessage.type === 'success' ? 'Gelukt' : 'Controle nodig'">
      <p aria-live="polite">{{ resultMessage.text }}</p>
    </AdminInlineNotice>

    <section class="admin-card admin-calendar-modes" aria-labelledby="calendar-management-mode-title">
      <div class="admin-calendar-modes__header">
        <h2 id="calendar-management-mode-title">Agenda weergave</h2>
        <AdminCollapsibleHelp label="Toon uitleg over de gekozen agendaweergave">
          <p>{{ modeDescription }}</p>
        </AdminCollapsibleHelp>
      </div>
      <DashboardCalendarViewSelector v-model="mode" />
      <div class="admin-calendar-actions">
        <div class="admin-calendar-actions__grid">
          <DashboardCalendarActionCard
            :title="actionTitle"
            :action-label="actionLabel"
            :disabled="!canOpenSingle"
            @action="openManagement()"
          >
            <p><strong>{{ selectionLabel }}</strong></p>
            <template v-if="selectedDay">
              <p v-if="mode === 'active-bookings'">{{ selectedDay.bookingCount }} actieve boeking{{ selectedDay.bookingCount === 1 ? '' : 'en' }}, {{ selectedDay.studentCount }} leerlingen.</p>
              <p v-else-if="!rangeComplete">Klik dezelfde datum of een einddatum om de periode af te ronden.</p>
              <p v-else-if="mode === 'available-management'">{{ availableWorkdayCount }} beschikbare werkdag{{ availableWorkdayCount === 1 ? '' : 'en' }} · {{ excludedDayCount }} uitgesloten dag{{ excludedDayCount === 1 ? '' : 'en' }}.</p>
              <p v-else>{{ manualBlockCount }} handmatige blokkade{{ manualBlockCount === 1 ? '' : 's' }} binnen de selectie.</p>
            </template>
            <p v-else>Kies een relevante datum rechtstreeks in de agenda.</p>
            <button v-if="selectedDay" type="button" class="admin-calendar-selection-clear" @click="clearSelection">Selectie wissen</button>
          </DashboardCalendarActionCard>
        </div>
      </div>
    </section>

    <div class="admin-calendar-layout">
      <section class="admin-card admin-calendar" :aria-busy="loading">
        <header class="admin-calendar__header">
          <AdminButton variant="secondary" aria-label="Vorige maand" @click="changeMonth(-1)"><ChevronLeft :size="18" aria-hidden="true" /></AdminButton>
          <h2>{{ monthLabel }}</h2>
          <AdminButton variant="secondary" aria-label="Volgende maand" @click="changeMonth(1)"><ChevronRight :size="18" aria-hidden="true" /></AdminButton>
        </header>
        <div class="admin-calendar__weekdays" aria-hidden="true">
          <span v-for="weekday in ['Ma', 'Di', 'Wo', 'Do', 'Vr', 'Za', 'Zo']" :key="weekday">{{ weekday }}</span>
        </div>
        <div v-if="error" class="admin-calendar__message" role="alert">
          <p>De agenda kon niet worden geladen.</p><AdminButton @click="load">Opnieuw proberen</AdminButton>
        </div>
        <p v-else-if="loading && !days.length" class="admin-calendar__message" role="status">Agenda laden…</p>
        <div v-else class="admin-calendar__grid">
          <DashboardCalendarDayCell
            v-for="day in days" :key="day.date" :day="day"
            :outside-month="monthStart(day.date) !== visibleMonth"
            :selected="day.date === selectedDate || day.date === selectionEnd" :today="day.date === today"
            :management-eligible="isEligibleInMode(day)"
            :range-start="day.date === selectedDate"
            :range-end="day.date === selectionEnd"
            :in-range="isInSelectionRange(day.date)"
            @select="selectCalendarDay"
          />
        </div>
        <div class="admin-calendar__legend" aria-label="Legenda">
          <span class="is-available">Beschikbaar</span><span class="is-limited">Beperkt</span><span class="is-full">Vol</span>
          <span class="is-manual">Handmatig geblokkeerd</span><span class="is-school-vacation">Schoolvakantie</span>
          <span class="is-weekend">Weekend</span><span class="is-past">Verleden</span>
        </div>
      </section>
      <DashboardCalendarDayDetail v-if="selectedDay" :day="selectedDay" />
    </div>

    <DashboardCalendarDateManagementDialog
      :open="dialogOpen" :action="action" :start-date="selectedDay?.date ?? ''" :end-date="endDate"
      :reason="reason" :confirmed="confirmed" :existing-bookings-accepted="existingBookingsAccepted"
      :preview="preview" :previewing="previewing" :submitting="submitting" :issues="issues"
      @close="closeManagement" @preview="loadPreview" @submit="submitManagement"
      @update:end-date="endDate = $event; invalidatePreview()"
      @update:reason="reason = $event; invalidatePreview()"
      @update:confirmed="confirmed = $event"
      @update:existing-bookings-accepted="existingBookingsAccepted = $event"
    />
  </section>
</template>

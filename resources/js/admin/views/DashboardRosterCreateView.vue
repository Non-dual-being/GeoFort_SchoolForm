<script setup lang="ts">
import {
  computed,
  inject,
  onBeforeUnmount,
  reactive,
  ref,
  watch,
} from "vue";
import { RouterLink, useRoute, useRouter } from "vue-router";
import { ArrowDown, ArrowUp, Plus, X } from "lucide-vue-next";

import AdminButton from "../components/form/AdminButton.vue";
import AdminDateField from "../components/form/AdminDateField.vue";
import AdminSelect from "../components/form/AdminSelect.vue";
import AdminWizardStepper, { type AdminWizardStep } from "../components/wizard/AdminWizardStepper.vue";
import {
  createDashboardManualRoster,
  createDashboardRoster,
  fetchDashboardRoster,
  fetchDashboardRosters,
  saveDashboardManualRosterProgram,
} from "../services/dashboardRosterApi";
import { fetchDashboardBookings } from "../services/dashboardBookingsApi";
import { fetchBookingProgramConfigValues } from "../../services/api/bookingProgramConfigApi";
import { fetchDisabledDates } from "../../services/api/bookingDisabledDatesApi";
import {
  createAgendaDayInfo,
  DEFAULT_AGENDA_CAPACITY,
  getAgendaInfoDescription,
  getAgendaInfoTitle,
} from "../../config/booking/calendar/helpers";
import { adminBootstrapKey } from "../types/admin";
import type {
  DashboardBookingFilters,
  DashboardBookingListItem,
  DashboardBookingPagination,
} from "../types/bookings";
import type {
  DashboardRosterManualCreateRequest,
  DashboardRosterManualProgramSaveRequest,
  DashboardRosterPlan,
  RosterManualTimeBlock,
} from "../types/roster";
import type {
  BookingProgramConfigData,
  ProgramKey,
  SchoolSectorKey,
} from "../../types/booking/BookingProgramConfigTypes";
import type { fullDatesInfo } from "../../types/booking/BookingDateType";

type SourceMode = "booking" | "manual" | null;
type BookingPickerPurpose = "booking_locked" | "custom";
type ManualWizardStep = "basis" | "program" | "timeline";

const props = defineProps<{
  embedded?: boolean;
  fixedVisitDate?: string;
  excludedBookingId?: number;
  schoolNumber?: number;
  resumePlanId?: number | null;
  twoSchoolDaysOnly?: boolean;
}>();
const emit = defineEmits<{
  complete: [plan: DashboardRosterPlan];
  progress: [plan: DashboardRosterPlan];
}>();

async function finishSchool(plan: DashboardRosterPlan): Promise<void> {
  if (props.embedded) {
    emit("complete", plan);
  } else {
    await router.push({ name: "roster-detail", params: { id: plan.id } });
  }
}

const route = useRoute();
const router = useRouter();
const bootstrap = inject(adminBootstrapKey);

if (!bootstrap) {
  throw new Error("Admin bootstrapdata ontbreekt.");
}

const rosterPlanCsrfToken = bootstrap.rosterPlanCsrfToken;

const sourceMode = ref<SourceMode>(null);
const bookingPickerPurpose = ref<BookingPickerPurpose>("booking_locked");
const manualLinkedBooking = ref<DashboardBookingListItem | null>(null);
const bookingItems = ref<DashboardBookingListItem[]>([]);
const bookingPagination = ref<DashboardBookingPagination>({
  currentPage: 1,
  perPage: 20,
  totalItems: 0,
  totalPages: 0,
  from: 0,
  to: 0,
});
const bookingFilters = reactive<DashboardBookingFilters>({
  search: "",
  status: "",
  sector: "",
  program: "",
  module: "",
  dateFrom: "",
  dateTo: "",
  page: 1,
  sort: "desc",
});
const loadingBookings = ref(false);
const openingBookingId = ref<number | null>(null);
const error = ref<string | null>(null);

const manualConfig = ref<BookingProgramConfigData | null>(null);
const manualDatesInfo = ref<fullDatesInfo | null>(null);
const loadingManualConfig = ref(false);
const creatingManual = ref(false);
const savingManualProgram = ref(false);
const savingManualTimeline = ref(false);
const manualGroupCountTouched = ref(false);
const manualStep = ref<ManualWizardStep>("basis");
const manualPlan = ref<DashboardRosterPlan | null>(null);
const loadingExistingPlan = ref(false);
let existingPlanController: AbortController | undefined;

const editingPlanId = computed<number | null>(() => {
  if (props.resumePlanId) return props.resumePlanId;
  const value = Array.isArray(route.query.plan) ? route.query.plan[0] : route.query.plan;
  const id = Number(value);

  return typeof value === "string"
    && /^\d+$/.test(value)
    && Number.isSafeInteger(id)
    && id > 0
    ? id
    : null;
});

const editingExistingPlan = computed(() =>
  editingPlanId.value !== null
  && manualPlan.value?.id === editingPlanId.value,
);
const manualModuleKeys = ref<string[]>([]);
const manualRounds = ref<RosterManualTimeBlock[]>([]);
const manualProgramSaved = ref(false);

const MANUAL_MIN_MODULE_COUNT = 2;
const MANUAL_MAX_MODULE_COUNT = 5;
const MANUAL_GROUPS_PER_MODULE = 2;
const MANUAL_MAX_GROUP_COUNT = MANUAL_MAX_MODULE_COUNT * MANUAL_GROUPS_PER_MODULE;
const allowWeekdayOverride = ref(false);
const manualForm = reactive({
  schoolName: "",
  city: "",
  visitDate: props.fixedVisitDate ?? "",
  sector: "" as SchoolSectorKey | "",
  program: "" as ProgramKey | "",
  studentCount: 40,
  groupCount: 3,
});

const manualSteps = computed<readonly AdminWizardStep[]>(() => [
  {
    label: "Basis",
    state: manualStep.value === "basis"
      ? "current"
      : manualPlan.value
        ? "completed"
        : "upcoming",
  },
  {
    label: "Programma",
    state: manualPlan.value === null
      ? "upcoming"
      : manualStep.value === "program"
        ? "current"
        : manualProgramSaved.value
          ? "completed"
          : "available",
  },
  {
    label: "Dagindeling",
    state: !manualProgramSaved.value
      ? "upcoming"
      : manualStep.value === "timeline"
        ? "current"
        : "available",
  },
]);

const manualSectorOptions = computed(() =>
  (manualConfig.value?.schoolTypes ?? []).map((sector) => ({
    value: sector.value,
    label: sector.label,
  })),
);

const manualProgramOptions = computed(() =>
  Object.entries(manualConfig.value?.programs ?? {})
    .filter(([, program]) =>
      manualForm.sector !== ""
      && program.allowedSchoolTypes.includes(manualForm.sector),
    )
    .map(([key, program]) => ({
      value: key,
      label: program.label,
    })),
);

const selectedProgram = computed(() => {
  if (!manualConfig.value || !manualForm.program) return null;
  return manualConfig.value.programs[manualForm.program] ?? null;
});

const manualModuleConfig = computed(() => {
  const config = manualConfig.value;
  const sector = manualForm.sector;
  const program = manualForm.program;

  if (!config || !sector || !program) return null;
  return config.modules[sector]?.[program] ?? null;
});

const manualStandardModuleKeys = computed(() =>
  manualModuleConfig.value?.standaard ?? [],
);

const manualChoiceModuleKeys = computed(() =>
  manualModuleConfig.value?.keuze ?? [],
);

const manualAllowedModuleKeys = computed(() => [
  ...manualStandardModuleKeys.value,
  ...manualChoiceModuleKeys.value,
]);

const manualAvailableModuleKeys = computed(() =>
  manualAllowedModuleKeys.value.filter(
    (moduleKey) => !manualModuleKeys.value.includes(moduleKey),
  ),
);

const manualModuleCapacity = computed(
  () => manualModuleKeys.value.length * MANUAL_GROUPS_PER_MODULE,
);

const manualRequiredModuleCount = computed(
  () => Math.max(
    MANUAL_MIN_MODULE_COUNT,
    Math.ceil(Number(manualForm.groupCount) / MANUAL_GROUPS_PER_MODULE),
  ),
);

const manualLargestGroup = computed(() => {
  const groups = Number(manualForm.groupCount);
  return Number.isInteger(groups) && groups > 0
    ? Math.ceil(Number(manualForm.studentCount) / groups) : 0;
});

const manualProgramValid = computed(() =>
  manualPlan.value !== null
  && manualModuleKeys.value.length >= MANUAL_MIN_MODULE_COUNT
  && manualModuleKeys.value.length <= MANUAL_MAX_MODULE_COUNT
  && Number.isInteger(Number(manualForm.groupCount))
  && Number(manualForm.groupCount) >= 1
  && Number(manualForm.groupCount) <= manualModuleCapacity.value
  && manualModuleKeys.value.every((moduleKey) =>
    manualAllowedModuleKeys.value.includes(moduleKey),
  ),
);

const manualTimeBlockTypeOptions: ReadonlyArray<{
  value: RosterManualTimeBlock["type"];
  label: string;
}> = [
  { value: "welcome", label: "Ontvangst" },
  { value: "round", label: "Lesronde" },
  { value: "break", label: "Pauze / wissel" },
  { value: "lunch", label: "Lunch" },
  { value: "departure", label: "Afronding / vertrek" },
];

const manualExpectedTimelineTypes = computed<RosterManualTimeBlock["type"][]>(() => {
  const moduleCount = manualModuleKeys.value.length;

  if (moduleCount === 2) return ["welcome", "round", "round", "departure"];
  if (moduleCount === 3) return ["welcome", "round", "break", "round", "round", "departure"];
  if (moduleCount === 4) return ["welcome", "round", "round", manualPlan.value?.education.programVersion === "day-four-2027" ? "lunch" : "break", "round", "round", "departure"];

  if (moduleCount === 5) {
    return manualForm.program === "ochtend"
      ? ["welcome", "round", "round", "break", "round", "round", "round", "departure"]
      : ["welcome", "round", "break", "round", "round", "lunch", "round", "round", "departure"];
  }

  return [];
});

function manualTimeBlockTypeLabel(type: RosterManualTimeBlock["type"]): string {
  return manualTimeBlockTypeOptions.find((option) => option.value === type)?.label ?? type;
}

const manualTimelineValid = computed(() => {
  if (manualPlan.value === null || manualRounds.value.length === 0) return false;

  const expectedTypes = manualExpectedTimelineTypes.value;
  if (expectedTypes.length === 0 || expectedTypes.length !== manualRounds.value.length) {
    return false;
  }

  let previousEnd = "";

  for (const [index, block] of manualRounds.value.entries()) {
    const label = block.label.trim();

    if (
      block.type !== expectedTypes[index]
      || label === ""
      || !/^\d{2}:\d{2}$/.test(block.startTime)
      || !/^\d{2}:\d{2}$/.test(block.endTime)
      || block.startTime >= block.endTime
      || (previousEnd !== "" && block.startTime < previousEnd)
    ) {
      return false;
    }

    previousEnd = block.endTime;
  }

  return true;
});

function cloneManualRounds(
  rounds: readonly RosterManualTimeBlock[] | undefined,
): RosterManualTimeBlock[] {
  return (rounds ?? []).map((block) => ({ ...block }));
}

function manualModuleLabel(moduleKey: string): string {
  return manualConfig.value?.moduleLabels[moduleKey] ?? moduleKey;
}

function manualModuleKind(moduleKey: string): string {
  return manualStandardModuleKeys.value.includes(moduleKey)
    ? "Standaard"
    : "Keuzemodule";
}

function addManualModule(moduleKey: string): void {
  if (
    !manualAllowedModuleKeys.value.includes(moduleKey)
    || manualModuleKeys.value.includes(moduleKey)
    || manualModuleKeys.value.length >= MANUAL_MAX_MODULE_COUNT
  ) return;

  manualModuleKeys.value = [...manualModuleKeys.value, moduleKey];
  manualProgramSaved.value = false;
}

function removeManualModule(moduleKey: string): void {
  manualModuleKeys.value = manualModuleKeys.value.filter((key) => key !== moduleKey);
  manualProgramSaved.value = false;
}

function moveManualModule(index: number, direction: -1 | 1): void {
  const targetIndex = index + direction;
  if (targetIndex < 0 || targetIndex >= manualModuleKeys.value.length) return;

  const next = [...manualModuleKeys.value];
  const current = next[index];
  const target = next[targetIndex];
  if (current === undefined || target === undefined) return;

  next[index] = target;
  next[targetIndex] = current;
  manualModuleKeys.value = next;
  manualProgramSaved.value = false;
}

function selectManualStep(step: number): void {
  if (manualPlan.value === null) return;
  if (step === 1 && !editingExistingPlan.value) manualStep.value = "basis";
  if (step === 2) manualStep.value = "program";
  if (step === 3 && manualProgramSaved.value) manualStep.value = "timeline";
}

function isoWeekday(value: string): number | null {
  if (!/^\d{4}-\d{2}-\d{2}$/.test(value)) return null;

  const date = new Date(`${value}T00:00:00Z`);
  if (Number.isNaN(date.getTime())) return null;

  const day = date.getUTCDay();
  return day === 0 ? 7 : day;
}

function eachDate(start: string, end: string): string[] {
  if (!start || !end) return [];

  const result: string[] = [];
  const cursor = new Date(`${start}T00:00:00Z`);
  const maximum = new Date(`${end}T00:00:00Z`);

  if (Number.isNaN(cursor.getTime()) || Number.isNaN(maximum.getTime())) {
    return result;
  }

  while (cursor <= maximum) {
    result.push(cursor.toISOString().slice(0, 10));
    cursor.setUTCDate(cursor.getUTCDate() + 1);
  }

  return result;
}

const manualDisabledDateReasons = computed<
  Record<string, { title: string; description: string }>
>(() => {
  const info = manualDatesInfo.value;
  if (!info) return {};

  const disabledDates = new Set(info.disabledDates ?? []);
  const disabledDetailByDate = new Map(
    (info.details ?? []).map((detail) => [detail.datum, detail]),
  );
  const availabilityByDate = new Map(
    (info.availabilityDetails ?? []).map((detail) => [detail.datum, detail]),
  );
  const capacity = info.capacity ?? DEFAULT_AGENDA_CAPACITY;
  const reasons: Record<string, { title: string; description: string }> = {};

  for (const date of eachDate(info.minDate, info.maxDate)) {
    const [year, month, day] = date.split("-").map(Number);
    if (!year || !month || !day) continue;

    const dateObject = new Date(year, month - 1, day);
    const dayInfo = createAgendaDayInfo({
      date,
      dateObj: dateObject,
      disabledByDateList: disabledDates.has(date),
      disabledDetail: disabledDetailByDate.get(date),
      availabilityDetail: availabilityByDate.get(date),
      capacity,
    });

    if (dayInfo.status === "not_bookable") {
      reasons[date] = {
        title: getAgendaInfoTitle(dayInfo),
        description: getAgendaInfoDescription(dayInfo),
      };
      continue;
    }

    const weekday = isoWeekday(date);
    const program = selectedProgram.value;

    if (
      weekday !== null
      && program
      && !(manualForm.program === "ochtend" && allowWeekdayOverride.value)
      && !program.allowedWeekdays.some((allowed) => Number(allowed) === weekday)
    ) {
      reasons[date] = {
        title: "Programma niet beschikbaar",
        description:
          program.description[0]
          ?? `${program.label} kan op deze dag niet worden ingepland.`,
      };
    }
  }

  return reasons;
});

const manualDisabledDates = computed(() =>
  Object.keys(manualDisabledDateReasons.value),
);

const selectedManualDateIsBookable = computed(() => {
  if (!manualForm.visitDate || !manualDatesInfo.value) return false;

  const { minDate, maxDate } = manualDatesInfo.value;
  const isLinkedBookingDate =
    manualLinkedBooking.value?.visitDate === manualForm.visitDate;

  return manualForm.visitDate >= minDate
    && manualForm.visitDate <= maxDate
    && (
      isLinkedBookingDate
      || !manualDisabledDates.value.includes(manualForm.visitDate)
    );
});

const suggestedGroupCount = computed<number | null>(() => {
  const students = Number(manualForm.studentCount);

  if (!Number.isInteger(students) || students < 1) return null;

  if (
    manualForm.sector === "primairOnderwijs"
    && manualForm.program === "ochtend"
  ) {
    if (students === 40) return 2;
    if (students >= 41 && students <= 50) return 3;
    if (students >= 51 && students <= 65) return 4;
    if (students >= 66 && students <= 80) return 5;
    return null;
  }

  if (manualForm.program === "dag") {
    if (students >= 40 && students <= 50) return 3;
    if (students >= 51 && students <= 65) return 4;
    if (students >= 66 && students <= 80) return 5;
    if (students >= 81 && students <= 100) return 6;
    if (students >= 101 && students <= 120) return 7;
    if (students >= 121 && students <= 130) return 8;
    if (students >= 131 && students <= 150) return 9;
    if (students >= 151 && students <= 160) return 10;
  }

  return null;
});

const manualBasisValid = computed(() =>
  manualForm.schoolName.trim() !== ""
  && manualForm.visitDate !== ""
  && selectedManualDateIsBookable.value
  && (!props.fixedVisitDate || manualForm.visitDate === props.fixedVisitDate)
  && manualForm.sector !== ""
  && manualForm.program !== ""
  && manualProgramOptions.value.some((option) => option.value === manualForm.program)
  && Number.isInteger(Number(manualForm.studentCount))
  && Number(manualForm.studentCount) >= 1
  && Number.isInteger(Number(manualForm.groupCount))
  && Number(manualForm.groupCount) >= 1
  && Number(manualForm.groupCount) <= MANUAL_MAX_GROUP_COUNT,
);

let searchTimer: ReturnType<typeof setTimeout> | undefined;
let controller: AbortController | undefined;

function formatDate(value: string): string {
  const [year, month, day] = value.split("-");
  return year && month && day ? `${day}-${month}-${year}` : value;
}

async function chooseSource(source: Exclude<SourceMode, null>): Promise<void> {
  sourceMode.value = source;
  error.value = null;

  if (source === "booking") {
    bookingPickerPurpose.value = "booking_locked";
    await loadBookings(1);
  }

  if (source === "manual" && manualConfig.value === null) {
    await loadManualConfig();
  }
}

async function openManualBookingPicker(): Promise<void> {
  if (manualPlan.value !== null) return;

  bookingPickerPurpose.value = "custom";
  sourceMode.value = "booking";
  error.value = null;

  await loadBookings(1);
}

async function useBookingForManual(item: DashboardBookingListItem): Promise<void> {
  if (props.twoSchoolDaysOnly) {
    try {
      const existing = (await fetchDashboardRosters()).items.find((plan) => plan.bookingId === item.id);
      if (existing) {
        await openFromBooking(item);
        return;
      }
    } catch (requestError) {
      error.value = requestError instanceof Error ? requestError.message : "Bestaand rooster controleren is niet gelukt.";
      return;
    }
  }
  if (item.id === props.excludedBookingId || (props.fixedVisitDate && item.visitDate !== props.fixedVisitDate)) {
    error.value = "Kies een andere school op dezelfde bezoekdatum.";
    return;
  }
  manualLinkedBooking.value = item;
  manualGroupCountTouched.value = false;

  manualForm.schoolName = item.schoolName;
  manualForm.city = item.city;
  manualForm.visitDate = item.visitDate;
  manualForm.sector = item.sectorKey as SchoolSectorKey;
  manualForm.program = item.programKey as ProgramKey;
  manualForm.studentCount = item.studentCount ?? 40;

  const suggestion = suggestedGroupCount.value;
  if (suggestion !== null) manualForm.groupCount = suggestion;

  sourceMode.value = "manual";
  error.value = null;
}

function clearManualBookingLink(): void {
  manualLinkedBooking.value = null;
  bookingPickerPurpose.value = "booking_locked";
}

async function loadManualConfig(): Promise<void> {
  loadingManualConfig.value = true;
  error.value = null;

  try {
    const [config, dates] = await Promise.all([
      fetchBookingProgramConfigValues(),
      fetchDisabledDates(),
    ]);

    manualConfig.value = config;
    manualDatesInfo.value = dates;

    if (!manualForm.sector) {
      manualForm.sector = config.schoolTypes[0]?.value ?? "";
    }

    const options = manualProgramOptions.value;
    if (!options.some((option) => option.value === manualForm.program)) {
      manualForm.program = (options[0]?.value ?? "") as ProgramKey | "";
    }
  } catch (requestError) {
    error.value = requestError instanceof Error
      ? requestError.message
      : "De roosterconfiguratie kon niet worden geladen.";
  } finally {
    loadingManualConfig.value = false;
  }
}

function hydrateExistingCustomPlan(plan: DashboardRosterPlan): void {
  if (plan.planningMode !== "custom") {
    throw new Error("Dit rooster is nog geen maatwerkrooster.");
  }

  if (plan.status !== "concept") {
    throw new Error("Alleen een conceptrooster kan via de maatwerkwizard worden aangepast.");
  }

  if (plan.planning.sessions.length > 0) {
    throw new Error(
      "Dit rooster bevat al sessies. Verwijder die eerst voordat je Programma of Dagindeling wijzigt.",
    );
  }

  if (
    plan.education.sector === null
    || plan.education.program === null
    || plan.education.studentCount === null
  ) {
    throw new Error("De basisgegevens van dit maatwerkrooster zijn niet compleet.");
  }

  sourceMode.value = "manual";
  bookingPickerPurpose.value = "custom";
  manualLinkedBooking.value = null;

  manualPlan.value = plan;
  manualForm.schoolName = plan.school.name;
  manualForm.city = plan.school.city;
  manualForm.visitDate = plan.visitDate;
  manualForm.sector = plan.education.sector as SchoolSectorKey;
  manualForm.program = plan.education.program as ProgramKey;
  allowWeekdayOverride.value = manualForm.program === "ochtend" && isoWeekday(plan.visitDate) !== 3;
  manualForm.studentCount = plan.education.studentCount;
  manualForm.groupCount = Math.max(1, plan.groups.length);

  manualGroupCountTouched.value = true;
  manualModuleKeys.value = plan.planning.modules.map((module) => module.key);
  manualRounds.value = cloneManualRounds(plan.planning.rounds);
  manualProgramSaved.value = false;
  manualStep.value = "program";
}

async function loadExistingCustomPlan(): Promise<void> {
  const id = editingPlanId.value;
  if (id === null || loadingExistingPlan.value) return;

  existingPlanController?.abort();
  const requestController = new AbortController();
  existingPlanController = requestController;

  loadingExistingPlan.value = true;
  error.value = null;
  sourceMode.value = "manual";

  try {
    const result = await fetchDashboardRoster(id, requestController.signal);
    if (!props.embedded && result.plan.jointPlanning) {
      const pair = result.plan.jointPlanning;
      await router.replace({ name: "roster-create", query: { schools: "2", school1: String(pair.planIds[0]), school2: String(pair.planIds[1]), wizard: String(result.plan.id) } });
      return;
    }
    hydrateExistingCustomPlan(result.plan);

    if (manualConfig.value === null) {
      await loadManualConfig();
    }
  } catch (requestError) {
    if (requestError instanceof DOMException && requestError.name === "AbortError") return;

    error.value = requestError instanceof Error
      ? requestError.message
      : "Het bestaande maatwerkrooster kon niet worden geopend.";
  } finally {
    if (!requestController.signal.aborted) loadingExistingPlan.value = false;
  }
}

async function loadBookings(page = bookingFilters.page): Promise<void> {
  controller?.abort();
  const requestController = new AbortController();
  controller = requestController;

  loadingBookings.value = true;
  error.value = null;
  bookingFilters.page = page;

  try {
    const result = await fetchDashboardBookings(
      {
        ...bookingFilters,
        withoutRoster: true,
        twoSchoolDaysOnly: props.twoSchoolDaysOnly,
        ...(props.fixedVisitDate ? { dateFrom: props.fixedVisitDate, dateTo: props.fixedVisitDate } : {}),
      },
      requestController.signal,
    );
    bookingItems.value = result.items.filter((item) => item.id !== props.excludedBookingId);
    bookingPagination.value = result.pagination;
  } catch (requestError) {
    if (requestError instanceof DOMException && requestError.name === "AbortError") return;
    error.value = "De aanvragen konden niet worden geladen.";
  } finally {
    if (!requestController.signal.aborted) loadingBookings.value = false;
  }
}

function scheduleBookingSearch(): void {
  clearTimeout(searchTimer);
  searchTimer = setTimeout(() => {
    void loadBookings(1);
  }, 350);
}

async function openFromBooking(item: DashboardBookingListItem): Promise<void> {
  if (openingBookingId.value !== null) return;

  openingBookingId.value = item.id;
  error.value = null;
  if (item.id === props.excludedBookingId || (props.fixedVisitDate && item.visitDate !== props.fixedVisitDate)) {
    error.value = "Kies een andere school op dezelfde bezoekdatum.";
    openingBookingId.value = null;
    return;
  }

  try {
    const result = await createDashboardRoster(
      item.id,
      rosterPlanCsrfToken,
    );

    await finishSchool(result.plan);
  } catch (requestError) {
    error.value = requestError instanceof Error
      ? requestError.message
      : "Het rooster kon niet worden aangemaakt of geopend.";
  } finally {
    openingBookingId.value = null;
  }
}

function syncManualProgramToSector(): void {
  const options = manualProgramOptions.value;

  if (!options.some((option) => option.value === manualForm.program)) {
    manualForm.program = (options[0]?.value ?? "") as ProgramKey | "";
  }
}

function useSuggestedGroupCount(): void {
  if (suggestedGroupCount.value === null) return;
  manualForm.groupCount = suggestedGroupCount.value;
  manualGroupCountTouched.value = false;
}

async function createManualRoster(): Promise<void> {
  if (creatingManual.value || !manualBasisValid.value) return;

  creatingManual.value = true;
  error.value = null;

  const request: DashboardRosterManualCreateRequest = {
    bookingId: manualLinkedBooking.value?.id ?? null,
    schoolName: manualForm.schoolName.trim(),
    city: manualForm.city.trim(),
    visitDate: manualForm.visitDate,
    sector: manualForm.sector,
    program: manualForm.program,
    allowWeekdayOverride: manualForm.program === "ochtend" && allowWeekdayOverride.value,
    studentCount: Number(manualForm.studentCount),
    groupCount: Number(manualForm.groupCount),
  };

  try {
    const result = await createDashboardManualRoster(
      request,
      rosterPlanCsrfToken,
    );

    manualPlan.value = result.plan;
    manualModuleKeys.value = result.plan.planning.modules.map((module) => module.key);
    manualRounds.value = cloneManualRounds(result.plan.planning.rounds);
    manualProgramSaved.value = false;

    if (manualModuleKeys.value.length === 0) {
      manualModuleKeys.value = [...manualStandardModuleKeys.value];
    }

    manualStep.value = "program";
    if (props.embedded) emit("progress", result.plan);
  } catch (requestError) {
    error.value = requestError instanceof Error
      ? requestError.message
      : "Het handmatige rooster kon niet worden aangemaakt.";
  } finally {
    creatingManual.value = false;
  }
}

async function saveManualProgram(): Promise<void> {
  if (
    savingManualProgram.value
    || !manualProgramValid.value
    || manualPlan.value === null
  ) return;

  savingManualProgram.value = true;
  error.value = null;

  const request: DashboardRosterManualProgramSaveRequest = {
    planId: manualPlan.value.id,
    expectedRevision: manualPlan.value.revision,
    moduleKeys: [...manualModuleKeys.value],
    groupCount: Number(manualForm.groupCount),
  };

  try {
    const result = await saveDashboardManualRosterProgram(
      request,
      rosterPlanCsrfToken,
    );

    manualPlan.value = result.plan;
    manualRounds.value = cloneManualRounds(result.plan.planning.rounds);
    manualProgramSaved.value = true;
    manualStep.value = "timeline";
  } catch (requestError) {
    error.value = requestError instanceof Error
      ? requestError.message
      : "Het programma kon niet worden opgeslagen.";
  } finally {
    savingManualProgram.value = false;
  }
}

async function saveManualTimeline(): Promise<void> {
  if (
    savingManualTimeline.value
    || !manualTimelineValid.value
    || manualPlan.value === null
  ) return;

  savingManualTimeline.value = true;
  error.value = null;

  const request: DashboardRosterManualProgramSaveRequest = {
    planId: manualPlan.value.id,
    expectedRevision: manualPlan.value.revision,
    rounds: manualRounds.value.map((block) => ({
      ...block,
      label: block.label.trim(),
    })),
  };

  try {
    const result = await saveDashboardManualRosterProgram(
      request,
      rosterPlanCsrfToken,
    );

    manualPlan.value = result.plan;
    manualRounds.value = cloneManualRounds(result.plan.planning.rounds);

    await finishSchool(result.plan);
  } catch (requestError) {
    error.value = requestError instanceof Error
      ? requestError.message
      : "De dagindeling kon niet worden opgeslagen.";
  } finally {
    savingManualTimeline.value = false;
  }
}

watch(
  suggestedGroupCount,
  (suggestion) => {
    if (!manualGroupCountTouched.value && suggestion !== null) {
      manualForm.groupCount = suggestion;
    }
  },
);

watch(
  () => manualForm.sector,
  () => syncManualProgramToSector(),
);

watch(
  manualDisabledDates,
  () => {
    if (
      manualForm.visitDate
      && !selectedManualDateIsBookable.value
    ) {
      manualForm.visitDate = "";
    }
  },
);

function resetSource(): void {
  if (manualPlan.value !== null) return;
  sourceMode.value = null;
  bookingPickerPurpose.value = "booking_locked";
  manualLinkedBooking.value = null;
  error.value = null;
  manualStep.value = "basis";
  manualModuleKeys.value = [];
}

watch(
  () => [route.query.plan, props.resumePlanId],
  () => {
    if (editingPlanId.value !== null && manualPlan.value?.id !== editingPlanId.value) void loadExistingCustomPlan();
  },
  { immediate: true },
);

onBeforeUnmount(() => {
  clearTimeout(searchTimer);
  controller?.abort();
  existingPlanController?.abort();
});
</script>

<template>
  <section class="admin-roster-create" :class="{ 'admin-roster-create--embedded': embedded }" aria-labelledby="roster-create-title">
    <RouterLink v-if="!embedded" class="admin-booking-detail__back" :to="{ name: 'rosters' }">
      &larr; Terug naar roosters
    </RouterLink>

    <header class="admin-roster-create__header">
      <p class="admin-eyebrow">{{ embedded ? `School ${schoolNumber ?? 1}` : "Onderwijsplanning" }}</p>
      <component :is="embedded ? 'h2' : 'h1'" id="roster-create-title">
        {{ embedded ? `School ${schoolNumber ?? 1} instellen` : editingExistingPlan ? "Maatwerkrooster bewerken" : "Rooster maken" }}
      </component>
      <p v-if="editingExistingPlan">
        Je bewerkt rooster #{{ manualPlan?.id }}. Het bestaande plan-ID en de eventuele
        koppeling met de aanvraag blijven behouden.
      </p>
      <p v-else-if="embedded">Kies een aanvraag of stel zelf het programma in. Een bestaand rooster wordt hergebruikt.</p>
      <p v-else>
        Kies één keer hoe dit rooster ontstaat. Die bron blijft daarna onderdeel
        van het rooster en wordt niet opnieuw gevraagd in de roosterwizard.
      </p>
    </header>

    <div v-if="sourceMode === null" class="admin-roster-create__sources">
      <article class="admin-card admin-roster-create__source-card">
        <span class="admin-status admin-status--confirmed">Gekoppeld</span>
        <h2>Vanuit een aanvraag</h2>
        <p>
          Neem school, bezoekdatum, programma, leerlingaantal en roostergroepen
          over uit een bestaande onderwijsaanvraag.
        </p>
        <AdminButton @click="chooseSource('booking')">
          Aanvraag kiezen
        </AdminButton>
      </article>

      <article class="admin-card admin-roster-create__source-card">
        <span class="admin-status admin-status--roster-custom">Maatwerk</span>
        <h2>Rooster op maat</h2>
        <p>
          Bepaal zelf de basisgegevens. Je kunt optioneel een aanvraag koppelen
          als startpunt en daarna programma, groepen en tijden aanpassen.
        </p>
        <AdminButton variant="secondary" @click="chooseSource('manual')">
            Rooster op maat maken
          </AdminButton>
      </article>
    </div>

    <section
      v-else-if="sourceMode === 'booking'"
      class="admin-card admin-roster-create__booking-picker"
      aria-labelledby="roster-booking-picker-title"
    >
      <header class="admin-roster-create__section-heading">
        <div>
          <p class="admin-eyebrow">Bron · aanvraag</p>
          <h2 id="roster-booking-picker-title">{{ bookingPickerPurpose === "custom" ? "Aanvraag koppelen" : "Aanvraag kiezen" }}</h2>
          <p v-if="bookingPickerPurpose === 'custom' && twoSchoolDaysOnly">Koppel een aanvraag of open het bestaande rooster. Er wordt geen tweede rooster voor dezelfde aanvraag gemaakt.</p>
          <p v-else-if="bookingPickerPurpose === 'custom'">
            Kies een aanvraag als startpunt. De gegevens worden vooringevuld
            en blijven daarna vrij aanpasbaar.
          </p>
          <p v-else>
            Kies een aanvraag. Bestaat hiervoor al een rooster, dan wordt het
            bestaande rooster geopend in plaats van een tweede rooster te maken.
          </p>
        </div>
        <AdminButton variant="ghost" @click="chooseSource('manual')">{{ bookingPickerPurpose === "custom" ? "Terug naar rooster op maat" : "Rooster op maat" }}</AdminButton>
      </header>

      <p v-if="twoSchoolDaysOnly" class="admin-roster-create__pair-help">
        Alleen aanvragen met status In optie of Definitief op dagen met twee actieve scholen.
        Afgewezen aanvragen tellen niet mee. Een bestaand rooster wordt hergebruikt.
      </p>
      <label class="admin-field">
        <span class="admin-field__label">Zoeken in aanvragen</span>
        <input
          v-model="bookingFilters.search"
            class="admin-control admin-roster-create__booking-search"
          type="search"
          maxlength="150"
          autocomplete="off"
          placeholder="School, plaats, contactpersoon of e-mail"
          @input="scheduleBookingSearch"
        >
      </label>

      <p v-if="error" class="admin-inline-error" role="alert">{{ error }}</p>

      <div v-if="loadingBookings && bookingItems.length === 0" class="admin-bookings__state">
        Aanvragen laden…
      </div>

      <div v-else-if="bookingItems.length === 0" class="admin-bookings__state">
        <template v-if="twoSchoolDaysOnly && fixedVisitDate">Geen andere actieve aanvraag gevonden op deze bezoekdatum. Er moeten twee aanvragen In optie of Definitief op die dag staan.</template>
        <template v-else>Geen aanvragen gevonden.</template>
      </div>

      <div v-else class="admin-roster-create__booking-list">
        <button
          v-for="item in bookingItems"
          :key="item.id"
          type="button"
          class="admin-roster-create__booking"
          :disabled="openingBookingId !== null"
          @click="bookingPickerPurpose === 'custom' ? useBookingForManual(item) : openFromBooking(item)"
        >
          <span>
            <strong>{{ item.schoolName }}</strong>
            <small>{{ item.city }} · {{ formatDate(item.visitDate) }}</small>
          </span>
          <span>
            {{ item.programLabel }}
            <small>{{ item.studentCount ?? "Onbekend" }} leerlingen</small>
          </span>
          <strong>
              <template v-if="bookingPickerPurpose === 'custom'">
                Koppelen <span aria-hidden="true">&rarr;</span>
              </template>
              <template v-else>
                {{ openingBookingId === item.id ? "Openen..." : "Rooster maken / openen" }}
                <span aria-hidden="true">&rarr;</span>
              </template>
            </strong>
        </button>
      </div>

      <nav
        v-if="bookingPagination.totalPages > 1"
        class="admin-pagination"
        aria-label="Paginering aanvraagkeuze"
      >
        <AdminButton
          variant="secondary"
          :disabled="bookingPagination.currentPage <= 1 || loadingBookings"
          @click="loadBookings(bookingPagination.currentPage - 1)"
        >
          Vorige
        </AdminButton>

        <p>
          <strong>{{ bookingPagination.from }}–{{ bookingPagination.to }}</strong>
          van {{ bookingPagination.totalItems }}
        </p>

        <AdminButton
          variant="secondary"
          :disabled="
            bookingPagination.currentPage >= bookingPagination.totalPages
            || loadingBookings
          "
          @click="loadBookings(bookingPagination.currentPage + 1)"
        >
          Volgende
        </AdminButton>
      </nav>
    </section>

    <section
      v-else
      class="admin-card admin-roster-create__manual-preview"
      aria-labelledby="roster-manual-title"
    >
      <div class="admin-roster-create__section-heading">
        <div>
          <p class="admin-eyebrow">Bron · handmatig</p>
          <h2 id="roster-manual-title">Rooster op maat</h2>
          <p>
            De startwizard volgt: Basis &rarr; Programma &rarr; Dagindeling.
            {{ embedded ? "Daarna ga je verder naar de volgende school of de gezamenlijke controle." : "Daarna opent het rooster en ga je daar verder met Personeel en Genereren." }}
          </p>
        </div>
        <AdminButton v-if="manualPlan === null" variant="ghost" @click="chooseSource('booking')">Rooster vanuit aanvraag</AdminButton>
      </div>

      <AdminWizardStepper :steps="manualSteps" @select="selectManualStep" />
      <p class="admin-roster-create__wizard-continuation">
        {{ embedded ? "Na Dagindeling ga je verder naar de volgende school of de gezamenlijke controle." : "Na stap 3 opent het rooster. Personeel instellen en het rooster genereren doe je daarna in Beheren." }}
      </p>

      <div v-if="loadingManualConfig" class="admin-bookings__state" role="status">
        Roosterconfiguratie laden…
      </div>

      <form
        v-else-if="manualConfig && manualStep === 'basis'"
        class="admin-roster-create__manual-form"
        @submit.prevent="createManualRoster"
      >
                            <div class="admin-roster-create__link-toolbar">
            <span class="admin-status admin-status--roster-custom">
              {{ manualPlan ? "Basis opgeslagen" : "Maatwerk" }}
            </span>

            <div
              v-if="manualPlan === null"
              class="admin-roster-create__link-toolbar-actions"
            >
              <AdminButton
                v-if="manualLinkedBooking"
                type="button"
                variant="secondary"
                @click="openManualBookingPicker"
              >
                Andere aanvraag kiezen
              </AdminButton>

              <AdminButton
                v-if="manualLinkedBooking"
                type="button"
                variant="ghost"
                @click="clearManualBookingLink"
              >
                Koppeling verwijderen
              </AdminButton>

              <AdminButton
                v-else
                type="button"
                variant="secondary"
                @click="openManualBookingPicker"
              >
                Aanvraag kiezen
              </AdminButton>
            </div>
          </div>

          <div
            v-if="manualPlan === null"
            class="admin-inline-notice admin-roster-create__link-notice"
          >
            <template v-if="manualLinkedBooking">
              <div>
                <strong>
                  Gekoppeld aan aanvraag #{{ manualLinkedBooking.id }}
                  - {{ manualLinkedBooking.schoolName }}
                </strong>
                <span>
                  {{ formatDate(manualLinkedBooking.visitDate) }}
                  - {{ manualLinkedBooking.programLabel }}
                  - {{ manualLinkedBooking.studentCount ?? "Onbekend" }} leerlingen
                </span>
                <span>
                  De aanvraag is alleen het startpunt. Alle basisgegevens hieronder
                  blijven aanpasbaar voor dit maatwerkrooster.
                </span>
              </div>
            </template>

            <template v-else>
              <div>
                <strong>Aanvraag koppelen (optioneel)</strong>
                <span>
                  Neem school, datum, sector, programma en leerlingaantal over als
                  startpunt. Je kunt alles daarna nog wijzigen.
                </span>
              </div>
            </template>
          </div>

          <div class="admin-roster-create__manual-grid">
          <label class="admin-field admin-roster-create__manual-field admin-roster-create__manual-wide">
            <span class="admin-field__label">Schoolnaam</span>
            <input
              v-model="manualForm.schoolName"
              class="admin-control admin-roster-create__manual-input"
              type="text"
              maxlength="160"
              autocomplete="organization"
              placeholder="Naam van de school"
              :disabled="manualPlan !== null"
              required
            >
          </label>

          <label class="admin-field admin-roster-create__manual-field">
            <span class="admin-field__label">Plaats</span>
            <input
              v-model="manualForm.city"
              class="admin-control admin-roster-create__manual-input"
              type="text"
              maxlength="120"
              autocomplete="address-level2"
              placeholder="Plaats"
              :disabled="manualPlan !== null"
            >
          </label>

          <AdminDateField
            v-model="manualForm.visitDate"
            name="manual-roster-visit-date"
            label="Bezoekdatum"
            :min="manualDatesInfo?.minDate"
            :max="manualDatesInfo?.maxDate"
            :disabled="manualPlan !== null || !!fixedVisitDate"
            :disabled-dates="manualDisabledDates"
            :disabled-date-reasons="manualDisabledDateReasons"
            hint="Alleen datums waarop een onderwijsbezoek geboekt kan worden."
            required
          />

          <AdminSelect
            v-model="manualForm.sector"
            name="manual-roster-sector"
            label="Onderwijssector"
            placeholder="Kies onderwijssector"
            :options="manualSectorOptions"
            :disabled="manualPlan !== null"
          />

          <AdminSelect
            v-model="manualForm.program"
            name="manual-roster-program"
            label="Programma"
            placeholder="Kies programma"
            :options="manualProgramOptions"
            :disabled="manualPlan !== null"
          />

          <label v-if="manualForm.program === 'ochtend'" class="admin-field admin-roster-create__manual-field">
            <span><input v-model="allowWeekdayOverride" type="checkbox" :disabled="manualPlan !== null"> Ochtendprogramma op een andere dag toestaan</span>
            <span class="admin-field__help">Normaal op woensdag. Als planner kun je hiervan afwijken; beschikbaarheid en capaciteit blijven gelden.</span>
          </label>

          <label class="admin-field admin-roster-create__manual-field">
            <span class="admin-field__label">Aantal leerlingen</span>
            <input
              v-model.number="manualForm.studentCount"
              class="admin-control admin-roster-create__manual-input"
              type="number"
              min="1"
              step="1"
              :disabled="manualPlan !== null"
              required
            >
            <span class="admin-field__help">
              Dit aantal wordt gebruikt voor het advies over de roostergroepen.
            </span>
          </label>

          <label class="admin-field admin-roster-create__manual-field">
            <span class="admin-field__label">Roostergroepen</span>
            <input
              v-model.number="manualForm.groupCount"
              class="admin-control admin-roster-create__manual-input"
              type="number"
              min="1"
              max="10"
              step="1"
              :disabled="manualPlan !== null"
              required
              @input="manualGroupCountTouched = true"
            >
            <span class="admin-field__help">
              De planner mag dit aantal altijd overschrijven.
            </span>
          </label>
        </div>

        <div v-if="manualLargestGroup > 25" class="admin-inline-notice admin-inline-notice--warning" role="status">
          <strong>Meer dan 25 leerlingen per groep</strong>
          <span>Bij een evenwichtige verdeling zijn dat maximaal {{ manualLargestGroup }} leerlingen per groep.
            Je kunt deze keuze opslaan en het rooster met deze waarschuwing definitief maken.</span>
        </div>

        <div
          v-if="selectedProgram || suggestedGroupCount !== null"
          class="admin-roster-create__manual-advice"
        >
          <div v-if="selectedProgram">
            <span>Programmaprofiel</span>
            <strong>
              {{ selectedProgram.beginTijd }}–{{ selectedProgram.eindTijd }}
              · {{ selectedProgram.duurLesmodule }}
            </strong>
          </div>

          <div v-if="suggestedGroupCount !== null">
            <span>Advies op basis van leerlingaantal</span>
            <strong>{{ suggestedGroupCount }} roostergroepen</strong>
            <AdminButton
              v-if="manualPlan === null && manualForm.groupCount !== suggestedGroupCount"
              variant="ghost"
              @click="useSuggestedGroupCount"
            >
              Gebruik advies
            </AdminButton>
          </div>
        </div>

        <div class="admin-inline-notice">
          <template v-if="manualPlan">
            Deze basisgegevens zijn opgeslagen voor rooster #{{ manualPlan.id }}.
            Ga terug naar Programma om de lesmodules verder in te stellen.
          </template>
          <template v-else>
            Na deze stap kies je de lesmodules en hun volgorde voor dit rooster.
          </template>
        </div>

        <p v-if="error" class="admin-inline-error" role="alert">{{ error }}</p>

        <footer class="admin-roster-create__manual-actions">
          <AdminButton
            v-if="manualPlan === null"
            variant="secondary"
            @click="resetSource"
          >
            Terug naar bronkeuze
          </AdminButton>
          <AdminButton
            v-else
            variant="secondary"
            @click="manualStep = 'program'"
          >
            Terug naar Programma
          </AdminButton>
          <AdminButton
            v-if="manualPlan === null"
            type="submit"
            :loading="creatingManual"
            :disabled="!manualBasisValid || loadingManualConfig"
          >
            Verder naar Programma
          </AdminButton>
        </footer>
      </form>

      <section
        v-else-if="manualConfig && manualStep === 'program' && manualPlan"
        class="admin-roster-create__manual-program"
        aria-labelledby="manual-program-title"
      >
        <div v-if="editingExistingPlan" class="admin-inline-notice">
          <strong>Bestaand maatwerkrooster #{{ manualPlan.id }}</strong>
          <span>
            Je past het bestaande rooster aan; er wordt geen tweede rooster aangemaakt.
            <template v-if="manualPlan.bookingId !== null">
              De koppeling met aanvraag #{{ manualPlan.bookingId }} blijft behouden.
            </template>
          </span>
        </div>

        <header class="admin-roster-create__program-heading">
          <div>
            <h3 id="manual-program-title">Lesprogramma samenstellen</h3>
            <p>
              Kies de modules voor deze schooldag en zet ze in de gewenste volgorde.
              De standaardmodules komen rechtstreeks uit BookingProgramConfig.
            </p>
          </div>
          <span class="admin-roster-create__module-count">
            {{ manualModuleKeys.length }} module{{ manualModuleKeys.length === 1 ? "" : "s" }}
          </span>
        </header>

        <dl class="admin-roster-create__program-summary">
          <div>
            <dt>School</dt>
            <dd>{{ manualForm.schoolName }}</dd>
          </div>
          <div>
            <dt>Bezoekdatum</dt>
            <dd>{{ formatDate(manualForm.visitDate) }}</dd>
          </div>
          <div>
            <dt>Programma</dt>
            <dd>{{ selectedProgram?.label }}</dd>
          </div>
          <div>
            <dt>Roostergroepen</dt>
            <dd>
              <label class="admin-field">
                <span class="admin-field__label">Aantal groepen (maatwerk)</span>
                <input v-model.number="manualForm.groupCount" class="admin-control" type="number"
                  min="1" max="10" step="1" required :disabled="savingManualProgram"
                  @input="manualGroupCountTouched = true; manualProgramSaved = false">
                <span class="admin-field__help">Hier kun je afwijken van de standaardberekening.</span>
              </label>
            </dd>
          </div>
        </dl>

        <div v-if="manualLargestGroup > 25" class="admin-inline-notice admin-inline-notice--warning" role="status">
          <strong>Meer dan 25 leerlingen per groep</strong>
          <span>Bij een evenwichtige verdeling zijn dat maximaal {{ manualLargestGroup }} leerlingen per groep.
            Je kunt deze keuze opslaan en het rooster met deze waarschuwing definitief maken.</span>
        </div>

        <div
          class="admin-inline-notice"
          :class="{ 'admin-inline-notice--warning': !manualProgramValid }"
        >
          <strong>
            {{ manualModuleKeys.length }} module{{ manualModuleKeys.length === 1 ? "" : "s" }}
            &middot; maximaal {{ manualModuleCapacity }} roostergroepen
          </strong>
          <span>
            Dit rooster heeft {{ manualForm.groupCount }} groepen.
            <template v-if="manualForm.groupCount > manualModuleCapacity">
              Kies minimaal {{ manualRequiredModuleCount }} modules.
            </template>
            <template v-else-if="manualModuleKeys.length < MANUAL_MIN_MODULE_COUNT">
              Kies minimaal twee modules.
            </template>
            <template v-else>
              De capaciteit past bij deze selectie.
            </template>
          </span>
        </div>

        <section class="admin-roster-create__selected-modules">
          <div class="admin-roster-create__program-subheading">
            <div>
              <h4>Geselecteerde modules</h4>
              <p>Deze volgorde wordt de basis voor de volgende stap Dagindeling.</p>
            </div>
          </div>

          <div v-if="manualModuleKeys.length" class="admin-roster-create__module-order">
            <article
              v-for="(moduleKey, index) in manualModuleKeys"
              :key="moduleKey"
              class="admin-roster-create__module-row"
            >
              <span class="admin-roster-create__module-position">{{ index + 1 }}</span>
              <div class="admin-roster-create__module-copy">
                <strong>{{ manualModuleLabel(moduleKey) }}</strong>
                <small>{{ manualModuleKind(moduleKey) }}</small>
              </div>
              <div class="admin-roster-create__module-actions">
                <button
                  type="button"
                  class="admin-roster-create__module-icon-button"
                  :disabled="index === 0"
                  :aria-label="`${manualModuleLabel(moduleKey)} omhoog`"
                  @click="moveManualModule(index, -1)"
                >
                  <ArrowUp :size="17" aria-hidden="true" />
                </button>
                <button
                  type="button"
                  class="admin-roster-create__module-icon-button"
                  :disabled="index === manualModuleKeys.length - 1"
                  :aria-label="`${manualModuleLabel(moduleKey)} omlaag`"
                  @click="moveManualModule(index, 1)"
                >
                  <ArrowDown :size="17" aria-hidden="true" />
                </button>
                <button
                  type="button"
                  class="admin-roster-create__module-icon-button admin-roster-create__module-icon-button--remove"
                  :aria-label="`${manualModuleLabel(moduleKey)} verwijderen`"
                  @click="removeManualModule(moduleKey)"
                >
                  <X :size="17" aria-hidden="true" />
                </button>
              </div>
            </article>
          </div>

          <div v-else class="admin-inline-notice admin-inline-notice--warning">
            Selecteer minimaal één module om het programma op te slaan.
          </div>
        </section>

        <section class="admin-roster-create__available-modules">
          <div class="admin-roster-create__program-subheading">
            <div>
              <h4>Beschikbare modules</h4>
              <p>
                Standaard- en keuzemodules volgen de gekozen sector en het programma.
              </p>
            </div>
          </div>

          <div v-if="manualAvailableModuleKeys.length" class="admin-roster-create__module-grid">
            <button
              v-for="moduleKey in manualAvailableModuleKeys"
              :key="moduleKey"
              type="button"
              class="admin-roster-create__module-option"
              :disabled="manualModuleKeys.length >= MANUAL_MAX_MODULE_COUNT"
              @click="addManualModule(moduleKey)"
            >
              <span>
                <strong>{{ manualModuleLabel(moduleKey) }}</strong>
                <small>{{ manualModuleKind(moduleKey) }}</small>
              </span>
              <Plus :size="18" aria-hidden="true" />
            </button>
          </div>
          <p v-else class="admin-roster-create__all-modules-selected">
            Alle beschikbare modules voor dit programmaprofiel zijn geselecteerd.
          </p>
        </section>

        <div class="admin-inline-notice">
          Je mag bewust afwijken van de standaardset. Vier en vijf modules passen beide
          binnen deze handmatige planner; een andere selectie blijft ook mogelijk.
        </div>

        <p v-if="error" class="admin-inline-error" role="alert">{{ error }}</p>

        <footer class="admin-roster-create__manual-actions">
          <AdminButton
            v-if="!editingExistingPlan"
            variant="secondary"
            @click="manualStep = 'basis'"
          >
            Basis bekijken
          </AdminButton>
          <AdminButton
            :loading="savingManualProgram"
            :disabled="!manualProgramValid"
            @click="saveManualProgram"
          >
            Programma opslaan en verder
          </AdminButton>
        </footer>
      </section>

      <section
        v-else-if="manualConfig && manualStep === 'timeline' && manualPlan"
        class="admin-roster-create__manual-timeline"
        aria-labelledby="manual-timeline-title"
      >
        <header class="admin-roster-create__timeline-heading">
          <div>
            <h3 id="manual-timeline-title">Dagindeling</h3>
            <p>
              Leg de tijdvakken van de dag vast. Lesrondes zijn tijdvakken;
              modules, locaties, groepen en docenten worden pas later aan sessies gekoppeld.
            </p>
          </div>
          <span class="admin-roster-create__timeline-source">
            {{ manualPlan.planning.roundsSource === "saved" ? "Opgeslagen indeling" : "Standaardindeling" }}
          </span>
        </header>

        <div class="admin-inline-notice">
          De standaardindeling is afgeleid van {{ manualModuleKeys.length }} modules.
          Bij vier modules staat na ronde 2 een pauze van 30 minuten, bij drie modules
          na ronde 1 een pauze van 15 minuten en bij twee modules is er geen tussenpauze.
          Tijdvakken mogen daarna handmatig worden aangepast zolang ze niet overlappen.
        </div>

        <div v-if="manualRounds.length" class="admin-roster-create__timeline-list">
          <article
            v-for="(block, index) in manualRounds"
            :key="`${index}-${block.type}`"
            class="admin-roster-create__timeline-row"
          >
            <span class="admin-roster-create__timeline-position">{{ index + 1 }}</span>

            <div class="admin-field admin-roster-create__timeline-field">
              <span class="admin-field__label">Type</span>
              <strong class="admin-roster-create__timeline-type">
                {{ manualTimeBlockTypeLabel(block.type) }}
              </strong>
            </div>

            <label class="admin-field admin-roster-create__timeline-field admin-roster-create__timeline-label">
              <span class="admin-field__label">Omschrijving</span>
              <input
                v-model="block.label"
                class="admin-control"
                type="text"
                maxlength="160"
                placeholder="Bijvoorbeeld Ronde 1"
              >
            </label>

            <label class="admin-field admin-roster-create__timeline-field">
              <span class="admin-field__label">Van</span>
              <input v-model="block.startTime" class="admin-control" type="time">
            </label>

            <label class="admin-field admin-roster-create__timeline-field">
              <span class="admin-field__label">Tot</span>
              <input v-model="block.endTime" class="admin-control" type="time">
            </label>

          </article>
        </div>

        <div v-else class="admin-inline-notice admin-inline-notice--warning">
          Voeg minimaal Ã©Ã©n lesronde toe om de dagindeling op te slaan.
        </div>


        <p v-if="error" class="admin-inline-error" role="alert">{{ error }}</p>

        <footer class="admin-roster-create__manual-actions">
          <AdminButton variant="secondary" @click="manualStep = 'program'">
            Programma bekijken
          </AdminButton>
          <AdminButton
            :loading="savingManualTimeline"
            :disabled="!manualTimelineValid"
            @click="saveManualTimeline"
          >
            {{ embedded ? "Dagindeling opslaan en verder" : "Dagindeling opslaan en rooster openen" }}
          </AdminButton>
        </footer>
      </section>

      <div v-else class="admin-bookings__state">
        <p>De roosterconfiguratie is nog niet beschikbaar.</p>
        <AdminButton :loading="loadingManualConfig" @click="loadManualConfig">
          Opnieuw proberen
        </AdminButton>
      </div>
    </section>
  </section>
</template>

<style scoped>
.admin-roster-create {
  display: grid;
  gap: 1rem;
}

.admin-roster-create__header {
  max-width: 58rem;
}

.admin-roster-create__header h1 {
  margin-bottom: 0.35rem;
  color: var(--color-main-blue-dark);
}
.admin-roster-create__wizard-continuation {
  margin: 0.55rem 0 0;
  font-size: 0.82rem;
  line-height: 1.45;
  color: rgba(15, 35, 74, 0.72);
}

.admin-roster-create__sources {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 1rem;
}

.admin-roster-create__source-card {
  display: grid;
  align-content: start;
  gap: 0.75rem;
  min-height: 15rem;
}

.admin-roster-create__source-card h2,
.admin-roster-create__source-card p {
  margin: 0;
}

.admin-roster-create__source-card .admin-button {
  justify-self: start;
  margin-top: auto;
}

.admin-roster-create__section-heading {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 1rem;
}

.admin-roster-create__section-heading h2,
.admin-roster-create__section-heading p {
  margin-top: 0;
}

.admin-roster-create__booking-picker {
  display: grid;
  gap: 1rem;
}

.admin-roster-create__booking-list {
  display: grid;
  gap: 0.45rem;
}

.admin-roster-create__booking {
  display: grid;
  grid-template-columns: minmax(220px, 1.2fr) minmax(220px, 1fr) auto;
  align-items: center;
  gap: 1rem;
  width: 100%;
  padding: 0.8rem 0.9rem;
  border: 1px solid var(--admin-border, #d8deea);
  border-radius: 0.7rem;
  background: var(--admin-surface, #fff);
  color: inherit;
  font: inherit;
  text-align: left;
  cursor: pointer;
  transition:
    background-color 150ms ease,
    border-color 150ms ease,
    box-shadow 150ms ease,
    transform 150ms ease;
}

.admin-roster-create__booking:hover:not(:disabled),
.admin-roster-create__booking:focus-visible:not(:disabled) {
  border-color: var(--color-main-blue);
  background: var(--admin-surface-soft);
  box-shadow: var(--shadow-control-hover);
  transform: translateY(-1px);
}

.admin-roster-create__booking:focus-visible {
  outline: 3px solid var(--color-main-blue);
  outline-offset: 2px;
}

.admin-roster-create__booking:disabled {
  cursor: wait;
  opacity: 0.68;
}

.admin-roster-create__booking > span {
  display: grid;
  gap: 0.15rem;
}

.admin-roster-create__booking small {
  color: var(--color-font-muted);
  font-size: 0.78rem;
}

.admin-roster-create__manual-preview {
  display: grid;
  gap: 1rem;
}

.admin-roster-create__manual-form {
  display: grid;
  gap: 1rem;
}

.admin-roster-create__manual-meta {
  display: flex;
  justify-content: flex-end;
  min-height: 1.85rem;
}

.admin-roster-create__manual-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  column-gap: 1rem;
  row-gap: 1.05rem;
  align-items: start;
}

.admin-roster-create__manual-field,
.admin-roster-create__manual-grid :deep(.admin-field) {
  display: flex;
  min-width: 0;
  flex-direction: column;
  align-self: start;
  gap: 0.45rem;
}

.admin-roster-create__manual-grid :deep(.admin-field__label) {
  display: flex;
  min-height: 1.35rem;
  align-items: center;
  margin: 0;
}

.admin-roster-create__manual-grid :deep(.admin-control) {
  min-height: 3.25rem;
}

.admin-roster-create__manual-grid :deep(.admin-field__message),
.admin-roster-create__manual-field .admin-field__help {
  margin: 0;
  line-height: 1.4;
}

.admin-roster-create__manual-input {
  padding-inline: 1rem;
}

.admin-roster-create__manual-wide {
  grid-column: 1 / -1;
}

.admin-roster-create__manual-advice {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 0.75rem;
}

.admin-roster-create__manual-advice > div {
  display: grid;
  align-content: start;
  gap: 0.2rem;
  padding: 0.8rem;
  border: 1px solid var(--admin-border, #d8deea);
  border-radius: 0.7rem;
  background: var(--admin-surface-soft, #f8fafc);
}

.admin-roster-create__manual-advice span {
  color: var(--color-font-muted);
  font-size: 0.78rem;
}

.admin-roster-create__manual-advice .admin-button {
  justify-self: start;
  margin-top: 0.35rem;
}

.admin-roster-create__manual-actions {
  display: flex;
  justify-content: space-between;
  gap: 0.75rem;
}

.admin-roster-create__manual-program {
  display: grid;
  gap: 1.15rem;
}

.admin-roster-create__program-heading,
.admin-roster-create__program-subheading {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 1rem;
}

.admin-roster-create__program-heading h3,
.admin-roster-create__program-heading p,
.admin-roster-create__program-subheading h4,
.admin-roster-create__program-subheading p {
  margin: 0;
}

.admin-roster-create__program-heading p,
.admin-roster-create__program-subheading p {
  margin-top: 0.25rem;
  color: var(--color-font-muted);
}

.admin-roster-create__module-count {
  flex: 0 0 auto;
  padding: 0.38rem 0.7rem;
  border: 1px solid var(--admin-border);
  border-radius: 999px;
  background: var(--admin-surface-soft);
  color: var(--color-main-blue-dark);
  font-size: 0.8rem;
  font-weight: 800;
}

.admin-roster-create__program-summary {
  display: grid;
  grid-template-columns: repeat(4, minmax(0, 1fr));
  gap: 0.75rem;
  margin: 0;
  padding: 0.85rem 1rem;
  border: 1px solid var(--admin-border);
  border-radius: 0.75rem;
  background: var(--admin-surface-soft);
}

.admin-roster-create__program-summary div {
  display: grid;
  gap: 0.15rem;
}

.admin-roster-create__program-summary dt {
  color: var(--color-font-muted);
  font-size: 0.75rem;
  font-weight: 700;
}

.admin-roster-create__program-summary dd {
  margin: 0;
  color: var(--color-main-blue-dark);
  font-weight: 750;
}

.admin-roster-create__selected-modules,
.admin-roster-create__available-modules {
  display: grid;
  gap: 0.7rem;
}

.admin-roster-create__module-order {
  display: grid;
  gap: 0.5rem;
}

.admin-roster-create__module-row {
  display: grid;
  grid-template-columns: auto minmax(0, 1fr) auto;
  align-items: center;
  gap: 0.75rem;
  padding: 0.65rem 0.75rem;
  border: 1px solid var(--admin-border);
  border-radius: 0.7rem;
  background: var(--admin-surface);
}

.admin-roster-create__module-position {
  display: grid;
  width: 1.8rem;
  height: 1.8rem;
  place-items: center;
  border-radius: 999px;
  background: #eaf1ff;
  color: var(--color-main-blue-dark);
  font-size: 0.78rem;
  font-weight: 850;
}

.admin-roster-create__module-copy {
  display: grid;
  gap: 0.08rem;
  min-width: 0;
}

.admin-roster-create__module-copy small,
.admin-roster-create__module-option small {
  color: var(--color-font-muted);
  font-size: 0.75rem;
}

.admin-roster-create__module-actions {
  display: flex;
  gap: 0.35rem;
}

.admin-roster-create__module-icon-button {
  display: grid;
  width: 2rem;
  height: 2rem;
  place-items: center;
  padding: 0;
  border: 1px solid var(--admin-border);
  border-radius: 0.55rem;
  background: var(--admin-surface);
  color: var(--color-main-blue-dark);
  cursor: pointer;
}

.admin-roster-create__module-icon-button:hover:not(:disabled),
.admin-roster-create__module-icon-button:focus-visible:not(:disabled) {
  border-color: var(--color-main-blue);
  background: #eef4ff;
}

.admin-roster-create__module-icon-button--remove:hover:not(:disabled),
.admin-roster-create__module-icon-button--remove:focus-visible:not(:disabled) {
  border-color: #94a3b8;
  background: #f1f5f9;
  color: #334155;
}

.admin-roster-create__module-icon-button:focus-visible,
.admin-roster-create__module-option:focus-visible {
  outline: none;
  box-shadow: var(--admin-focus-ring);
}

.admin-roster-create__module-icon-button:disabled {
  cursor: default;
  opacity: 0.32;
}

.admin-roster-create__module-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 0.55rem;
}

.admin-roster-create__module-option {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 0.75rem;
  min-height: 3.4rem;
  padding: 0.65rem 0.8rem;
  border: 1px solid var(--admin-border);
  border-radius: 0.7rem;
  background: var(--admin-surface);
  color: inherit;
  font: inherit;
  text-align: left;
  cursor: pointer;
  transition: border-color 150ms ease, background-color 150ms ease, box-shadow 150ms ease;
}

.admin-roster-create__module-option:hover {
  border-color: var(--color-main-blue);
  background: #f6f9ff;
}

.admin-roster-create__module-option > span {
  display: grid;
  gap: 0.1rem;
}

.admin-roster-create__all-modules-selected {
  margin: 0;
  padding: 0.7rem 0.8rem;
  border: 1px dashed var(--admin-border);
  border-radius: 0.7rem;
  color: var(--color-font-muted);
}

@media (max-width: 760px) {
  .admin-roster-create__sources,
  .admin-roster-create__booking,
  .admin-roster-create__manual-grid,
  .admin-roster-create__manual-advice,
  .admin-roster-create__module-grid,
  .admin-roster-create__program-summary {
    grid-template-columns: 1fr;
  }

  .admin-roster-create__manual-wide {
    grid-column: auto;
  }

  .admin-roster-create__manual-actions,
  .admin-roster-create__program-heading {
    display: grid;
  }

  .admin-roster-create__manual-meta {
    justify-content: flex-start;
  }

  .admin-roster-create__section-heading {
    display: grid;
  }

  .admin-roster-create__module-row {
    grid-template-columns: auto minmax(0, 1fr);
  }

  .admin-roster-create__module-actions {
    grid-column: 1 / -1;
    justify-content: flex-end;
  }
}
.admin-roster-create__manual-timeline {
  display: grid;
  gap: 1rem;
}

.admin-roster-create__timeline-heading {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 1rem;
}

.admin-roster-create__timeline-heading h3,
.admin-roster-create__timeline-heading p {
  margin-top: 0;
}

.admin-roster-create__timeline-source {
  flex: none;
  padding: 0.3rem 0.55rem;
  border-radius: 999px;
  background: #eaf1ff;
  color: var(--color-main-blue-dark);
  font-size: 0.75rem;
  font-weight: 800;
}

.admin-roster-create__timeline-list {
  display: grid;
  gap: 0.55rem;
}

.admin-roster-create__timeline-row {
  display: grid;
  grid-template-columns: auto minmax(130px, 0.7fr) minmax(210px, 1.4fr) minmax(110px, 0.55fr) minmax(110px, 0.55fr);
  align-items: end;
  gap: 0.65rem;
  padding: 0.75rem;
  border: 1px solid var(--admin-border);
  border-radius: 0.75rem;
  background: var(--admin-surface);
}

.admin-roster-create__timeline-position {
  display: grid;
  width: 1.8rem;
  height: 1.8rem;
  place-items: center;
  align-self: center;
  border-radius: 999px;
  background: #eaf1ff;
  color: var(--color-main-blue-dark);
  font-size: 0.78rem;
  font-weight: 850;
}

.admin-roster-create__timeline-field {

  min-width: 0;

  padding: 0.5rem 0.6rem 0.6rem;

  border-radius: 0.65rem;

}



/* Alternate Type / Description / From / To columns. */

.admin-roster-create__timeline-row > .admin-roster-create__timeline-field:nth-child(2),

.admin-roster-create__timeline-row > .admin-roster-create__timeline-field:nth-child(4) {

  background: #eef5ff;

}



.admin-roster-create__timeline-row > .admin-roster-create__timeline-field:nth-child(3),

.admin-roster-create__timeline-row > .admin-roster-create__timeline-field:nth-child(5) {

  background: #ffffff;

}

.admin-roster-create__timeline-field .admin-control {

  width: 100%;

  min-height: 2.8rem;

  padding-left: 0.95rem;

  padding-right: 0.8rem;

  background: #ffffff;

}
.admin-roster-create__timeline-type {

  display: flex;

  min-height: 2.8rem;

  align-items: center;

  padding: 0.65rem 0.95rem;

  border: 1px solid var(--admin-border);

  border-radius: 0.55rem;

  background: #ffffff;

  color: var(--color-main-blue-dark);

}

.admin-roster-create__timeline-actions {
  display: flex;
  align-items: center;
  align-self: end;
  gap: 0.35rem;
  min-height: 2.8rem;
}

.admin-roster-create__timeline-add {
  display: flex;
  justify-content: flex-start;
}

@media (max-width: 980px) {
  .admin-roster-create__timeline-row {
    grid-template-columns: auto minmax(0, 1fr) minmax(0, 1fr);
    align-items: end;
  }

  .admin-roster-create__timeline-label {
    grid-column: 2 / -1;
  }

  .admin-roster-create__timeline-actions {
    grid-column: 2 / -1;
    justify-content: flex-end;
  }
}

@media (max-width: 680px) {
  .admin-roster-create__timeline-heading {
    display: grid;
  }

  .admin-roster-create__timeline-source {
    justify-self: start;
  }

  .admin-roster-create__timeline-row {
    grid-template-columns: auto minmax(0, 1fr);
  }

  .admin-roster-create__timeline-field,
  .admin-roster-create__timeline-label,
  .admin-roster-create__timeline-actions {
    grid-column: 2 / -1;
  }
}


/* D3B polish: calm blue maatwerk controls */
.admin-roster-create__link-toolbar {
  display: flex;
  width: 100%;
  max-width: 100%;
  box-sizing: border-box;
  align-items: center;
  flex-wrap: wrap;
  gap: 0.65rem 0.9rem;
}

.admin-roster-create__link-toolbar-actions {
  display: flex;
  max-width: 100%;
  align-items: center;
  flex-wrap: wrap;
  gap: 0.6rem;
  margin-left: auto;
}

.admin-roster-create__link-notice {
  display: grid;
  width: 100%;
  max-width: 100%;
  box-sizing: border-box;
  gap: 0.3rem;
}

.admin-roster-create__link-notice > div {
  display: grid;
  min-width: 0;
  gap: 0.25rem;
}

.admin-roster-create__booking-search {
  padding-left: 1rem;
  padding-right: 0.9rem;
}

.admin-status--roster-custom {
  border: 1px solid #b8cbea;
  background: #eef5ff;
  color: var(--color-main-blue-dark);
}

@media (max-width: 760px) {
  .admin-roster-create__link-toolbar {
    align-items: stretch;
  }

  .admin-roster-create__link-toolbar-actions {
    width: 100%;
    margin-left: 0;
  }
}

/* D3B final visual polish: aligned source cards and yellow maatwerk. */
.admin-roster-create__sources {
  align-items: stretch;
}

.admin-roster-create__source-card {
  height: 100%;
  grid-template-rows: auto auto minmax(0, 1fr) auto;
  align-content: stretch;
}

.admin-roster-create__source-card .admin-button {
  align-self: end;
  margin-top: 0;
}

.admin-status--roster-custom {
  border-color: #d7bd50;
  background: #fff8d9;
  color: #574300;
}
/* Compact embedded wizard; the parent supplies the page heading. */
.admin-roster-create:has(.admin-roster-create__source-card) .admin-card { padding: 1.25rem; }
.admin-roster-create__sources { grid-auto-rows: 1fr; }
.admin-roster-create__source-card { box-sizing: border-box; }
.admin-roster-create--embedded { gap: 1rem; }
.admin-roster-create--embedded .admin-roster-create__header h2 { font-size: 1.4rem; margin: .2rem 0; }
.admin-roster-create--embedded .admin-roster-create__header p { margin: .3rem 0; }
.admin-roster-create__sources { grid-template-rows: auto auto 1fr auto; }
.admin-roster-create__source-card { grid-row: span 4; grid-template-rows: subgrid; gap: .65rem; }
.admin-roster-create__source-card h2, .admin-roster-create__source-card p { margin: 0; }
.admin-roster-create__source-card h2 { font-size: 1.25rem; }
@media (max-width: 760px) {
  .admin-roster-create__sources { grid-template-rows: none; }
  .admin-roster-create__source-card { grid-row: auto; grid-template-rows: auto auto 1fr auto; }
}
</style>

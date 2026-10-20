<script setup lang="ts">
import AdminSelect from "../form/AdminSelect.vue";
import { computed, inject, onBeforeUnmount, ref, watch } from "vue";

import { fetchDashboardRoster } from '../../services/dashboardRosterApi';
import { pairedStaffUse, availablePairedRounds } from '../../services/pairedRosterStaff';
import RosterCompanionPreview from './RosterCompanionPreview.vue';
import AdminButton from "../form/AdminButton.vue";
import AdminWizardStepper, { type AdminWizardStep } from "../wizard/AdminWizardStepper.vue";
import {
  applyGeneratedRoster,
  previewGeneratedRoster,
  type RosterGenerationOptions,
} from "../../services/dashboardRosterGenerationApi";
import { adminBootstrapKey } from "../../types/admin";
import type {
  DashboardRosterPlan,
  RosterGenerationProposal,
  RosterGenerationRound,
  RosterLessonPreferences,
  RosterPlannerModulePreference,
  RosterStaffMember,
  RosterStaffingMode,
} from "../../types/roster";

const props = defineProps<{ plan: DashboardRosterPlan }>();
const emit = defineEmits<{ applied: [] }>();

const bootstrap = inject(adminBootstrapKey);
if (!bootstrap) throw new Error("Admin bootstrapdata ontbreekt.");
const csrfToken = bootstrap.rosterGenerationCsrfToken;

const currentStep = ref(1);

const staffingMode = ref<RosterStaffingMode>("with_staff");
const cookStaffId = ref<number | null>(null);
const lessonPreferences = ref<RosterLessonPreferences>({ moduleOrder: [], supervision: {} });
const supervisionModules = computed(() => props.plan.planning.modules.filter(
  module => ["Klimaat-Experience", "Klimparcours"].includes(module.key) && module.schoolSupervisionAllowed,
));
const rankedModules = computed(() => lessonPreferences.value.moduleOrder.flatMap(key => {
  const module = props.plan.planning.modules.find(item => item.key === key);
  return module ? [module] : [];
}));
const firstMovableRank = computed(() => lessonPreferences.value.moduleOrder[0] === "Voedsel-Innovatie" ? 1 : 0);
function moveModulePreference(index: number, direction: -1 | 1): void {
  const target = index + direction;
  if (loading.value || applying.value || index < firstMovableRank.value
    || target < firstMovableRank.value || target >= lessonPreferences.value.moduleOrder.length) return;
  const order = [...lessonPreferences.value.moduleOrder];
  [order[index], order[target]] = [order[target]!, order[index]!];
  lessonPreferences.value.moduleOrder = order;
}
const supervisionOptions = [
  { value: "geofort", label: "GeoFort-begeleider" },
  { value: "school", label: "Schoolbegeleiding" },
];
function setSupervision(moduleKey: string, value: string): void {
  if (value === "geofort" || value === "school") lessonPreferences.value.supervision[moduleKey] = value;
}
const lessonPreferencesSummary = computed(() => {
  const selected = proposal.value?.lessonPreferences;
  if (!selected) return [];
  const order = selected.moduleOrder.map((key, index) => {
    const label = props.plan.planning.modules.find(module => module.key === key)?.label ?? key;
    return `${index + 1}. ${label}`;
  });
  return [
    `Voorkeursvolgorde: ${order.join(" · ")}`,
    ...supervisionModules.value.map(module =>
      `${module.label}: ${selected.supervision[module.key] === "school" ? "schoolbegeleiding" : "GeoFort-begeleider, naam later invullen"}`,
    ),
  ];
});
const rounds = ref<RosterGenerationRound[]>([]);
const selectedStaffIds = ref<number[]>([]);
const personnelSubstep = ref<"selection" | "preferences">("selection");
const plannerModulePreferences = ref<Record<number, string[]>>({});
const proposal = ref<RosterGenerationProposal | null>(null);
const replaceExisting = ref(false);
const staffFilter = ref<'existing' | 'all'>('all');
const lessonConflictsAccepted = ref(false);
watch(proposal, () => { lessonConflictsAccepted.value = false; });
const visibleGuideCatalog = computed(() => guideCatalog.value.filter(member => staffFilter.value === 'all' || companionStaff.value.some(p => p.id === member.id)));

const loading = ref(false);
const applying = ref(false);
const error = ref<string | null>(null);

const companion = ref<DashboardRosterPlan | null>(null);
const companionLoading = ref(false);
const companionError = ref<string | null>(null);
let companionController: AbortController | undefined;
watch(() => `${props.plan.id}:${props.plan.revision}:${props.plan.jointPlanning?.planIds.join(',') ?? ''}`, async () => {
  companionController?.abort();
  const control = new AbortController(); companionController = control;
  companion.value = null; companionError.value = null; companionLoading.value = false;
  const id = props.plan.jointPlanning?.planIds.find(id => id !== props.plan.id);
  if (!id) return;
  companionLoading.value = true;
  try { const response = await fetchDashboardRoster(id, control.signal); if (!control.signal.aborted) companion.value = response.plan; }
  catch (e) { if (!control.signal.aborted) companionError.value = e instanceof Error ? e.message : 'De andere school kon niet worden geladen. Herlaad de wizard.'; }
  finally { if (!control.signal.aborted) companionLoading.value = false; }
}, { immediate: true });
onBeforeUnmount(() => companionController?.abort());
const companionStaff = computed(() => pairedStaffUse(companion.value));
function pairedAvailability(member: RosterStaffMember) {
  return availablePairedRounds(member, companionStaff.value.find(p => p.id === member.id), rounds.value, props.plan.planning.modules.map(m => m.key));
}
function staffUseLabel(member: RosterStaffMember): string {
  const use = companionStaff.value.find(p => p.id === member.id);
  if (!use) return companion.value ? 'Extra medewerker voor deze bezoekdag' : '';
  const free = pairedAvailability(member);
  return free.length ? `Al ingezet · nog mogelijk: ${free.map(r => `${r.startTime}–${r.endTime}`).join(', ')}` : 'Al ingezet · geen passende vrije lesronde';
}

const hasSchoolFallback = computed(() =>
  props.plan.education.programVersion !== "day-four-2027" && props.plan.planning.modules.some((module) => module.schoolSupervisionAllowed),
);
const steps = computed<AdminWizardStep[]>(() =>
  ["Basis", "Dagindeling", "Programma", staffingMode.value === "lesson_only" ? "Voorkeuren" : "Personeel", "Genereren"].map(
    (label, index) => {
      const step = index + 1;
      if (step === currentStep.value) return { label, state: "current" };
      if (step < currentStep.value) return { label, state: "completed" };
      if (step === currentStep.value + 1) return { label, state: "available" };
      return { label, state: "upcoming" };
    },
  ),
);

interface DayTimelineItem {
  key: string;
  type: "arrival" | "activity" | "break" | "lunch" | "departure";
  label: string;
  startTime: string;
  endTime: string;
  roundIndex: number | null;
}

const dayTimeline = computed<DayTimelineItem[]>(() => {
  const timeline = props.plan.planning.rounds ?? [];
  let roundIndex = 0;

  return timeline.map((block, index) => {
    const isRound = block.type === "round";
    const item: DayTimelineItem = {
      key: `${block.type}-${index}`,
      type:
        block.type === "welcome"
          ? "arrival"
          : block.type === "round"
            ? "activity"
            : block.type,
      label: block.label,
      startTime: block.startTime,
      endTime: block.endTime,
      roundIndex: isRound ? roundIndex : null,
    };

    if (isRound) roundIndex += 1;
    return item;
  });
});

const guideCatalog = computed(() =>
  props.plan.planning.staffCatalog.filter((member) => member.isActive && member.canGuide).sort((a,b) => Number(companionStaff.value.some(p => p.id === b.id)) - Number(companionStaff.value.some(p => p.id === a.id)) || a.name.localeCompare(b.name,'nl')),
);

const cookCatalog = computed(() =>
  props.plan.planning.staffCatalog.filter((member) => member.isActive && member.canCook),
);

const selectedStaff = computed(() => {
  const selected = new Set(selectedStaffIds.value);
  return guideCatalog.value.filter((member) => selected.has(member.id));
});

function generalPreferenceRank(member: RosterStaffMember, moduleKey: string): number {
  return member.preferences.find((preference) => preference.moduleKey === moduleKey)?.rank
    ?? Number.MAX_SAFE_INTEGER;
}

function eligibleModulesForStaff(member: RosterStaffMember) {
  return props.plan.planning.modules
    .filter((module) =>
      member.preferences.some((preference) => preference.moduleKey === module.key),
    )
    .sort((left, right) => {
      const rankDifference =
        generalPreferenceRank(member, left.key) - generalPreferenceRank(member, right.key);
      return rankDifference !== 0 ? rankDifference : left.label.localeCompare(right.label, "nl");
    });
}

function plannerPreferenceKeys(staffId: number): string[] {
  return plannerModulePreferences.value[staffId] ?? [];
}

function plannerPreferencePosition(staffId: number, moduleKey: string): number | null {
  const index = plannerPreferenceKeys(staffId).indexOf(moduleKey);
  return index >= 0 ? index + 1 : null;
}

function togglePlannerPreference(staffId: number, moduleKey: string): void {
  const current = [...plannerPreferenceKeys(staffId)];
  const existingIndex = current.indexOf(moduleKey);

  if (existingIndex >= 0) {
    current.splice(existingIndex, 1);
  } else if (current.length < 3) {
    current.push(moduleKey);
  } else {
    return;
  }

  plannerModulePreferences.value = {
    ...plannerModulePreferences.value,
    [staffId]: current,
  };
  proposal.value = null;
}

function plannerPreferencesPayload(): RosterPlannerModulePreference[] {
  const selected = new Set(selectedStaffIds.value);

  return Object.entries(plannerModulePreferences.value)
    .map(([staffId, moduleKeys]) => ({
      staffId: Number(staffId),
      moduleKeys: [...moduleKeys],
    }))
    .filter((entry) =>
      selected.has(entry.staffId)
      && entry.moduleKeys.length > 0,
    );
}

const sharedGuideModuleKeys = new Set([
  "Klimaat-Experience",
  "Stop-de-Klimaat-Klok",
  "Earth-Watch",
  "Minecraft-Klimaatspeurtocht",
  "Minecraft-Programmeren",
  "Minecraft-Windenergiespeurtocht",
]);

function minimumGuidesForModuleGroups(
  module: DashboardRosterPlan["planning"]["modules"][number],
  groupCount: number,
): number {
  if (groupCount <= 0) return 0;
  if (props.plan.education.programVersion === "day-four-2027") return ["Klimaat-Experience", "Klimparcours"].includes(module.key) ? Math.ceil(groupCount / 2) : groupCount;
  if (module.schoolSupervisionAllowed) return 0;
  if (module.key === "Dynamische-Globe") return groupCount;
  if (module.key === "Voedsel-Innovatie" || sharedGuideModuleKeys.has(module.key)) {
    return Math.ceil(groupCount / 2);
  }
  return groupCount * Math.max(1, module.minimumGeoFortStaff);
}

function preferredGuidesForModuleGroups(
  module: DashboardRosterPlan["planning"]["modules"][number],
  groupCount: number,
): number {
  if (groupCount <= 0) return 0;
  if (props.plan.education.programVersion === "day-four-2027") return ["Klimaat-Experience", "Klimparcours"].includes(module.key) ? Math.ceil(groupCount / 2) : groupCount;
  if (module.schoolSupervisionAllowed) {
    return groupCount;
  }
  if (module.key === "Voedsel-Innovatie") return Math.ceil(groupCount / 2);
  if (module.key === "Dynamische-Globe") return groupCount;
  return groupCount * Math.max(1, module.minimumGeoFortStaff);
}

const staffingAdvice = computed(() => {
  const selected = new Set(selectedStaffIds.value);
  const modules = props.plan.planning.modules.map((module) => {
    const eligible = guideCatalog.value.filter((member) =>
      member.preferences.some((preference) => preference.moduleKey === module.key),
    );

    return {
      module,
      eligibleSelected: eligible.filter((member) => selected.has(member.id)),
    };
  });

  const orderedModules = [...props.plan.planning.modules].sort((left, right) => {
    if (left.key === "Voedsel-Innovatie") return -1;
    if (right.key === "Voedsel-Innovatie") return 1;
    return 0;
  });

  let minimumGuides = 0;
  let preferredGuides = 0;

  for (let roundIndex = 0; roundIndex < orderedModules.length; roundIndex += 1) {
    const groupsByModule = new Map<
      string,
      {
        module: DashboardRosterPlan["planning"]["modules"][number];
        count: number;
      }
    >();

    for (let groupIndex = 0; groupIndex < props.plan.groups.length; groupIndex += 1) {
      const module = orderedModules[(groupIndex + roundIndex) % orderedModules.length];
      if (!module) continue;

      const current = groupsByModule.get(module.key);
      if (current) {
        current.count += 1;
      } else {
        groupsByModule.set(module.key, { module, count: 1 });
      }
    }

    let minimum = 0;
    let preferred = 0;

    for (const { module, count } of groupsByModule.values()) {
      minimum += minimumGuidesForModuleGroups(module, count);
      preferred += preferredGuidesForModuleGroups(module, count);
    }

    minimumGuides = Math.max(minimumGuides, minimum);
    preferredGuides = Math.max(preferredGuides, preferred);
  }

  const hasVi = orderedModules.some((module) => module.key === "Voedsel-Innovatie");
  const selectedPeople = new Set(selectedStaffIds.value);
  if (cookStaffId.value !== null) selectedPeople.add(cookStaffId.value);

  return {
    modules,
    minimumGuides,
    preferredGuides,
    hasVi,
    recommendedPeople: preferredGuides + (hasVi ? 1 : 0),
    selectedPeople: selectedPeople.size,
  };
});

function defaultCookId(): number | null {
  if (!staffingAdvice.value.hasVi) return null;

  if (props.plan.planning.staffingSettings.cookStaffId !== null) {
    return props.plan.planning.staffingSettings.cookStaffId;
  }

  return null;
}

function resetWizard(): void {
  currentStep.value = 1;
  const timelineRounds = (props.plan.planning.rounds ?? [])
    .filter((block) => block.type === "round")
    .map((block) => ({
      label: block.label,
      startTime: block.startTime,
      endTime: block.endTime,
    }));

  rounds.value = timelineRounds.length > 0
    ? timelineRounds
    : props.plan.planning.generation.defaultRounds.map((round) => ({ ...round }));
  staffingMode.value = props.plan.planning.staffingSettings.staffingMode ?? "with_staff";
  const persisted = props.plan.planning.lessonPreferences;
  lessonPreferences.value = persisted
    ? { moduleOrder: [...persisted.moduleOrder], supervision: { ...persisted.supervision } }
    : {
        moduleOrder: props.plan.planning.modules.map(module => module.key).sort((a, b) =>
          a === "Voedsel-Innovatie" ? -1 : b === "Voedsel-Innovatie" ? 1 : 0,
        ),
        supervision: Object.fromEntries(supervisionModules.value.map(module =>
          [module.key, props.plan.education.programVersion === "day-four-2027" ? "geofort" : "school"],
        )),
      };
  selectedStaffIds.value = props.plan.planning.selectedStaffIds.filter((id) =>
    id !== props.plan.planning.staffingSettings.cookStaffId
    || props.plan.planning.staffCatalog.find(member => member.id === id)?.canGuide,
  );
  personnelSubstep.value = "selection";
  const persistedPlannerPreferences: Record<number, string[]> = {};
  for (const entry of props.plan.planning.staffingSettings.plannerModulePreferences ?? []) {
    persistedPlannerPreferences[entry.staffId] = [...entry.moduleKeys];
  }
  plannerModulePreferences.value = persistedPlannerPreferences;
  cookStaffId.value = defaultCookId();
  proposal.value = null;
  replaceExisting.value = false;
  error.value = null;
}

watch(
  () => [props.plan.id, props.plan.revision] as const,
  resetWizard,
  { immediate: true },
);
watch(
  () => staffingAdvice.value.hasVi,
  (hasVi) => {
    if (!hasVi) {
      cookStaffId.value = null;
    } else if (cookStaffId.value === null) {
      cookStaffId.value = defaultCookId();
    }
    proposal.value = null;
  },
  { immediate: true },
);
watch([staffingMode, cookStaffId], () => { proposal.value = null; });
watch(lessonPreferences, () => { proposal.value = null; }, { deep: true });
function selectStep(step: number): void {
  if (step <= currentStep.value + 1) currentStep.value = step;
}
function previous(): void {
  if (currentStep.value > 1) currentStep.value -= 1;
}
function next(): void {
  if (currentStep.value < 5) currentStep.value += 1;
}
function toggleStaff(id: number): void {
  const wasSelected = selectedStaffIds.value.includes(id);
  if (!wasSelected && !pairedAvailability(guideCatalog.value.find(m => m.id === id)!).length) return;
  selectedStaffIds.value = wasSelected
    ? selectedStaffIds.value.filter((staffId) => staffId !== id)
    : [...selectedStaffIds.value, id];

  if (wasSelected) {
    const next = { ...plannerModulePreferences.value };
    delete next[id];
    plannerModulePreferences.value = next;
  }

  proposal.value = null;
}
function selectAllStaff(): void {
  selectedStaffIds.value = guideCatalog.value.filter(member => pairedAvailability(member).length > 0).map((member) => member.id);
  proposal.value = null;
}
function clearStaff(): void {
  selectedStaffIds.value = [];
  plannerModulePreferences.value = {};
  proposal.value = null;
}
function updateRoundTime(index: number, field: "startTime" | "endTime", event: Event): void {
  const round = rounds.value[index];
  const target = event.target;
  if (!round || !(target instanceof HTMLInputElement)) return;
  round[field] = target.value;
  proposal.value = null;
}

function generationOptions(): RosterGenerationOptions {
  return {
    staffingMode: staffingMode.value,
    lessonPreferences: staffingMode.value === "lesson_only" ? {
      moduleOrder: [...lessonPreferences.value.moduleOrder],
      supervision: { ...lessonPreferences.value.supervision },
    } : undefined,
    staffIds: staffingMode.value === "with_staff" ? selectedStaffIds.value : [],
    // Legacy transport field only; the generator ignores it. Preserve the stored value.
    preferGeoFortKe: props.plan.planning.staffingSettings.preferGeoFortKe ?? true,
    cookStaffId: staffingMode.value === "with_staff" && staffingAdvice.value.hasVi ? cookStaffId.value : null,
    plannerModulePreferences:
      staffingMode.value === "with_staff" ? plannerPreferencesPayload() : [],
  };
}

function generationPreviewErrorMessage(requestError: unknown): string {
  const fallback = "Het automatische roostervoorstel kon niet worden gemaakt. Probeer opnieuw.";

  if (!(requestError instanceof Error)) return fallback;

  const statusValue = (requestError as Error & { status?: unknown }).status;
  const status = typeof statusValue === "number" ? statusValue : null;

  if (requestError.message === "Ongeldige server response") {
    return "De generator kreeg geen geldig antwoord van de server. Probeer opnieuw. Blijft dit gebeuren, meld het aan beheer.";
  }

  if (status !== null && status >= 500) {
    return "Het voorstel kon door een serverfout niet worden gemaakt. Probeer opnieuw. Blijft dit gebeuren, meld het aan beheer.";
  }

  if (requestError.message === "Request mislukt") return fallback;

  return requestError.message;
}

async function preview(): Promise<void> {
  if (loading.value || companionLoading.value || companionError.value) return;
  loading.value = true;
  error.value = null;

  try {
    proposal.value = await previewGeneratedRoster(
      props.plan.id,
      rounds.value,
      generationOptions(),
      csrfToken,
    );
    replaceExisting.value = proposal.value.existingSessionCount === 0;
  } catch (requestError) {
    error.value = generationPreviewErrorMessage(requestError);
  } finally {
    loading.value = false;
  }
}

async function apply(): Promise<void> {
  if (proposal.value?.lessonConflictSignature && !lessonConflictsAccepted.value) return;
  if (!proposal.value || !proposal.value.canApply || applying.value || companionLoading.value || companionError.value) return;
  applying.value = true;
  error.value = null;

  try {
    await applyGeneratedRoster(
      props.plan.id,
      props.plan.revision,
      rounds.value,
      proposal.value.existingSessionCount > 0 ? replaceExisting.value : false,
      generationOptions(),
      csrfToken,
      proposal.value.catalogRevision,
      lessonConflictsAccepted.value ? proposal.value.lessonConflictSignature ?? null : null,
    );
    proposal.value = null;
    emit("applied");
  } catch (requestError) {
    error.value = requestError instanceof Error
      ? requestError.message
      : "Het automatische roostervoorstel kon niet worden toegepast.";
  } finally {
    applying.value = false;
  }
}

function proposalSessionFor(
  round: RosterGenerationRound,
  groupId: number,
): RosterGenerationProposal["sessions"][number] | null {
  return proposal.value?.sessions.find(
    (session) =>
      session.startTime === round.startTime
      && session.endTime === round.endTime
      && session.groupIds.includes(groupId),
  ) ?? null;
}

function proposalStaffLabel(
  session: RosterGenerationProposal["sessions"][number] | null,
): string {
  if (!session) return "Open";

  if (session.schoolFallback) return "Schoolbegeleiding";

  if (session.assignedStaffIds.length === 0) {
    return staffingMode.value === "lesson_only" ? "GeoFort · nog in te vullen" : "OPEN";
  }

  const names = session.assignedStaffIds
    .map((id) => props.plan.planning.staffCatalog.find((member) => member.id === id)?.name)
    .filter((name): name is string => Boolean(name));

  return names.join(" + ") || "OPEN";
}

function compressionLabel(level: number): string {
  if (level <= 1) return "KE";
  if (level === 2) return "KE + Stop de Klok";
  if (level === 3) return "KE + Stop de Klok + Earth Watch";
  const minecraft = props.plan.planning.modules
    .filter(module => ["Minecraft-Klimaatspeurtocht", "Minecraft-Programmeren", "Minecraft-Windenergiespeurtocht"].includes(module.key))
    .map(module => module.label);
  return ["KE", "Stop de Klok", "Earth Watch", ...minecraft].join(" + ");
}

function hours(minutes: number): string {
  return `${(minutes / 60).toFixed(1).replace(".0", "")} u`;
}
</script>

<template>
  <section class="admin-card admin-roster-generator admin-roster-setup-wizard">
    <p v-if="companionLoading" role="status">Opgeslagen rooster van de andere school laden…</p>
    <p v-if="companionError" class="admin-inline-error" role="alert">{{ companionError }}</p>
    <RosterCompanionPreview v-if="companion" :plan="companion" :staff="companionStaff" />
    <div v-for="id in selectedStaffIds.filter(value => !guideCatalog.some(member => member.id === value))" :key="'unavailable-' + id" class="admin-inline-error">
      {{ plan.planning.staffCatalog.find(member => member.id === id)?.name ?? 'Medewerker #' + id }} is niet meer beschikbaar als begeleider. Verwijder deze keuze bewust voordat je opnieuw genereert.
      <AdminButton variant="secondary" @click="toggleStaff(id)">Uit voorstel verwijderen</AdminButton>
    </div>
    <div class="admin-roster-generator__heading">
      <div>
        <p class="admin-eyebrow">Rooster instellen</p>
        <h2>Conceptrooster-wizard</h2>
      </div>
      <span class="admin-status admin-status--option">Concept</span>
    </div>

        <header
      class="admin-roster-wizard-identity"
      :class="plan.planningMode === 'custom'
        ? 'admin-roster-wizard-identity--custom'
        : 'admin-roster-wizard-identity--standard'"
    >
      <div class="admin-roster-wizard-identity__copy">
        <span
          class="admin-roster-mode-badge"
          :class="plan.planningMode === 'custom'
            ? 'admin-roster-mode-badge--custom'
            : 'admin-roster-mode-badge--standard'"
        >
          {{ plan.planningMode === "custom" ? "Maatwerk" : "Standaard" }}
        </span>
        <div>
          <p class="admin-eyebrow">
            {{ plan.planningMode === "custom" ? "Maatwerkwizard" : "Standaardwizard" }}
          </p>
          <h2>
            {{ plan.planningMode === "custom"
              ? "Maatwerkrooster samenstellen"
              : "Standaardrooster vanuit aanvraag" }}
          </h2>
          <p>
            {{ plan.planningMode === "custom"
              ? "Het rooster zelf bepaalt programma en dagindeling."
              : "De gekoppelde aanvraag bepaalt programma en dagindeling." }}
          </p>
        </div>
      </div>

      <RouterLink
        v-if="plan.planningMode === 'custom' && plan.planning.sessions.length === 0"
        class="admin-button admin-button--secondary"
        :to="{ name: 'roster-create', query: { plan: String(plan.id) } }"
      >
        Programma en dagindeling wijzigen
      </RouterLink>
      <button
        v-else-if="plan.planningMode === 'custom'"
        class="admin-button admin-button--secondary"
        type="button"
        disabled
        title="Verwijder eerst de bestaande sessies via Handmatige correcties."
      >
        Programma en dagindeling wijzigen
      </button>
    </header>
<AdminWizardStepper :steps="steps" @select="selectStep" />

    <div class="admin-roster-setup-wizard__body">
      <section v-if="currentStep === 1">
        <p class="admin-eyebrow">Stap 1</p>
        <h3>Basis en generatiemodus</h3>

        <dl class="admin-details">
          <dt>School</dt><dd>{{ plan.school.name }}</dd>
          <dt>Datum</dt><dd>{{ plan.visitDate }}</dd>
          <dt>Sector</dt><dd>{{ plan.education.sectorLabel }}</dd>
          <dt>Programma</dt><dd>{{ plan.education.programLabel }}</dd>
          <dt>Leerlingen</dt><dd>{{ plan.education.studentCount ?? "Onbekend" }}</dd>
          <dt>Groepen</dt><dd>{{ plan.groups.length }}</dd>
        </dl>

        <h4>Wat wil je genereren?</h4>
        <div class="admin-roster-mode-choice">
          <label>
            <input v-model="staffingMode" type="radio" value="lesson_only">
            <div>
              <strong>Alleen lesrooster</strong>
              <span>Een zuinig lesvoorstel binnen de normale capaciteit, zonder uitzonderlijke dubbele modules. Personeel vul je later in.</span>
            </div>
          </label>
          <label>
            <input v-model="staffingMode" type="radio" value="with_staff">
            <div>
              <strong>Lesrooster + personeel</strong>
              <span>Genereer direct docententoewijzingen en het read-only docentenrooster.</span>
            </div>
          </label>
        </div>
      </section>

      <section v-else-if="currentStep === 2">
        <p class="admin-eyebrow">Stap 2</p>
        <h3>Dagindeling</h3>

        <div class="admin-roster-day-template">
          <article
            v-for="item in dayTimeline"
            :key="item.key"
            class="admin-roster-day-template__row"
            :class="`admin-roster-day-template__row--${item.type}`"
          >
            <div class="admin-roster-day-template__type">
              <strong>{{ item.label }}</strong>
              <span>{{ item.type === "activity" ? "Lesronde" : "Dagonderdeel" }}</span>
            </div>

            <template v-if="item.type === 'activity' && item.roundIndex !== null">
              <label class="admin-field">
                <span class="admin-field__label">Start</span>
                <input
                  :value="rounds[item.roundIndex]?.startTime ?? ''"
                  class="admin-control"
                  type="time"
                  step="300"
                  disabled
                  @input="updateRoundTime(item.roundIndex, 'startTime', $event)"
                >
              </label>
              <label class="admin-field">
                <span class="admin-field__label">Einde</span>
                <input
                  :value="rounds[item.roundIndex]?.endTime ?? ''"
                  class="admin-control"
                  type="time"
                  step="300"
                  disabled
                  @input="updateRoundTime(item.roundIndex, 'endTime', $event)"
                >
              </label>
            </template>

            <div v-else class="admin-roster-day-template__time">
              <span>{{ item.startTime }}</span><span aria-hidden="true">→</span><span>{{ item.endTime }}</span>
            </div>
          </article>
        </div>
      </section>

      <section v-else-if="currentStep === 3">
        <p class="admin-eyebrow">Stap 3</p>
        <h3>Programma en locaties</h3>

        <div class="admin-roster-setup-wizard__modules">
          <article
            v-for="module in plan.planning.modules"
            :key="module.key"
            class="admin-roster-setup-module"
          >
            <span
              class="admin-roster-module-dot"
              :style="{ '--roster-module-color': module.color }"
              aria-hidden="true"
            />
            <div>
              <strong>{{ module.label }}</strong>
              <span>{{ module.defaultLocation ?? "Locatie nog open" }}</span>
            </div>
            <small>Max. parallel: {{ module.maxParallel }}</small>
          </article>
        </div>
      </section>

      <section v-else-if="currentStep === 4">
        <p class="admin-eyebrow">Stap 4</p>
        <h3>{{ staffingMode === "lesson_only" ? "Roostervoorkeuren" : "Personeel" }}</h3>

        <div v-if="staffingMode === 'lesson_only'" class="admin-roster-generator__lesson-preferences">
          <section class="admin-roster-generator__preference-card" aria-labelledby="lesson-order-heading">
            <header class="admin-roster-generator__preference-heading">
              <span class="admin-roster-generator__preference-number" aria-hidden="true">1</span>
              <div>
                <h4 id="lesson-order-heading">Welke modules wil je vroeg plannen?</h4>
                <p>Een voorgestelde volgorde staat klaar. Pas de voorkeuren aan met de pijltjes.</p>
              </div>
            </header>
            <ol class="admin-roster-generator__module-ranking" aria-label="Voorkeursvolgorde modules">
              <li v-for="(module, index) in rankedModules" :key="module.key"
                class="admin-roster-generator__module-rank" :class="{ 'is-fixed': module.key === 'Voedsel-Innovatie' }">
                <span class="admin-roster-day-preference-option__rank" :aria-label="`Voorkeur ${index + 1}`">{{ index + 1 }}</span>
                <strong>{{ module.label }}</strong>
                <span v-if="module.key === 'Voedsel-Innovatie'" class="admin-roster-generator__rank-fixed">Vast op 1</span>
                <div v-else class="admin-roster-generator__rank-actions">
                  <button type="button" :aria-label="`${module.label} eerder`" :title="`${module.label} eerder`"
                    :disabled="loading || applying || index <= firstMovableRank"
                    @click="moveModulePreference(index, -1)">↑</button>
                  <button type="button" :aria-label="`${module.label} later`" :title="`${module.label} later`"
                    :disabled="loading || applying || index === rankedModules.length - 1"
                    @click="moveModulePreference(index, 1)">↓</button>
                </div>
              </li>
            </ol>
            <p class="admin-field__help">
              Voorkeur 1 weegt het zwaarst, daarna 2, 3 enzovoort.
              <template v-if="firstMovableRank">Voedsel Innovatie blijft altijd op 1.</template>
              De wizard plant binnen de normale capaciteit en zoekt daarna een zuinige indeling.
              Groepen kunnen dus op verschillende modules starten.
            </p>
          </section>
          <section v-if="supervisionModules.length" class="admin-roster-generator__preference-card admin-roster-generator__preference-card--supervision"
            aria-labelledby="lesson-supervision-heading">
            <header class="admin-roster-generator__preference-heading">
              <span class="admin-roster-generator__preference-number" aria-hidden="true">2</span>
              <div><h4 id="lesson-supervision-heading">Wie begeleidt deze modules?</h4></div>
            </header>
            <p class="admin-field__help">Kies per module. Deze keuze geldt voor alle groepen van deze school.</p>
            <div class="admin-roster-generator__supervision-options">
              <AdminSelect
                v-for="module in supervisionModules"
                :key="module.key"
                :label="module.label"
                :model-value="lessonPreferences.supervision[module.key] ?? 'geofort'"
                :options="supervisionOptions"
                :allow-empty="false"
                :disabled="loading || applying"
                @update:model-value="setSupervision(module.key, $event)"
              />
            </div>
            <p class="admin-field__help">
              Bij GeoFort-begeleiding vul je later de naam in. Eén GeoFort-begeleider mag bij KE en
              Vleermuizen twee groepen tegelijk begeleiden. Schoolbegeleiding wordt expliciet in het rooster vastgelegd.
            </p>
          </section>
          <div class="admin-inline-notice admin-roster-generator__lesson-notice">
            <p>De harde regels en normale modulecapaciteit blijven gelden. Ochtendmodules krijgen altijd GeoFort-begeleiding.</p>
          </div>
        </div>

        <template v-else>
          <nav class="admin-roster-personnel-substeps" aria-label="Personeelsinstellingen">
            <button
              type="button"
              :aria-pressed="personnelSubstep === 'selection'"
              @click="personnelSubstep = 'selection'"
            >
              <span>4A</span>
              Beschikbare mensen
            </button>
            <button
              type="button"
              :aria-pressed="personnelSubstep === 'preferences'"
              @click="personnelSubstep = 'preferences'"
            >
              <span>4B</span>
              Dagvoorkeuren
            </button>
          </nav>

          <div v-show="personnelSubstep === 'selection'">
            <p v-if="hasSchoolFallback" class="admin-field__help">
              Bij Klimaat Experience en Vleermuizen Speurtuin kiest de generator eerst geschikte GeoFort-begeleiding.
              Als niemand kan worden ingezet, wordt automatisch School vastgelegd.
            </p>

            <section v-if="staffingAdvice.hasVi" class="admin-roster-wizard-section">
              <h4>Voedsel Innovatie — kok</h4>
              <AdminSelect label="Kok voor deze dag" :model-value="cookStaffId === null ? '' : String(cookStaffId)" @update:model-value="cookStaffId = $event ? Number($event) : null" placeholder="Geen kok ingevuld" :options="[...(cookStaffId !== null && !cookCatalog.some(m=>m.id===cookStaffId) ? [{value:String(cookStaffId),label:'Bestaande kok — niet meer bevoegd/actief',disabled:true}] : []),...cookCatalog.map(m=>({value:String(m.id),label:m.name}))]" />
              <p class="admin-field__help">
                Kies expliciet een actieve medewerker die mag koken. De opgeslagen kokkeuze blijft aan diens personeels-ID gekoppeld. Tijdens VI kan de kok niet tegelijk begeleiden.
              </p>
            </section>

            <div class="admin-roster-staff-advice">
              <article>
                <span>Minimaal begeleiders tegelijk</span>
                <strong>{{ staffingAdvice.minimumGuides }}</strong>
                <small>Harde GeoFort-bemensing, exclusief kok</small>
              </article>
              <article>
                <span>Aanbevolen begeleiders</span>
                <strong>{{ staffingAdvice.preferredGuides }}</strong>
                <small>Met GeoFort waar begeleiding mogelijk is</small>
              </article>
              <article :class="{ 'is-warning': staffingAdvice.selectedPeople < staffingAdvice.recommendedPeople }">
                <span>Personen geselecteerd</span>
                <strong>{{ staffingAdvice.selectedPeople }}</strong>
                <small v-if="staffingAdvice.hasVi">
                  Richtwaarde incl. kok: {{ staffingAdvice.recommendedPeople }}
                </small>
                <small v-else>
                  Richtwaarde: {{ staffingAdvice.recommendedPeople }}
                </small>
              </article>
            </div>

            <div class="admin-roster-staff-coverage">
              <article
                v-for="item in staffingAdvice.modules"
                :key="item.module.key"
                :class="{
                  'is-warning':
                    item.module.minimumGeoFortStaff > 0
                    && item.eligibleSelected.length === 0,
                }"
              >
                <div>
                  <strong>{{ item.module.label }}</strong>
                  <span v-if="hasSchoolFallback && item.module.schoolSupervisionAllowed">Eerst GeoFort, anders automatisch School</span>
                  <span v-else>GeoFort-begeleiding vereist</span>
                </div>
                <div>
                  <span>Geselecteerd geschikt: {{ item.eligibleSelected.length }}</span>
                  <small>{{ item.eligibleSelected.map((member) => member.name).join(", ") || "niemand" }}</small>
                </div>
              </article>
            </div>

            <div class="admin-roster-staff-picker__actions">
              <div v-if="companion" class="admin-booking-filters__sort-toggle" role="group" aria-label="Docenten tonen">
                <button type="button" :aria-pressed="staffFilter === 'existing'" @click="staffFilter = 'existing'">Al ingezet</button>
                <button type="button" :aria-pressed="staffFilter === 'all'" @click="staffFilter = 'all'">Iedereen</button>
              </div>
              <div class="roster-staff-selection-actions">
                <AdminButton variant="ghost" @click="selectAllStaff">Alle begeleiders selecteren</AdminButton>
                <AdminButton variant="ghost" @click="clearStaff">Selectie wissen</AdminButton>
              </div>
            </div>

            <p v-if="!visibleGuideCatalog.length" role="status">Geen al ingezette begeleiders om te tonen. Kies Iedereen voor de overige docenten.</p>
            <div class="admin-roster-staff-picker">
              <label
                v-for="member in visibleGuideCatalog"
                :key="member.id"
                class="admin-roster-staff-picker__member"
                :class="{ 'is-selected': selectedStaffIds.includes(member.id) }"
              >
                <input
                  type="checkbox"
                  :checked="selectedStaffIds.includes(member.id)"
                  :disabled="!selectedStaffIds.includes(member.id) && pairedAvailability(member).length === 0"
                  @change="toggleStaff(member.id)"
                >
                <div>
                  <strong>{{ member.name }}</strong>
                  <span v-if="companion" class="paired-staff-tags">
                    <span class="paired-staff-tag" :class="{ 'paired-staff-tag--existing': companionStaff.some(p => p.id === member.id) }">{{ companionStaff.some(p => p.id === member.id) ? 'Al ingezet' : 'Extra medewerker' }}</span>
                    <span v-if="pairedAvailability(member).length === 0" class="paired-staff-tag paired-staff-tag--busy">Geen vrije inzet</span>
                  </span>
                  <small v-if="companionStaff.some(p => p.id === member.id)" class="paired-staff-status">{{ staffUseLabel(member) }}</small>
                  <span>{{ member.employmentType === "volunteer" ? "Vrijwilliger" : "Betaald" }}</span>
                  <span>
                    <template v-for="(preference, index) in member.preferences" :key="preference.moduleKey">
                      {{ preference.rank }}. {{ preference.moduleLabel }}<template v-if="index < member.preferences.length - 1"> · </template>
                    </template>
                  </span>
                </div>
              </label>
            </div>

            <div class="admin-roster-personnel-substep-actions">
              <AdminButton variant="secondary" @click="personnelSubstep = 'preferences'">
                Naar 4B · dagvoorkeuren
              </AdminButton>
            </div>
          </div>

          <div v-show="personnelSubstep === 'preferences'">
            <p class="admin-field__help">
              Kies per geselecteerde begeleider maximaal drie voorkeursmodules.
            </p>

            <div v-if="selectedStaff.length === 0" class="admin-inline-notice admin-inline-notice--warning">
              Selecteer eerst begeleiders bij stap 4A.
            </div>

            <div v-else class="admin-roster-day-preferences">
              <article
                v-for="member in selectedStaff"
                :key="member.id"
                class="admin-roster-day-preference-card"
              >
                <header>
                  <div>
                    <strong>{{ member.name }}</strong>
                    <span>{{ member.employmentType === "volunteer" ? "Vrijwilliger" : "Betaald" }}</span>
                  </div>
                  <span>{{ plannerPreferenceKeys(member.id).length }}/3 gekozen</span>
                </header>

                <div class="admin-roster-day-preference-options">
                  <button
                    v-for="module in eligibleModulesForStaff(member)"
                    :key="module.key"
                    type="button"
                    class="admin-roster-day-preference-option"
                    :class="{
                      'is-selected': plannerPreferencePosition(member.id, module.key) !== null,
                      'is-maxed':
                        plannerPreferenceKeys(member.id).length >= 3
                        && plannerPreferencePosition(member.id, module.key) === null,
                    }"
                    :aria-pressed="plannerPreferencePosition(member.id, module.key) !== null"
                    :aria-disabled="
                      plannerPreferenceKeys(member.id).length >= 3
                      && plannerPreferencePosition(member.id, module.key) === null
                    "
                    @click="togglePlannerPreference(member.id, module.key)"
                  >
                    <span
                      v-if="plannerPreferencePosition(member.id, module.key) !== null"
                      class="admin-roster-day-preference-option__rank"
                    >
                      {{ plannerPreferencePosition(member.id, module.key) }}
                    </span>
                    <span v-else class="admin-roster-day-preference-option__rank is-empty">+</span>
                    <span>
                      <strong>{{ module.label }}</strong>
                      <small>
                        Algemene voorkeur #{{ generalPreferenceRank(member, module.key) }}
                      </small>
                    </span>
                  </button>
                </div>

                <small class="admin-field__help">
                  Geen keuze betekent: gebruik alleen de algemene voorkeurslijst van deze begeleider.
                </small>
              </article>
            </div>

            <div class="admin-roster-personnel-substep-actions">
              <AdminButton variant="ghost" @click="personnelSubstep = 'selection'">
                Terug naar 4A
              </AdminButton>
            </div>
          </div>
        </template>
      </section>

      <section v-else>
        <p class="admin-eyebrow">Stap 5</p>
        <h3>Voorstel genereren</h3>
        <p>
          Maak eerst een voorstel. Ook een onvolledig resultaat kan als concept worden opgeslagen.
          De planner laat zien wat nog moet worden opgelost voordat het rooster definitief mag worden.
        </p>

        <AdminButton :loading="loading" @click="preview">Automatisch voorstel maken</AdminButton>

        <p v-if="error" class="admin-inline-error" role="alert">{{ error }}</p>

        <div v-if="proposal" class="admin-roster-generator__proposal">
          <div class="admin-roster-generator__summary">
            <div><span>Sessies</span><strong>{{ proposal.sessions.length }}</strong></div>
            <div>
              <span>Modus</span>
              <strong>{{ proposal.staffing.mode === "lesson_only" ? "Alleen lesrooster" : "Met personeel" }}</strong>
            </div>
            <div v-if="proposal.staffing.mode === 'with_staff'">
              <span>Open verplichte plekken</span>
              <strong>{{ proposal.staffing.unfilledRequiredAssignments }}</strong>
            </div>
            <div v-if="proposal.staffing.mode === 'with_staff'">
              <span>Kok</span>
              <strong>{{ proposal.staffing.cookSelected ? "ingevuld" : "open" }}</strong>
            </div>
          </div>



          <section class="admin-roster-generator-preview">
            <header>
              <div>
                <h4>{{ proposal.staffing.mode === "lesson_only" ? "Lesvoorstel zonder uitzonderingen" : "Beste gevonden voorbeeld" }}</h4>
                <ul v-if="proposal.staffing.mode === 'lesson_only' && lessonPreferencesSummary.length" class="admin-roster-generator__preference-summary" aria-label="Toegepaste roostervoorkeuren">
                  <li v-for="line in lessonPreferencesSummary" :key="line">{{ line }}</li>
                </ul>
                <p v-if="proposal.staffing.mode === 'lesson_only'">
                  Je voorkeuren zijn meegenomen binnen de normale capaciteit. GeoFort-begeleiders vul je na toepassen in via Handmatige correcties.
                </p>
                <p>
                  {{ proposal.canFinalize
                    ? "Controleer na opslaan het actuele rooster via Rooster controleren."
                    : "Dit concept bevat nog aandachtspunten, maar kan wel worden opgeslagen en verder bewerkt." }}
                </p>
              </div>
              <span class="admin-status">
                {{ proposal.canFinalize ? "Voorstel gereed voor controle" : "Concept met aandachtspunten" }}
              </span>
            </header>

            <div class="admin-roster-day__wrap">
              <table class="admin-roster-grid-table admin-roster-generator-preview__table">
                <thead>
                  <tr>
                    <th>Tijd</th>
                    <th v-for="group in plan.groups" :key="group.id">
                      {{ group.label }}
                    </th>
                  </tr>
                </thead>
                <tbody>
                  <tr
                    v-for="round in proposal.rounds"
                    :key="`${round.startTime}-${round.endTime}`"
                  >
                    <th class="admin-roster-day-table__time is-activity">
                      <strong>{{ round.startTime }}</strong>
                      <span>{{ round.endTime }}</span>
                    </th>
                    <td
                      v-for="group in plan.groups"
                      :key="group.id"
                      class="admin-roster-grid-cell"
                      :style="proposalSessionFor(round, group.id)
                        ? { backgroundColor: proposalSessionFor(round, group.id)?.color }
                        : undefined"
                    >
                      <template v-if="proposalSessionFor(round, group.id)">
                        <strong>{{ proposalSessionFor(round, group.id)?.moduleLabel }}</strong>
                        <span>{{ proposalStaffLabel(proposalSessionFor(round, group.id)) }}</span>
                      </template>
                      <span v-else>Open</span>
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>
          </section>

          <section v-if="proposal.hardIssues.length" class="admin-roster-hard-issues">
            <h4>Aandachtspunten vóór definitief maken</h4>
            <article
              v-for="issue in proposal.hardIssues"
              :key="`${issue.code}-${issue.startTime}-${issue.moduleKey}-${issue.message}`"
            >
              <strong>
                {{ issue.moduleLabel ?? "Roosterstructuur" }}
                <template v-if="issue.startTime">
                  · {{ issue.startTime }} - {{ issue.endTime }}
                </template>
              </strong>
              <span>{{ issue.message }}</span>
              <small v-if="issue.eligibleStaffNames.length">
                Geselecteerd en geschikt: {{ issue.eligibleStaffNames.join(", ") }}
              </small>
            </article>
          </section>

          <div v-if="proposal.staffing.staff.length" class="admin-roster-staff-result">
            <article v-for="member in proposal.staffing.staff" :key="member.id">
              <strong>{{ member.name }}</strong>
              <span>{{ hours(member.workMinutes) }} werk</span>
              <span>{{ member.load }} lesrondes</span>
              <span>{{ member.moduleCount }} module(s)</span>
              <span>{{ member.gaps }} tussenuren-indicatie</span>
              <span v-if="member.averagePreferenceRank !== null">gem. voorkeur {{ member.averagePreferenceRank }}</span>
            </article>
          </div>

          <ul v-if="proposal.warnings.length" class="admin-roster-generator__warnings">
            <li v-for="warning in proposal.warnings" :key="`${warning.code}-${warning.message}`">
              {{ warning.message }}
            </li>
          </ul>

          <label v-if="proposal.existingSessionCount > 0" class="admin-roster-generator__replace">
            <input v-model="replaceExisting" type="checkbox">
            <span>Vervang de {{ proposal.existingSessionCount }} bestaande sessie(s).</span>
          </label>

          <label v-if="proposal.lessonConflictSignature" class="admin-roster-generator__replace">
            <input v-model="lessonConflictsAccepted" type="checkbox" :disabled="!proposal.canApply">
            <span>Ik heb de lesconflicten hierboven bekeken en accepteer deze indeling voorlopig als concept. Definitief maken blijft geblokkeerd zolang harde lesconflicten bestaan.</span>
          </label>
          <p v-if="error" class="admin-inline-error" role="alert">{{ error }}</p>
          <p v-if="!proposal.canApply" class="admin-inline-error">Los eerst de personeelsconflicten hierboven op en bereken een nieuw voorstel.</p>
          <AdminButton
            :loading="applying"
            :disabled="!!(proposal.lessonConflictSignature && !lessonConflictsAccepted) || !proposal.canApply || companionLoading || !!companionError || (proposal.existingSessionCount > 0 && !replaceExisting)"
            @click="apply"
          >
            {{ proposal.canFinalize ? "Voorstel toepassen" : "Concept toepassen met waarschuwingen" }}
          </AdminButton>
        </div>
      </section>
    </div>

    <footer class="admin-roster-setup-wizard__footer">
      <AdminButton
        variant="secondary"
        :disabled="currentStep === 1 || loading || applying"
        @click="previous"
      >
        Vorige
      </AdminButton>
      <span>Stap {{ currentStep }} van 5</span>
      <AdminButton v-if="currentStep < 5" :disabled="loading || applying" @click="next">
        Volgende
      </AdminButton>
      <AdminButton v-else variant="ghost" :disabled="loading || applying" @click="resetWizard">
        Wizard opnieuw controleren
      </AdminButton>
    </footer>
  </section>
</template>

<style scoped>
.roster-staff-selection-actions { display: flex; flex-direction: column; align-items: flex-start; gap: .5rem; margin: .9rem 0 1rem; }
.roster-staff-selection-actions .admin-button { min-height: 2.75rem; padding: .45rem .75rem; font-size: .875rem; font-weight: 500; box-shadow: none; }

.paired-staff-tags { display:flex; flex-wrap:wrap; gap:.35rem; margin:.3rem 0; }
.paired-staff-tag { display:inline-flex; width:fit-content; padding:.18rem .55rem; border-radius:999px; background:#edf0f5; border:1px solid var(--admin-border); font-size:.75rem; font-weight:600; color:var(--color-main-blue); }
.paired-staff-tag--existing { background:#e8efff; border-color:#aabdeb; }
.paired-staff-tag--busy { background:#fff3d6; border-color:#e4c277; color:#624900; }
</style>

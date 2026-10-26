<script setup lang="ts">
import AdminCheckbox from "../form/AdminCheckbox.vue";
import AdminSelect from "../form/AdminSelect.vue";
import { computed, inject, onBeforeUnmount, ref, watch } from "vue";

import { fetchDashboardRoster, fetchDashboardRosters } from '../../services/dashboardRosterApi';
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
const useExistingLessons = ref(false);
const optimizeExistingLessons = ref(false);
const hasSavedLessons = computed(() => props.plan.planning.sessions.length > 0);
const staffingSavedLessons = computed(() => staffingMode.value === "with_staff" && useExistingLessons.value && hasSavedLessons.value);
function startExistingStaffing(optimize = false): void {
  if (props.plan.status !== "concept" || !hasSavedLessons.value) return;
  staffingMode.value = "with_staff";
  useExistingLessons.value = true;
  optimizeExistingLessons.value = optimize;
  currentStep.value = 4;
  personnelSubstep.value = "selection";
  proposal.value = null;
}
defineExpose({ startExistingStaffing, hasUnappliedProposal: () => proposal.value !== null });
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
const sharedFoodRounds = computed(() => {
  if (!companion.value || !props.plan.planning.modules.some(m => m.key === "Voedsel-Innovatie")) return [];
  const own = staffingSavedLessons.value ? props.plan.planning.sessions.filter(s => s.moduleKey === "Voedsel-Innovatie") :
    props.plan.groups.length === 1 ? rounds.value.map(r => ({ ...r, groupIds: [props.plan.groups[0]!.id] })) : [];
  return own.filter(s => companion.value!.planning.sessions.some(other => other.moduleKey === "Voedsel-Innovatie"
    && other.startTime.slice(0,5) === s.startTime.slice(0,5) && other.endTime.slice(0,5) === s.endTime.slice(0,5)
    && other.groupIds.length + s.groupIds.length <= 2))
    .map(s => ({ label: "Gedeelde Voedsel Innovatie", startTime: s.startTime.slice(0,5), endTime: s.endTime.slice(0,5) }));
});
const sharedFoodGuides = computed(() => companionStaff.value.filter(member => member.slots.some(slot => !slot.cook
  && slot.moduleKey === "Voedsel-Innovatie" && sharedFoodRounds.value.some(r => r.startTime === slot.startTime && r.endTime === slot.endTime))));
watch(sharedFoodGuides, guides => {
  selectedStaffIds.value = [...new Set([...selectedStaffIds.value, ...guides.map(g => g.id)])];
});
function pairedAvailability(member: RosterStaffMember) {
  return availablePairedRounds(member, companionStaff.value.find(p => p.id === member.id), staffingSavedLessons.value ? props.plan.planning.sessions.map(s => ({ label: s.moduleLabel, startTime: s.startTime, endTime: s.endTime })) : rounds.value, props.plan.planning.modules.map(m => m.key), sharedFoodRounds.value);
}
function staffUseLabel(member: RosterStaffMember): string {
  const use = companionStaff.value.find(p => p.id === member.id);
  if (!use) return companion.value ? 'Extra medewerker voor deze bezoekdag' : '';
  const free = pairedAvailability(member);
  return free.length ? `Al ingezet · nog mogelijk: ${free.map(r => `${r.startTime}–${r.endTime}`).join(', ')}` : 'Al ingezet · geen passende vrije lesronde';
}

const hasSchoolFallback = computed(() =>
  !staffingSavedLessons.value &&  props.plan.planning.modules.some((module) => module.schoolSupervisionAllowed),
);
const steps = computed<AdminWizardStep[]>(() =>
  ["Basis", "Dagindeling", "Programma", staffingMode.value === "lesson_only" ? "Voorkeuren" : "Personeel", "Genereren"].map(
    (label, index) => {
      const step = index + 1;
      if (step === currentStep.value) return { label, information: index === 1 || index === 2, state: "current" };
      if (step < currentStep.value) return { label, information: index === 1 || index === 2, state: "completed" };
      if (step === currentStep.value + 1) return { label, information: index === 1 || index === 2, state: "available" };
      return { label, information: index === 1 || index === 2, state: "upcoming" };
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
  if (props.plan.education.programVersion === "day-four-2027" && !module.schoolSupervisionAllowed) return module.key === "Voedsel-Innovatie" ? Math.ceil(groupCount / 2) : groupCount;
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
  if (props.plan.education.programVersion === "day-four-2027" && !module.schoolSupervisionAllowed) return module.key === "Voedsel-Innovatie" ? Math.ceil(groupCount / 2) : groupCount;
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
      needsGuide: staffingSavedLessons.value
        ? props.plan.planning.sessions.some(session => session.moduleKey === module.key && session.minimumGeoFortStaff > 0)
        : module.minimumGeoFortStaff > 0,
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
  if (staffingSavedLessons.value) {
    minimumGuides = Math.max(0, ...props.plan.planning.sessions.map(point =>
      props.plan.planning.sessions
        .filter(session => session.startTime <= point.startTime && session.endTime > point.startTime)
        .reduce((sum, session) => sum + (session.schoolSupervisionAllowed ? 0 : session.minimumGeoFortStaff), 0),
    ));
    preferredGuides = Math.max(0, ...props.plan.planning.sessions.map(point =>
      props.plan.planning.sessions.filter(session => session.startTime <= point.startTime && session.endTime > point.startTime)
        .reduce((sum, session) => sum + (session.groupSupervision?.length && session.groupSupervision.every(group => group.kind === "school") ? 0 : Math.max(1, session.minimumGeoFortStaff)), 0),
    ));
  }
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
  useExistingLessons.value = hasSavedLessons.value;
  optimizeExistingLessons.value = false;
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
  selectedStaffIds.value = props.plan.planning.selectedStaffIds.filter(id => guideCatalog.value.some(member => member.id === id && eligibleModulesForStaff(member).length > 0)).filter((id) =>
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
watch([staffingMode, cookStaffId, useExistingLessons, optimizeExistingLessons], () => { proposal.value = null; replaceExisting.value = false; });
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
  if (!wasSelected && (!eligibleModulesForStaff(guideCatalog.value.find(m => m.id === id)!).length || !pairedAvailability(guideCatalog.value.find(m => m.id === id)!).length)) return;
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
  selectedStaffIds.value = guideCatalog.value.filter(member => eligibleModulesForStaff(member).length > 0 && pairedAvailability(member).length > 0).map((member) => member.id);
  proposal.value = null;
}
function clearStaff(): void {
  selectedStaffIds.value = [];
  plannerModulePreferences.value = {};
  proposal.value = null;
}
function generationOptions(): RosterGenerationOptions {
  return {
    staffingMode: staffingMode.value,
    useExistingLessons: staffingSavedLessons.value,
    optimizeExistingLessons: staffingSavedLessons.value && optimizeExistingLessons.value,
    lessonPreferences: !staffingSavedLessons.value || optimizeExistingLessons.value ? {
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

let cachedPreview: { key: string; proposal: RosterGenerationProposal } | null = null;
async function preview(): Promise<void> {
  if (loading.value || companionLoading.value || companionError.value) return;
  loading.value = true;
  error.value = null;

  try {
    const [fresh, rosterList] = await Promise.all([fetchDashboardRoster(props.plan.id), fetchDashboardRosters()]);
    if (fresh.plan.revision !== props.plan.revision) throw new Error("Dit rooster is gewijzigd. Herlaad de wizard voordat je verdergaat.");
    const companionId = fresh.plan.jointPlanning?.planIds.find(id => id !== props.plan.id);
    const freshCompanion = companionId ? (await fetchDashboardRoster(companionId)).plan : null;
    const key = JSON.stringify([fresh.plan, freshCompanion, rosterList.items, rounds.value, generationOptions()]);
    if (cachedPreview?.key === key) {
      proposal.value = cachedPreview.proposal;
      return;
    }
    proposal.value = await previewGeneratedRoster(
      props.plan.id,
      rounds.value,
      generationOptions(),
      csrfToken,
    );
    cachedPreview = { key, proposal: proposal.value };
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
      proposal.value.staffingSignature ?? null,
    );
    proposal.value = null;
    cachedPreview = null;
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
      session.startTime <= round.startTime
      && session.endTime >= round.endTime
      && session.groupIds.includes(groupId),
  ) ?? null;
}

function proposalStaffLabel(
  session: RosterGenerationProposal["sessions"][number] | null,
  groupId: number,
): string {
  if (!session) return "Open";

  const group = session.groupStaffAssignments?.find(item => item.groupId === groupId);
  if (group?.kind === "school" || (!group && session.schoolFallback)) return "Schoolbegeleiding";
  const ids = group?.assignedStaffIds ?? session.assignedStaffIds;

  if (ids.length === 0) {
    return staffingMode.value === "lesson_only" ? "GeoFort · nog in te vullen" : "OPEN";
  }

  const names = ids
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

    <p v-if="staffingSavedLessons" class="admin-inline-notice admin-inline-notice--plain admin-roster-generator__saved-notice">
      <strong>Docenten indelen op basis van opgeslagen lessen</strong>
      De {{ plan.planning.sessions.length }} opgeslagen lessen vormen het uitgangspunt. Kies je medewerkers en bekijk het voorstel.
      Bestaande schoolbegeleiding blijft behouden. Verplichte GeoFort-lessen krijgen voorrang op KE en Vleermuizen.
    </p>
    <fieldset v-if="staffingSavedLessons && currentStep === 4" class="admin-roster-guided-choice" :disabled="loading || applying">
      <legend>Ruimte om het rooster te verbeteren</legend>
      <div class="admin-booking-filters__sort-toggle" role="group" aria-label="Lesindeling">
        <button type="button" :aria-pressed="!optimizeExistingLessons" @click="optimizeExistingLessons = false">Lesindeling behouden</button>
        <button type="button" :aria-pressed="optimizeExistingLessons" @click="optimizeExistingLessons = true">Lessen mogen verschuiven</button>
      </div>
      <p v-if="optimizeExistingLessons">De wizard vergelijkt met dezelfde medewerkers op de bestaande lesindeling. Je ziet alle verschuivingen vóór toepassen. Groepen, lesduur en dagindeling blijven behouden. KE en Vleermuizen kunnen naar schoolbegeleiding om verplichte lessen te bemensen; Voedsel Innovatie blijft op zijn tijd.</p>
    </fieldset>
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
              <span>Een lesvoorstel binnen de modulecapaciteit. Gelijktijdige Earth Watch-, Stop de Klimaat Klok- en Minecraft-lessen krijgen ieder een eigen docentplek. Docenten kies je later.</span>
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
        <label v-if="staffingMode === 'with_staff' && hasSavedLessons" class="admin-roster-generator__replace">
          <input v-model="useExistingLessons" type="checkbox" :disabled="loading || applying">
          <span><strong>Bestaande lesindeling gebruiken</strong><br>
            Gebruik de opgeslagen lessen als uitgangspunt en kies hierboven of lessen mogen verschuiven.
          </span>
        </label>
        <p v-if="staffingMode === 'with_staff' && hasSavedLessons && !useExistingLessons" class="admin-inline-notice admin-inline-notice--plain admin-inline-notice--warning">
          Je maakt een nieuwe lesindeling én docententoewijzing. Bij toepassen worden de bestaande lessen vervangen.
        </p>
      </section>

      <section v-else-if="currentStep === 2" class="admin-roster-information" title="Ter informatie: deze gegevens staan al vast. Je hoeft hier niets in te vullen.">
        <p class="admin-roster-information__label"><span aria-hidden="true">ⓘ</span> Ter informatie — niets invullen</p>
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

            <div class="admin-roster-day-template__time">
              <span>{{ item.startTime }}</span><span aria-hidden="true">→</span><span>{{ item.endTime }}</span>
            </div>
          </article>
        </div>
      </section>

      <section v-else-if="currentStep === 3" class="admin-roster-information" title="Ter informatie: deze gegevens staan al vast. Je hoeft hier niets in te vullen.">
        <p class="admin-roster-information__label"><span aria-hidden="true">ⓘ</span> Ter informatie — niets invullen</p>
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

        <div v-if="!staffingSavedLessons || optimizeExistingLessons" class="admin-roster-generator__lesson-preferences">
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
          <section v-if="staffingMode === 'lesson_only' && supervisionModules.length" class="admin-roster-generator__preference-card admin-roster-generator__preference-card--supervision"
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
        </div>

        <template v-if="staffingMode === 'with_staff'">
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
              De generator vult eerst de verplichte GeoFort-lessen. Daarna krijgen Klimaat Experience en Vleermuizen Speurtuin beschikbare GeoFort-begeleiding.
              Als niemand kan worden ingezet, wordt automatisch School vastgelegd.
            </p>

            <section v-if="staffingAdvice.hasVi" class="admin-roster-wizard-section">
              <h4>Voedsel Innovatie — kok</h4>
              <AdminSelect label="Kok voor deze dag" :model-value="cookStaffId === null ? '' : String(cookStaffId)" @update:model-value="cookStaffId = $event ? Number($event) : null" placeholder="Geen kok ingevuld" :options="[...(cookStaffId !== null && !cookCatalog.some(m=>m.id===cookStaffId) ? [{value:String(cookStaffId),label:'Bestaande kok — niet meer bevoegd/actief',disabled:true}] : []),...cookCatalog.map(m=>({value:String(m.id),label:m.name}))]" />
              <p class="admin-field__help">
                Bij Voedsel Innovatie delen maximaal twee groepen één kok en één begeleider, ook bij gekoppelde scholen. Tijdens VI kan de kok niet tegelijk begeleiden.
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
                <small>{{ staffingSavedLessons ? "Inclusief KE en Vleermuizen waar personeel beschikbaar is" : "Inclusief GeoFort-begeleiding bij KE en Vleermuizen; verplichte lessen gaan voor" }}</small>
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
                    item.needsGuide
                    && item.eligibleSelected.length === 0,
                }"
              >
                <div>
                  <strong>{{ item.module.key === 'Voedsel-Innovatie' ? 'Voedsel Innovatie · begeleider' : item.module.label }}</strong>
                  <span v-if="staffingSavedLessons && !item.needsGuide">Schoolbegeleiding voor alle opgeslagen lessen.</span>
                  <span v-else-if="staffingSavedLessons && item.module.schoolSupervisionAllowed">Schoolbegeleiding blijft behouden. GeoFort begeleidt de overige groepen als verplichte lessen bezet zijn.</span>
                  <span v-else-if="hasSchoolFallback && item.module.schoolSupervisionAllowed">Eerst GeoFort, anders automatisch School</span>
                  <span v-else>GeoFort-begeleiding vereist</span>
                </div>
                <div v-if="!staffingSavedLessons || item.needsGuide">
                  <span>Geselecteerd geschikt: {{ item.eligibleSelected.length }}</span>
                  <div class="admin-roster-name-grid"><span v-for="person in item.eligibleSelected" :key="person.id">{{ person.name }}</span><span v-if="!item.eligibleSelected.length">Niemand geselecteerd</span></div>
                </div>
              </article>
            </div>

            <p v-if="sharedFoodGuides.length" class="admin-field__help">Gedeelde Voedsel Innovatie: {{ sharedFoodGuides.map(g => g.name).join(", ") }} is alvast geselecteerd. Twee groepen delen hier één kok en één begeleider.</p>
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
                <AdminCheckbox
                  :model-value="selectedStaffIds.includes(member.id)"
                  :label="'Selecteer ' + member.name"
                  :disabled="!selectedStaffIds.includes(member.id) && (eligibleModulesForStaff(member).length === 0 || pairedAvailability(member).length === 0)"
                  @update:model-value="toggleStaff(member.id)"
                />
                <div class="admin-roster-person__body">
                  <div class="admin-roster-person__heading"><strong class="admin-roster-person__name">{{ member.name }}</strong>
                  <small v-if="eligibleModulesForStaff(member).length === 0">Geen vaardigheid voor de gekozen modules</small>
                  <span v-if="companion" class="paired-staff-tags">
                    <span class="paired-staff-tag" :class="{ 'paired-staff-tag--existing': companionStaff.some(p => p.id === member.id) }">{{ companionStaff.some(p => p.id === member.id) ? 'Al ingezet' : 'Extra medewerker' }}</span>
                    <span v-if="pairedAvailability(member).length === 0" class="paired-staff-tag paired-staff-tag--busy">Geen vrije inzet</span>
                  </span>
                  <small v-if="companionStaff.some(p => p.id === member.id)" class="paired-staff-status">{{ staffUseLabel(member) }}</small>
                  </div><div class="admin-roster-person__employment"><span>{{ member.employmentType === "volunteer" ? "Vrijwilliger" : "Betaald" }}</span></div>
                  <div class="admin-roster-person__skills" aria-label="Vaardigheden">
                    <span v-if="member.canCook"><b>Kok</b><span class="admin-roster-skill-name" tabindex="0">Voedsel Innovatie · kok</span></span>
                    <span v-for="preference in member.preferences" :key="preference.moduleKey"><b>{{ preference.rank }}</b><span class="admin-roster-skill-name" tabindex="0">{{ preference.moduleKey === 'Voedsel-Innovatie' ? 'Voedsel Innovatie · begeleider' : preference.moduleLabel }}</span></span>
                  </div>
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

            <div v-if="selectedStaff.length === 0" class="admin-inline-notice admin-inline-notice--plain admin-inline-notice--warning">
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
              <strong>{{ proposal.useExistingLessons ? "Docenten bij opgeslagen lessen" : proposal.staffing.mode === "lesson_only" ? "Alleen lesrooster" : "Met personeel" }}</strong>
            </div>
            <div v-if="proposal.staffing.mode === 'with_staff'">
              <span>Open verplichte plekken</span>
              <strong>{{ proposal.staffing.unfilledRequiredAssignments }}</strong>
            </div>
            <div v-if="proposal.staffing.mode === 'with_staff' && proposal.staffing.cookRequired">
              <span>Kok</span>
              <strong>{{ proposal.staffing.cookSelected ? "ingevuld" : "open" }}</strong>
            </div>
          </div>



          <section class="admin-roster-generator-preview">
            <header>
              <div>
                <h4>{{ proposal.useExistingLessons ? "Docentenvoorstel op jouw lesrooster" : proposal.staffing.mode === "lesson_only" ? "Lesvoorstel zonder uitzonderingen" : "Beste gevonden voorbeeld" }}</h4>
                <ul v-if="proposal.staffing.mode === 'lesson_only' && lessonPreferencesSummary.length" class="admin-roster-generator__preference-summary" aria-label="Toegepaste roostervoorkeuren">
                  <li v-for="line in lessonPreferencesSummary" :key="line">{{ line }}</li>
                </ul>
                <p v-if="proposal.staffing.mode === 'lesson_only'">
                  Je voorkeuren zijn meegenomen binnen de normale capaciteit. Voeg na toepassen docenten toe via Docenten toevoegen aan dit lesrooster.
                </p>
                <p>
                  {{ proposal.staffing.mode === "lesson_only" && proposal.hardIssues.length === 0
                    ? "De lesindeling past binnen de gecontroleerde capaciteit. GeoFort-docenten en de eventuele kok wijs je daarna toe."
                    : proposal.canFinalize
                      ? "Controleer na opslaan het actuele rooster via Rooster controleren."
                      : "Dit concept bevat nog aandachtspunten, maar kan wel worden opgeslagen en verder bewerkt." }}
                </p>
              </div>
              <span class="admin-status">
                {{ proposal.staffing.mode === "lesson_only" && proposal.hardIssues.length === 0 ? "Lesindeling akkoord · docenten volgen" : proposal.canFinalize ? "Voorstel gereed voor controle" : "Concept met aandachtspunten" }}
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
                        <span>{{ proposalStaffLabel(proposalSessionFor(round, group.id), group.id) }}</span>
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

          <section v-if="proposal.optimization" class="admin-roster-guided-result" aria-label="Vergelijking met opgeslagen lesindeling">
            <h4>Wat verandert er?</h4>
            <p>Dezelfde medewerkers en voorkeuren, eerst op de opgeslagen lesindeling en daarna op het voorstel. Tussenminuten zijn vrije minuten tussen eigen taken binnen de gezamenlijke lesblokken. Tijd zonder lessen, zoals een gezamenlijke lunchpauze, telt niet mee. Inzet bij andere gekoppelde of definitieve scholen telt mee en blijft vaststaan.</p>
            <div class="admin-roster-guided-metrics">
              <article><span>Tussenminuten · betaald</span><strong>{{ proposal.optimization.before.paidGapMinutes }} → {{ proposal.optimization.after.paidGapMinutes }} min</strong></article>
              <article><span>Tussenminuten · iedereen</span><strong>{{ proposal.optimization.before.gapMinutes }} → {{ proposal.optimization.after.gapMinutes }} min</strong></article>
              <article><span>Open docentplekken</span><strong>{{ proposal.optimization.before.unfilledAssignments }} → {{ proposal.optimization.after.unfilledAssignments }}</strong></article>
            </div>
            <p v-if="!proposal.optimization.changes.length" class="admin-inline-notice admin-inline-notice--plain">Geen betere verschuiving gevonden. De bestaande lestijden blijven behouden.</p>
            <div v-else class="admin-roster-guided-table">
              <table><caption>{{ proposal.optimization.changes.length }} lessen verschuiven</caption>
                <thead><tr><th scope="col">Les / groepen</th><th scope="col">Opgeslagen tijd</th><th scope="col">Voorgestelde tijd</th></tr></thead>
                <tbody><tr v-for="change in proposal.optimization.changes" :key="change.sessionId">
                  <th scope="row">{{ change.moduleLabel }}<small>{{ change.groupLabels.join(' · ') }}</small></th>
                  <td>{{ change.fromStart }}–{{ change.fromEnd }}</td><td>{{ change.toStart }}–{{ change.toEnd }}</td>
                </tr></tbody>
              </table>
            </div>
            <details><summary>Verschil per medewerker bekijken</summary>
              <div class="admin-roster-guided-table"><table>
                <thead><tr><th scope="col">Medewerker</th><th scope="col">Les- en kokminuten · voor → na</th><th scope="col">Tussenminuten · voor → na</th></tr></thead>
                <tbody><tr v-for="member in proposal.optimization.before.staff" :key="member.id">
                  <th scope="row">{{ member.name }}</th>
                  <td>{{ member.workMinutes }} → {{ proposal.optimization.after.staff.find(p => p.id === member.id)?.workMinutes ?? 0 }}</td>
                  <td>{{ member.gapMinutes }} → {{ proposal.optimization.after.staff.find(p => p.id === member.id)?.gapMinutes ?? 0 }}</td>
                </tr></tbody>
              </table></div>
            </details>
            <small>Beste gevonden voorstel na {{ proposal.optimization.candidateCount }} onderzochte indelingen.{{ proposal.optimization.searchLimited ? ' De zoekgrens is bereikt.' : '' }} Er kan nog een andere oplossing bestaan.</small>
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
            <span v-if="proposal.optimizeExistingLessons">Ik heb de vergelijking bekeken en pas deze docententoewijzingen en de getoonde lesverschuivingen toe. Schoolbegeleiding blijft behouden.</span>
            <span v-else-if="proposal.useExistingLessons">Ik pas deze docententoewijzingen toe op de bestaande lessen. Eerdere docent- en koktoewijzingen worden vervangen; de lesindeling en schoolbegeleiding blijven behouden.</span>
            <span v-else>Vervang de {{ proposal.existingSessionCount }} bestaande sessie(s).</span>
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
            {{ proposal.canFinalize || (proposal.staffing.mode === "lesson_only" && proposal.hardIssues.length === 0 && proposal.warnings.length === 0) ? "Voorstel toepassen" : "Concept toepassen met waarschuwingen" }}
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

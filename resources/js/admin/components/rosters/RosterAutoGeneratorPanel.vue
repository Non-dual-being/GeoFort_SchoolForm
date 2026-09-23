<script setup lang="ts">
import { computed, inject, ref, watch } from "vue";

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
  RosterStaffingMode,
} from "../../types/roster";

const props = defineProps<{ plan: DashboardRosterPlan }>();
const emit = defineEmits<{ applied: [] }>();

const bootstrap = inject(adminBootstrapKey);
if (!bootstrap) throw new Error("Admin bootstrapdata ontbreekt.");
const csrfToken = bootstrap.rosterGenerationCsrfToken;

const currentStep = ref(1);
const wizardMode = ref<"standard" | "custom">("standard");
const staffingMode = ref<RosterStaffingMode>("with_staff");
const preferGeoFortKe = ref(true);
const cookStaffId = ref<number | null>(null);
const rounds = ref<RosterGenerationRound[]>([]);
const selectedStaffIds = ref<number[]>([]);
const proposal = ref<RosterGenerationProposal | null>(null);
const replaceExisting = ref(false);
const loading = ref(false);
const applying = ref(false);
const error = ref<string | null>(null);

const steps = computed<AdminWizardStep[]>(() =>
  ["Basis", "Dagindeling", "Programma", "Personeel", "Genereren"].map(
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
  const r = rounds.value;

  if (props.plan.education.program === "ochtend") {
    return [
      { key: "arrival", type: "arrival", label: "Aankomst & welkom", startTime: "10:00", endTime: r[0]?.startTime ?? "10:15", roundIndex: null },
      { key: "round-1", type: "activity", label: r[0]?.label ?? "Ronde 1", startTime: r[0]?.startTime ?? "10:15", endTime: r[0]?.endTime ?? "10:35", roundIndex: 0 },
      { key: "round-2", type: "activity", label: r[1]?.label ?? "Ronde 2", startTime: r[1]?.startTime ?? "10:35", endTime: r[1]?.endTime ?? "10:55", roundIndex: 1 },
      { key: "break", type: "break", label: "Pauze", startTime: r[1]?.endTime ?? "10:55", endTime: r[2]?.startTime ?? "11:10", roundIndex: null },
      { key: "round-3", type: "activity", label: r[2]?.label ?? "Ronde 3", startTime: r[2]?.startTime ?? "11:10", endTime: r[2]?.endTime ?? "11:30", roundIndex: 2 },
      { key: "round-4", type: "activity", label: r[3]?.label ?? "Ronde 4", startTime: r[3]?.startTime ?? "11:30", endTime: r[3]?.endTime ?? "11:50", roundIndex: 3 },
      { key: "round-5", type: "activity", label: r[4]?.label ?? "Ronde 5", startTime: r[4]?.startTime ?? "11:50", endTime: r[4]?.endTime ?? "12:10", roundIndex: 4 },
      { key: "departure", type: "departure", label: "Afscheid & vertrek", startTime: r[4]?.endTime ?? "12:10", endTime: "12:15", roundIndex: null },
    ];
  }

  return [
    { key: "arrival", type: "arrival", label: "Aankomst & welkom", startTime: "10:00", endTime: r[0]?.startTime ?? "10:15", roundIndex: null },
    { key: "round-1", type: "activity", label: r[0]?.label ?? "Ronde 1", startTime: r[0]?.startTime ?? "10:15", endTime: r[0]?.endTime ?? "11:00", roundIndex: 0 },
    { key: "break", type: "break", label: "Pauze", startTime: r[0]?.endTime ?? "11:00", endTime: r[1]?.startTime ?? "11:15", roundIndex: null },
    { key: "round-2", type: "activity", label: r[1]?.label ?? "Ronde 2", startTime: r[1]?.startTime ?? "11:15", endTime: r[1]?.endTime ?? "12:00", roundIndex: 1 },
    { key: "round-3", type: "activity", label: r[2]?.label ?? "Ronde 3", startTime: r[2]?.startTime ?? "12:00", endTime: r[2]?.endTime ?? "12:45", roundIndex: 2 },
    { key: "lunch", type: "lunch", label: "Lunch", startTime: r[2]?.endTime ?? "12:45", endTime: r[3]?.startTime ?? "13:15", roundIndex: null },
    { key: "round-4", type: "activity", label: r[3]?.label ?? "Ronde 4", startTime: r[3]?.startTime ?? "13:15", endTime: r[3]?.endTime ?? "14:00", roundIndex: 3 },
    { key: "round-5", type: "activity", label: r[4]?.label ?? "Ronde 5", startTime: r[4]?.startTime ?? "14:00", endTime: r[4]?.endTime ?? "14:45", roundIndex: 4 },
    { key: "departure", type: "departure", label: "Afscheid & vertrek", startTime: r[4]?.endTime ?? "14:45", endTime: "15:00", roundIndex: null },
  ];
});

const guideCatalog = computed(() =>
  props.plan.planning.staffCatalog.filter((member) => member.isActive && member.canGuide),
);

const cookCatalog = computed(() =>
  props.plan.planning.staffCatalog.filter((member) => member.isActive && member.canCook),
);

const selectedStaff = computed(() => {
  const selected = new Set(selectedStaffIds.value);
  return guideCatalog.value.filter((member) => selected.has(member.id));
});

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
    let minimum = 0;
    let preferred = 0;

    for (let groupIndex = 0; groupIndex < props.plan.groups.length; groupIndex += 1) {
      const module = orderedModules[(groupIndex + roundIndex) % orderedModules.length];
      if (!module) continue;

      minimum += module.minimumGeoFortStaff;
      preferred += module.minimumGeoFortStaff;
      if (
        preferGeoFortKe.value
        && module.key === "Klimaat-Experience"
        && module.schoolSupervisionAllowed
      ) {
        preferred += 1;
      }
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
  if (props.plan.planning.staffingSettings.cookStaffId !== null) {
    return props.plan.planning.staffingSettings.cookStaffId;
  }

  return cookCatalog.value.find((member) => member.name === "Nelleke de With")?.id ?? null;
}

function resetWizard(): void {
  currentStep.value = 1;
  wizardMode.value = "standard";
  rounds.value = props.plan.planning.generation.defaultRounds.map((round) => ({ ...round }));
  staffingMode.value = props.plan.planning.staffingSettings.staffingMode ?? "with_staff";
  preferGeoFortKe.value = props.plan.planning.staffingSettings.preferGeoFortKe ?? true;
  selectedStaffIds.value = props.plan.planning.selectedStaffIds.filter((id) =>
    guideCatalog.value.some((member) => member.id === id),
  );
  cookStaffId.value = defaultCookId();
  proposal.value = null;
  replaceExisting.value = false;
  error.value = null;
}

watch(() => props.plan.id, resetWizard, { immediate: true });
watch([staffingMode, preferGeoFortKe, cookStaffId], () => { proposal.value = null; });

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
  selectedStaffIds.value = selectedStaffIds.value.includes(id)
    ? selectedStaffIds.value.filter((staffId) => staffId !== id)
    : [...selectedStaffIds.value, id];
  proposal.value = null;
}
function selectAllStaff(): void {
  selectedStaffIds.value = guideCatalog.value.map((member) => member.id);
  proposal.value = null;
}
function clearStaff(): void {
  selectedStaffIds.value = [];
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
    staffIds: staffingMode.value === "with_staff" ? selectedStaffIds.value : [],
    preferGeoFortKe: preferGeoFortKe.value,
    cookStaffId: staffingMode.value === "with_staff" ? cookStaffId.value : null,
  };
}

async function preview(): Promise<void> {
  if (loading.value) return;
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
    error.value = requestError instanceof Error
      ? requestError.message
      : "Het automatische roostervoorstel kon niet worden gemaakt.";
  } finally {
    loading.value = false;
  }
}

async function apply(): Promise<void> {
  if (!proposal.value || applying.value) return;
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

function hours(minutes: number): string {
  return `${(minutes / 60).toFixed(1).replace(".0", "")} u`;
}
</script>

<template>
  <section class="admin-card admin-roster-generator admin-roster-setup-wizard">
    <div class="admin-roster-generator__heading">
      <div>
        <p class="admin-eyebrow">Rooster instellen</p>
        <h2>Conceptrooster-wizard</h2>
      </div>
      <span class="admin-status admin-status--option">Concept</span>
    </div>

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

        <div class="admin-roster-mode-choice">
          <label>
            <input v-model="wizardMode" type="radio" value="standard">
            <div><strong>Standaard</strong><span>Gebruik de GeoFort-standaardwaarden.</span></div>
          </label>
          <label>
            <input v-model="wizardMode" type="radio" value="custom">
            <div><strong>Aangepast</strong><span>Maak rondetijden handmatig aanpasbaar.</span></div>
          </label>
        </div>

        <h4>Wat wil je genereren?</h4>
        <div class="admin-roster-mode-choice">
          <label>
            <input v-model="staffingMode" type="radio" value="lesson_only">
            <div>
              <strong>Alleen lesrooster</strong>
              <span>Geen personeel tijdens generatie. Personeel kan later per cel worden ingevuld.</span>
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
        <p>
          Tijden worden hier beheerd. Bij Handmatige correcties zijn tijden bewust alleen-lezen.
        </p>

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
                  :disabled="wizardMode === 'standard'"
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
                  :disabled="wizardMode === 'standard'"
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
        <h3>Personeel</h3>

        <div v-if="staffingMode === 'lesson_only'" class="admin-inline-notice">
          Deze stap wordt overgeslagen. Het lesrooster wordt zonder personeel gegenereerd.
          In <strong>Beheren → Handmatige correcties</strong> kun je daarna per sessie docenten toevoegen.
        </div>

        <template v-else>
          <section class="admin-roster-wizard-section">
            <h4>Klimaat Experience</h4>
            <div class="admin-roster-mode-choice">
              <label>
                <input v-model="preferGeoFortKe" type="radio" :value="true">
                <div>
                  <strong>GeoFort-docent bij voorkeur</strong>
                  <span>Standaard. De optimizer probeert KE te bemannen, maar School blijft fallback.</span>
                </div>
              </label>
              <label>
                <input v-model="preferGeoFortKe" type="radio" :value="false">
                <div>
                  <strong>Schoolbegeleiding is prima</strong>
                  <span>KE telt niet mee als gewenste GeoFort-bemensing.</span>
                </div>
              </label>
            </div>
          </section>

          <section class="admin-roster-wizard-section">
            <h4>Voedsel Innovatie — kok</h4>
            <label class="admin-field">
              <span class="admin-field__label">Kok voor deze dag</span>
              <select v-model="cookStaffId" class="admin-control">
                <option :value="null">Geen kok beschikbaar — waarschuwing</option>
                <option v-for="member in cookCatalog" :key="member.id" :value="member.id">
                  {{ member.name }}{{ !member.canGuide ? " — alleen kok" : "" }}
                </option>
              </select>
            </label>
            <p class="admin-field__help">
              Nelleke de With is standaard kok. Een VI-begeleider kan ook kok zijn, maar kan tijdens VI dan niet tegelijk begeleiden.
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
              <small>Met GeoFort op KE waar gewenst</small>
            </article>
            <article :class="{ 'is-warning': staffingAdvice.selectedPeople < staffingAdvice.recommendedPeople }">
              <span>Personen geselecteerd</span>
              <strong>{{ staffingAdvice.selectedPeople }}</strong>
              <small>Richtwaarde incl. kok: {{ staffingAdvice.recommendedPeople }}</small>
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
                <span v-if="item.module.key === 'Klimaat-Experience'">
                  {{ preferGeoFortKe ? "GeoFort bij voorkeur, anders School" : "Schoolbegeleiding toegestaan" }}
                </span>
                <span v-else-if="item.module.schoolSupervisionAllowed">Schoolbegeleiding toegestaan</span>
                <span v-else>GeoFort-begeleiding vereist</span>
              </div>
              <div>
                <span>Geselecteerd geschikt: {{ item.eligibleSelected.length }}</span>
                <small>{{ item.eligibleSelected.map((member) => member.name).join(", ") || "niemand" }}</small>
              </div>
            </article>
          </div>

          <div class="admin-roster-staff-picker__actions">
            <AdminButton variant="secondary" @click="selectAllStaff">Alle begeleiders selecteren</AdminButton>
            <AdminButton variant="ghost" @click="clearStaff">Selectie wissen</AdminButton>
          </div>

          <div class="admin-roster-staff-picker">
            <label
              v-for="member in guideCatalog"
              :key="member.id"
              class="admin-roster-staff-picker__member"
              :class="{ 'is-selected': selectedStaffIds.includes(member.id) }"
            >
              <input
                type="checkbox"
                :checked="selectedStaffIds.includes(member.id)"
                @change="toggleStaff(member.id)"
              >
              <div>
                <strong>{{ member.name }}</strong>
                <span>{{ member.employmentType === "volunteer" ? "Vrijwilliger" : "Betaald" }}</span>
                <span>
                  <template v-for="(preference, index) in member.preferences" :key="preference.moduleKey">
                    {{ preference.rank }}. {{ preference.moduleLabel }}<template v-if="index < member.preferences.length - 1"> · </template>
                  </template>
                </span>
              </div>
            </label>
          </div>

          <div v-if="selectedStaff.length" class="admin-inline-notice">
            Werkuren worden voor betaalde krachten en vrijwilligers gelijkwaardig verdeeld;
            dienstverband heeft geen voorrang in de optimizer.
          </div>
        </template>
      </section>

      <section v-else>
        <p class="admin-eyebrow">Stap 5</p>
        <h3>Voorstel genereren</h3>
        <p>
          Maak eerst een voorstel. Harde conflicten worden geblokkeerd; zachte voorkeuren
          zoals tussenuren worden geoptimaliseerd maar blijven controleerbaar.
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

          <p>{{ proposal.staffing.message }}</p>

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

          <AdminButton
            :loading="applying"
            :disabled="proposal.existingSessionCount > 0 && !replaceExisting"
            @click="apply"
          >
            Voorstel toepassen
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

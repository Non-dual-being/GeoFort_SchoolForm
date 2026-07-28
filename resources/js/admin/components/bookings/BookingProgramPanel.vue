<script setup lang="ts">
import {
  computed,
  inject,
  nextTick,
  ref,
  watch,
} from "vue";
import {
  CalendarDays,
  GraduationCap,
  Tag,
} from "lucide-vue-next";

import AdminButton from "../form/AdminButton.vue";
import AdminContextCard from "../form/AdminContextCard.vue";
import AdminInlineNotice from "../form/AdminInlineNotice.vue";
import AdminWizardActionBar from "../wizard/AdminWizardActionBar.vue";
import AdminWizardStepper, {
  type AdminWizardStep,
} from "../wizard/AdminWizardStepper.vue";

import BookingRuleOverrideDialog from "./BookingRuleOverrideDialog.vue";
import BookingProgramChoiceStep from "./program/BookingProgramChoiceStep.vue";
import BookingProgramStudentStep from "./program/BookingProgramStudentStep.vue";
import BookingProgramEducationStep from "./program/BookingProgramEducationStep.vue";
import BookingProgramModuleStep from "./program/BookingProgramModuleStep.vue";
import BookingProgramReviewStep from "./program/BookingProgramReviewStep.vue";

import { adminBootstrapKey } from "../../types/admin";

import {
  BookingProgramConfigurationApiError,
  updateDashboardBookingProgramConfiguration,
} from "../../services/dashboardBookingProgramConfigurationApi";

import type { BookingValidationIssue } from "../../types/bookingStatus";
import type { DashboardEducationSelection } from "../../types/bookingDetail";

import type {
  EducationSelectionValue,
  ProgramConfigurationExpected,
  ProgramConfigurationProposed,
} from "../../types/bookingProgramConfiguration";

import {
  getAvailableEducationModuleOptions,
  supportsChoiceModules,
} from "../../../config/booking/educationModuleHelpers";

import type {
  AnyLevelKey,
  BookingProgramConfigData,
  ProgramKey,
  SchoolSectorKey,
} from "../../../types/booking/BookingProgramConfigTypes";

import {
  validateProgramConfigurationStep,
  type ProgramConfigurationFrontendIssue,
  type ProgramConfigurationStepId,
  type ProgramConfigurationValidationContext,
} from "../../validation/programConfigurationStepValidator";

type Option = {
  key: string;
  label: string;
  description: string[];
  allowedSchoolTypes: string[];
  allowedWeekdays: number[];
};

type Configuration = {
  schoolLevels: Record<
    string,
    {
      label: string;
      groups: Record<string, string>;
    }
  >;

  selectionRules: {
    minLevels: number;
    maxLevels: number;
    minGroupsPerLevel: number;
    maxGroupsPerLevel: number;
  };

  studentLimitsByProgram: Record<
    string,
    {
      minimum: number;
      maximum: number;
    }
  >;

  modules: BookingProgramConfigData["modules"];
  moduleLabels: BookingProgramConfigData["moduleLabels"];
  moduleFilters: BookingProgramConfigData["moduleFilters"];
};

const props = defineProps<{
  bookingId: number;
  program: string;
  programLabel: string;
  schoolSector: string;
  schoolSectorLabel: string;
  visitDate: string;
  status: string;
  studentCount: number | null;
  choiceModule: string | null;
  selections: DashboardEducationSelection[];
  configuration: Configuration;
  options: Option[];
  refreshing: boolean;
}>();

const emit = defineEmits<{
  completed: [
    message: string,
    refresh: boolean,
    conflict: boolean,
  ];
}>();

const bootstrap = inject(adminBootstrapKey);

if (!bootstrap) {
  throw new Error("Admin bootstrapdata ontbreekt.");
}

const token = bootstrap.bookingProgramConfigurationCsrfToken;

const editing = ref(false);
const submitting = ref(false);
const currentStepId = ref<ProgramConfigurationStepId>("program");
const error = ref<string | null>(null);
const conflicted = ref(false);
const dialog = ref(false);

const expected = ref<ProgramConfigurationExpected>(snapshot());
const proposed = ref<ProgramConfigurationProposed>(proposal());

const emptyConfirmations=():Record<ProgramConfigurationStepId,boolean>=>({program:false,students:false,education:false,module:false,review:false});
const emptyConfirmationErrors=():Record<ProgramConfigurationStepId,string|null>=>({program:null,students:null,education:null,module:null,review:null});
const emptyAttempted=():Record<ProgramConfigurationStepId,boolean>=>({program:false,students:false,education:false,module:false,review:false});
const checked = ref(emptyConfirmations());
const confirmationErrors = ref(emptyConfirmationErrors());

const weekdayAccepted = ref(false);
const issues = ref<BookingValidationIssue[]>([]);
const reasons = ref<Record<string, string>>({});

const attemptedSteps = ref(emptyAttempted());

const frontendIssues = ref<ProgramConfigurationFrontendIssue[]>([]);

const staleModuleMessage = ref<string | null>(null);
const moduleChangeNotice = ref<string | null>(null);
const errorSummary = ref<HTMLElement | null>(null);

const weekday = computed(() => {
  const [year, month, day] = props.visitDate
    .split("-")
    .map(Number);

  return (
    new Date(
      Date.UTC(
        year ?? 1970,
        (month ?? 1) - 1,
        day ?? 1,
      ),
    ).getUTCDay() || 7
  );
});

const selectedOption = computed(() => {
  return props.options.find(
    (option) => option.key === proposed.value.program,
  );
});

const weekdayMismatch = computed(() => {
  return (
    Boolean(selectedOption.value) &&
    !selectedOption.value!.allowedWeekdays.includes(
      weekday.value,
    )
  );
});

const sectorMismatch = computed(() => {
  return (
    Boolean(selectedOption.value) &&
    !selectedOption.value!.allowedSchoolTypes.includes(
      props.schoolSector,
    )
  );
});

const moduleOptions = computed(() => {
  return getAvailableEducationModuleOptions({
    config: props.configuration,
    sector: props.schoolSector as SchoolSectorKey,
    program: proposed.value.program as ProgramKey,
    groupType: "keuze",
    selectedLevels:
      proposed.value.educationSelection
        .selectedLevels as AnyLevelKey[],
    selectedGroupsByLevel:
      proposed.value.educationSelection
        .selectedGroupsByLevel,
  });
});

const choiceModulesSupported = computed(() => supportsChoiceModules({
  config: props.configuration,
  sector: props.schoolSector as SchoolSectorKey,
  program: proposed.value.program as ProgramKey,
}));

const allSteps:Record<ProgramConfigurationStepId,{id:ProgramConfigurationStepId;label:string}>={
  program:{id:"program",label:"Programma"},
  students:{id:"students",label:"Leerlingen"},
  education:{id:"education",label:"Onderwijs"},
  module:{id:"module",label:"Keuzemodule"},
  review:{id:"review",label:"Controle"},
};

const visibleSteps=computed(()=>[
  allSteps.program,
  allSteps.students,
  allSteps.education,
  ...(choiceModulesSupported.value?[allSteps.module]:[]),
  allSteps.review,
]);
const currentStepIndex=computed(()=>Math.max(0,visibleSteps.value.findIndex(item=>item.id===currentStepId.value)));
const currentStepNumber=computed(()=>currentStepIndex.value+1);
const totalSteps=computed(()=>visibleSteps.value.length);

const moduleLabel = computed(() => {
  return (
    moduleOptions.value.find(
      (item) =>
        item.key === proposed.value.choiceModule,
    )?.label ?? "Niet gekozen"
  );
});

const studentLimits = computed(() => {
  return (
    props.configuration.studentLimitsByProgram[
      proposed.value.program
    ] ?? null
  );
});

const validationContext =
  computed<ProgramConfigurationValidationContext>(
    () => ({
      schoolSector: props.schoolSector,
      weekday: weekday.value,
      options: props.options,
      selectionRules:
        props.configuration.selectionRules,
      schoolLevels:
        props.configuration.schoolLevels,
      studentLimits: studentLimits.value,
      availableModules: moduleOptions.value,
      supportsChoiceModules: choiceModulesSupported.value,
      confirmations: checked.value,
      weekdayAccepted: weekdayAccepted.value,
    }),
  );

const stepIssues = (
  value: ProgramConfigurationStepId,
): ProgramConfigurationFrontendIssue[] => {
  return validateProgramConfigurationStep(
    value,
    proposed.value,
    validationContext.value,
  );
};

const canSubmit = computed(() => {
  return (
    stepIssues("review").length === 0 &&
    !conflicted.value
  );
});

const safeIssueText = (
  issue: BookingValidationIssue,
): string => {
  return (
    issue.description.trim() ||
    issue.title.trim() ||
    "Controleer dit veld."
  );
};

const fieldIssue = computed(() => {
  return Object.fromEntries(
    issues.value.map((issue) => [
      issue.field,
      safeIssueText(issue),
    ]),
  );
});

const visibleFrontendIssues = computed(() => {
  return frontendIssues.value.filter(
    (issue) =>
      attemptedSteps.value[issue.stepId],
  );
});

const frontendIssueForStep = (
  value: ProgramConfigurationStepId,
): ProgramConfigurationFrontendIssue | undefined => {
  return visibleFrontendIssues.value.find(
    (issue) =>
      issue.stepId === value &&
      issue.code !== "STEP_CONFIRMATION_REQUIRED",
  );
};

const educationIssue = computed(() => {
  return issues.value.find(
    (issue) => issueStepId(issue.field) === "education",
  );
});

const moduleIssue = computed(() => {
  return issues.value.find(
    (issue) => issueStepId(issue.field) === "module",
  );
});

const programIssue = computed(() => {
  return issues.value.find(
    (issue) => issueStepId(issue.field) === "program",
  );
});

function issueStepId(field: string): ProgramConfigurationStepId {
  if (
    field === "program" ||
    field === "programma"
  ) {
    return "program";
  }

  if (
    field === "studentCount" ||
    field === "aantal_leerlingen" ||
    field === "aantalLeerlingen"
  ) {
    return "students";
  }

  if (
    field.startsWith("educationSelection") ||
    field.includes("selectedLevels") ||
    field.includes("selectedGroupsByLevel")
  ) {
    return "education";
  }

  if (
    field === "choiceModule" ||
    field === "keuzemodule"
  ) {
    return "module";
  }

  return "review";
}

const wizardSteps = computed<AdminWizardStep[]>(
  () =>
    visibleSteps.value.map(({id,label}, index) => {
      const invalid =
        visibleFrontendIssues.value.some(
          (issue) => issue.stepId === id,
        ) ||
        issues.value.some(
          (issue) =>
            issueStepId(issue.field) === id,
        );

      const completed =
        checked.value[id] &&
        stepIssues(id).length === 0 &&
        !invalid;

      return {
        label,
        error: invalid,
        state:
          currentStepId.value === id
            ? "current"
            : completed
              ? "completed"
              : index < currentStepIndex.value
                ? "available"
                : "upcoming",
      };
    }),
);

function selectionFromProps(): EducationSelectionValue {
  const selectedLevels = props.selections.map(
    (item) => item.levelKey,
  );

  return {
    sector: props.schoolSector,
    selectedLevels,
    selectedGroupsByLevel: Object.fromEntries(
      props.selections.map((item) => [
        item.levelKey,
        item.groups.map(
          (group) => group.groupKey,
        ),
      ]),
    ),
  };
}

function snapshot(): ProgramConfigurationExpected {
  return {
    status: props.status,
    visitDate: props.visitDate,
    program: props.program,
    studentCount: props.studentCount ?? 0,
    educationSelection: selectionFromProps(),
    choiceModule: props.choiceModule,
  };
}

function proposal(): ProgramConfigurationProposed {
  const value = snapshot();
  const choiceModuleSupported=supportsChoiceModules({
    config:props.configuration,
    sector:props.schoolSector as SchoolSectorKey,
    program:value.program as ProgramKey,
  });

  return {
    program: value.program,
    studentCount: value.studentCount,
    educationSelection:
      value.educationSelection,
    choiceModule: choiceModuleSupported ? value.choiceModule : null,
  };
}

function formatDate(value: string): string {
  const [year, month, day] = value
    .split("-")
    .map(Number);

  const text = new Intl.DateTimeFormat(
    "nl-NL",
    {
      weekday: "long",
      day: "numeric",
      month: "long",
      year: "numeric",
      timeZone: "UTC",
    },
  ).format(
    new Date(
      Date.UTC(
        year ?? 1970,
        (month ?? 1) - 1,
        day ?? 1,
      ),
    ),
  );

  return (
    text.charAt(0).toUpperCase() +
    text.slice(1)
  );
}

function open(): void {
  expected.value = snapshot();
  proposed.value = proposal();

  checked.value = emptyConfirmations();
  confirmationErrors.value = emptyConfirmationErrors();
  attemptedSteps.value = emptyAttempted();

  frontendIssues.value = [];
  currentStepId.value = "program";
  weekdayAccepted.value = false;
  issues.value = [];
  reasons.value = {};
  error.value = null;
  staleModuleMessage.value = null;
  moduleChangeNotice.value =
    expected.value.choiceModule!==null&&!supportsChoiceModules({
      config:props.configuration,
      sector:props.schoolSector as SchoolSectorKey,
      program:proposed.value.program as ProgramKey,
    })
      ?"Het Ochtendprogramma gebruikt geen keuzemodule. De huidige keuzemodule wordt bij het opslaan verwijderd."
      :null;
  conflicted.value = false;
  editing.value = true;
}

function cancel(): void {
  if (!submitting.value) {
    editing.value = false;
    dialog.value = false;
  }
}

function updateConfirmation(
  stepId: ProgramConfigurationStepId,
  value: boolean,
): void {
  checked.value[stepId] = value;

  if (value) {
    confirmationErrors.value[stepId] = null;

    frontendIssues.value =
      frontendIssues.value.filter(
        (issue) =>
          !(
            issue.stepId === stepId &&
            issue.code ===
              "STEP_CONFIRMATION_REQUIRED"
          ),
      );
  }
}

function invalidateStep(stepId: ProgramConfigurationStepId): void {
  checked.value[stepId] = false;
  confirmationErrors.value[stepId] = null;
  attemptedSteps.value[stepId] = false;

  frontendIssues.value =
    frontendIssues.value.filter(
      (issue) => issue.stepId !== stepId,
    );

  issues.value = issues.value.filter(
    (issue) =>
      issueStepId(issue.field) !== stepId,
  );
}

function invalidateFrom(stepId: ProgramConfigurationStepId): void {
  const start=Object.keys(allSteps).indexOf(stepId);
  for (const id of Object.keys(allSteps).slice(start) as ProgramConfigurationStepId[]) {
    invalidateStep(id);
  }
}

function chooseProgram(value: string): void {
  const previouslySupported=choiceModulesSupported.value;
  const previousModule=proposed.value.choiceModule;
  proposed.value.program = value;
  invalidateFrom("program");
  weekdayAccepted.value = false;
  const nowSupported=supportsChoiceModules({
    config:props.configuration,
    sector:props.schoolSector as SchoolSectorKey,
    program:value as ProgramKey,
  });
  if(!nowSupported){
    proposed.value.choiceModule=null;
    staleModuleMessage.value=null;
    issues.value=issues.value.filter(issue=>issueStepId(issue.field)!=="module");
    frontendIssues.value=frontendIssues.value.filter(issue=>issue.stepId!=="module");
    if(previousModule!==null){
      moduleChangeNotice.value="Het Ochtendprogramma gebruikt geen keuzemodule. De huidige keuzemodule wordt bij het opslaan verwijderd.";
    }
  }else if(!previouslySupported){
    proposed.value.choiceModule=null;
    moduleChangeNotice.value=!previouslySupported
      ?"Kies een keuzemodule die past bij het programma en de onderwijsselectie."
      :null;
  }else{
    moduleChangeNotice.value=null;
    validateCurrentModule();
  }
  if(!visibleSteps.value.some(item=>item.id===currentStepId.value))currentStepId.value="review";
}

function updateStudents(
  value: number | null,
): void {
  proposed.value.studentCount = value;
  invalidateStep("students");
  invalidateStep("review");
}

function updateEducation(
  value: EducationSelectionValue,
): void {
  proposed.value.educationSelection = value;
  invalidateFrom("education");
  validateCurrentModule();
}

function updateModule(
  value: string | null,
): void {
  proposed.value.choiceModule = value;
  staleModuleMessage.value = null;
  invalidateStep("module");
  invalidateStep("review");
}

function validateCurrentModule(): void {
  if (
    proposed.value.choiceModule &&
    !moduleOptions.value.some(
      (item) =>
        item.key === proposed.value.choiceModule,
    )
  ) {
    proposed.value.choiceModule = null;

    staleModuleMessage.value =
      "De eerder gekozen keuzemodule past niet meer bij de nieuwe onderwijsselectie. Kies een nieuwe keuzemodule.";

    invalidateStep("module");
    invalidateStep("review");
  }
}

async function showLocalErrors(
  targetStep: ProgramConfigurationStepId,
  found: ProgramConfigurationFrontendIssue[],
): Promise<void> {
  attemptedSteps.value[targetStep] = true;

  frontendIssues.value = [
    ...frontendIssues.value.filter(
      (issue) => issue.stepId !== targetStep,
    ),
    ...found,
  ];

  const confirmation = found.find(
    (issue) =>
      issue.code ===
      "STEP_CONFIRMATION_REQUIRED",
  );

  confirmationErrors.value[targetStep] =
    confirmation?.message ?? null;

  error.value =
    "Controleer de gemarkeerde velden voordat u verdergaat.";

  await nextTick();
  errorSummary.value?.focus();
}

function validateStep(
  targetStep: ProgramConfigurationStepId,
): boolean {
  const found = stepIssues(targetStep);

  if (found.length) {
    void showLocalErrors(targetStep, found);
    return false;
  }

  attemptedSteps.value[targetStep] = true;

  frontendIssues.value =
    frontendIssues.value.filter(
      (issue) => issue.stepId !== targetStep,
    );

  confirmationErrors.value[targetStep] =
    null;

  error.value = null;

  return true;
}

function nextStep(): void {
  if (!validateStep(currentStepId.value)) {
    return;
  }

  const next=visibleSteps.value[currentStepIndex.value+1];
  if(next)currentStepId.value=next.id;
}

function previousStep(): void {
  const previous=visibleSteps.value[currentStepIndex.value-1];
  if(previous)currentStepId.value=previous.id;
}

function selectStep(value: number): void {
  const index=value-1;
  if (index < currentStepIndex.value) {
    currentStepId.value = visibleSteps.value[index]?.id??"program";
  }
}

function submitWizard(): void {
  const found = stepIssues("review");

  if (found.length) {
    for(const visible of visibleSteps.value)attemptedSteps.value[visible.id]=true;

    frontendIssues.value = found;

    const first = visibleSteps.value.find(item=>found.some(issue=>issue.stepId===item.id))?.id??"review";
    currentStepId.value = first;

    void showLocalErrors(
      first,
      found.filter(
        (issue) => issue.stepId === first,
      ),
    );

    return;
  }

  if (!validateStep("review")) {
    return;
  }

  void save(false);
}

function reconsider(): void {
  expected.value = snapshot();

  checked.value = emptyConfirmations();
  confirmationErrors.value = emptyConfirmationErrors();

  conflicted.value = false;

  error.value =
    "De servergegevens zijn bijgewerkt. Controleer iedere stap opnieuw; uw voorgestelde waarden zijn behouden.";

  currentStepId.value = "program";
}

async function save(
  withOverrides = false,
): Promise<void> {
  if (
    submitting.value ||
    props.refreshing ||
    !canSubmit.value
  ) return;

  submitting.value = true;
  error.value = null;

  try {
    const overrides = withOverrides
      ? issues.value.map((issue) => ({
          ruleCode: issue.code,
          reason: (
            reasons.value[issue.code] ?? ""
          ).trim(),
        }))
      : [];

    const result =
      await updateDashboardBookingProgramConfiguration(
        {
          bookingId: props.bookingId,
          expected:expected.value,
          proposed:proposed.value,
          overrides,
        },
        token,
      );

    editing.value = false;
    dialog.value = false;

    emit(
      "completed",
      result.code ===
        "NO_PROGRAM_CONFIGURATION_CHANGE"
        ? "De programmaconfiguratie is niet gewijzigd."
        : "De programmaconfiguratie is bijgewerkt.",
      result.code === "SUCCESS",
      false,
    );
  } catch (caught) {
    if (
      caught instanceof
        BookingProgramConfigurationApiError &&
      caught.result
    ) {
      const result = caught.result;

      if (
        result.code === "OVERRIDE_REQUIRED" ||
        result.code ===
          "INVALID_OVERRIDE_REQUEST"
      ) {
        issues.value =
          result.validationIssues.filter(
            (issue) => issue.overridable,
          );

        reasons.value = Object.fromEntries(
          issues.value.map((issue) => [
            issue.code,
            reasons.value[issue.code] ?? "",
          ]),
        );

        dialog.value = true;
        return;
      }

      if (
        result.code ===
        "PROGRAM_CONFIGURATION_CONFLICT"
      ) {
        conflicted.value = true;

        error.value =
          "De aanvraag is inmiddels gewijzigd. De actuele servergegevens worden geladen; beoordeel daarna uw bewaarde voorstel opnieuw.";

        emit(
          "completed",
          error.value,
          true,
          true,
        );

        return;
      }

      issues.value = result.validationIssues;

      error.value =
        "De programmaconfiguratie kon niet worden opgeslagen. Uw keuzes zijn behouden.";

      currentStepId.value = issues.value.length
        ? visibleSteps.value.find(item=>issues.value.some(issue=>issueStepId(issue.field)===item.id))?.id??"review"
        : "review";

      await nextTick();
      errorSummary.value?.focus();
    } else {
      error.value =
        "De programmaconfiguratie kon niet worden opgeslagen. Uw keuzes zijn behouden.";
    }
  } finally {
    submitting.value = false;
  }
}

watch(
  moduleOptions,
  validateCurrentModule,
);
</script>

<template>
  <section class="admin-card admin-program-panel">
    <div class="admin-program-panel__heading">
      <div>
        <p class="admin-program-step__eyebrow">
          Programmaconfiguratie
        </p>

        <h2>Programma wijzigen</h2>
      </div>

      <Tag
        :size="25"
        aria-hidden="true"
      />
    </div>

    <template v-if="!editing">
      <p class="admin-program-panel__value">
        {{ programLabel }}
      </p>

      <AdminButton
        class="admin-program-panel__edit-button"
        :disabled="refreshing"
        @click="open"
      >
        Configuratie wijzigen
      </AdminButton>
    </template>

    <form
      v-else
      class="admin-program-wizard"
      @submit.prevent="submitWizard"
    >
      <div class="admin-program-context">
        <AdminContextCard
          label="Bezoekdatum"
          :value="formatDate(visitDate)"
          subtitle="Blijft ongewijzigd"
          :icon="CalendarDays"
        />

        <AdminContextCard
          label="Schoolsector"
          :value="schoolSectorLabel"
          subtitle="Blijft ongewijzigd"
          :icon="GraduationCap"
        />

        <AdminContextCard
          label="Status"
          :value="status"
          subtitle="Blijft ongewijzigd"
          :icon="Tag"
        />
      </div>

      <AdminWizardStepper
        :steps="wizardSteps"
        @select="selectStep"
      />

      <div
        v-if="error"
        ref="errorSummary"
        class="admin-program-wizard__summary"
        role="alert"
        aria-live="assertive"
        tabindex="-1"
      >
        <AdminInlineNotice
          variant="error"
          title="Controleer de gemarkeerde velden voordat u verdergaat."
        >
          {{ error }}
        </AdminInlineNotice>
      </div>

      <div class="admin-program-wizard__content">
        <BookingProgramChoiceStep
          v-if="currentStepId === 'program'"
          :step-number="currentStepNumber"
          :total-steps="totalSteps"
          :model-value="proposed.program"
          :options="options"
          :confirmed="checked.program"
          :confirmation-error="confirmationErrors.program"
          :module-change-notice="moduleChangeNotice"
          :field-error="
            frontendIssueForStep('program')?.message ??
            (programIssue
              ? safeIssueText(programIssue)
              : null)
          "
          :sector-mismatch="sectorMismatch"
          :weekday-mismatch="weekdayMismatch"
          :weekday-accepted="weekdayAccepted"
          @update:model-value="chooseProgram"
          @update:confirmed="
            (value) => updateConfirmation('program', value)
          "
          @update:weekday-accepted="
            (value) => {
              weekdayAccepted = value;
              invalidateStep('program');
            }
          "
        />

        <BookingProgramStudentStep
          v-else-if="
            currentStepId === 'students' &&
            studentLimits
          "
          :step-number="currentStepNumber"
          :total-steps="totalSteps"
          :model-value="proposed.studentCount"
          :minimum="studentLimits.minimum"
          :maximum="studentLimits.maximum"
          :confirmed="checked.students"
          :confirmation-error="confirmationErrors.students"
          :field-error="
            frontendIssueForStep('students')?.message ??
            fieldIssue.aantalLeerlingen ??
            fieldIssue.studentCount ??
            fieldIssue.aantal_leerlingen
          "
          @update:model-value="updateStudents"
          @update:confirmed="
            (value) => updateConfirmation('students', value)
          "
        />

        <AdminInlineNotice
          v-else-if="currentStepId === 'students'"
          variant="error"
          title="Leerlinglimieten ontbreken"
        >
          Voor dit programma zijn geen centrale
          leerlinglimieten beschikbaar.
        </AdminInlineNotice>

        <BookingProgramEducationStep
          v-else-if="currentStepId === 'education'"
          :step-number="currentStepNumber"
          :total-steps="totalSteps"
          :model-value="
            proposed.educationSelection
          "
          :levels="configuration.schoolLevels"
          :rules="configuration.selectionRules"
          :attempted="
            attemptedSteps.education
          "
          :issues="
            visibleFrontendIssues.filter(
              (issue) => issue.stepId === 'education',
            )
          "
          :confirmed="checked.education"
          :confirmation-error="confirmationErrors.education"
          :field-error="
            educationIssue
              ? safeIssueText(educationIssue)
              : null
          "
          @update:model-value="updateEducation"
          @update:confirmed="
            (value) => updateConfirmation('education', value)
          "
        />

        <BookingProgramModuleStep
          v-else-if="currentStepId === 'module'"
          :step-number="currentStepNumber"
          :total-steps="totalSteps"
          :model-value="proposed.choiceModule"
          :options="moduleOptions"
          :confirmed="checked.module"
          :confirmation-error="confirmationErrors.module"
          :field-error="
            frontendIssueForStep('module')?.message ??
            (moduleIssue
              ? safeIssueText(moduleIssue)
              : null)
          "
          :stale-message="staleModuleMessage"
          @update:model-value="updateModule"
          @update:confirmed="
            (value) => updateConfirmation('module', value)
          "
          @edit-program="currentStepId = 'program'"
          @edit-education="currentStepId = 'education'"
        />

        <BookingProgramReviewStep
          v-else
          :step-number="currentStepNumber"
          :total-steps="totalSteps"
          :supports-choice-modules="choiceModulesSupported"
          :module-change-notice="moduleChangeNotice"
          :expected="expected"
          :proposed="proposed"
          :program-label="
            selectedOption?.label ??
            proposed.program
          "
          :module-label="moduleLabel"
          :visit-date-label="
            formatDate(visitDate)
          "
          :school-sector-label="
            schoolSectorLabel
          "
          :levels="
            configuration.schoolLevels
          "
          :confirmed="checked.review"
          :confirmation-error="confirmationErrors.review"
          @update:confirmed="
            (value) => updateConfirmation('review', value)
          "
          @edit="(value) => (currentStepId = value)"
        />
      </div>

      <AdminButton
        v-if="conflicted"
        type="button"
        variant="secondary"
        @click="reconsider"
      >
        Actuele gegevens opnieuw beoordelen
      </AdminButton>

      <AdminWizardActionBar
        :step="currentStepNumber"
        :total-steps="totalSteps"
        :next-disabled="
          submitting ||
          refreshing ||
          conflicted
        "
        :submitting="submitting"
        @back="previousStep"
        @cancel="cancel"
        @next="nextStep"
        @submit="submitWizard"
      />
    </form>

    <BookingRuleOverrideDialog
      :open="dialog"
      :issues="issues"
      :submitting="submitting"
      :reasons="reasons"
      @close="dialog = false"
      @confirm="save(true)"
      @update:reason="
        (code, value) =>
          (reasons[code] = value)
      "
    />
  </section>
</template>

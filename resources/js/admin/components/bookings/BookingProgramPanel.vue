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

import { getAvailableEducationModuleOptions } from "../../../config/booking/educationModuleHelpers";

import type {
  AnyLevelKey,
  BookingProgramConfigData,
  ProgramKey,
  SchoolSectorKey,
} from "../../../types/booking/BookingProgramConfigTypes";

import {
  validateProgramConfigurationStep,
  type ProgramConfigurationFrontendIssue,
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
const step = ref(1);
const error = ref<string | null>(null);
const conflicted = ref(false);
const dialog = ref(false);

const expected = ref<ProgramConfigurationExpected>(snapshot());
const proposed = ref<ProgramConfigurationProposed>(proposal());

const checked = ref([
  false,
  false,
  false,
  false,
  false,
]);

const confirmationErrors = ref<(string | null)[]>([
  null,
  null,
  null,
  null,
  null,
]);

const weekdayAccepted = ref(false);
const issues = ref<BookingValidationIssue[]>([]);
const reasons = ref<Record<string, string>>({});

const attemptedSteps = ref([
  false,
  false,
  false,
  false,
  false,
]);

const frontendIssues = ref<ProgramConfigurationFrontendIssue[]>([]);

const staleModuleMessage = ref<string | null>(null);
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
      confirmations: checked.value,
      weekdayAccepted: weekdayAccepted.value,
    }),
  );

const stepIssues = (
  value: number,
): ProgramConfigurationFrontendIssue[] => {
  return validateProgramConfigurationStep(
    value,
    proposed.value,
    validationContext.value,
  );
};

const canSubmit = computed(() => {
  return (
    stepIssues(5).length === 0 &&
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
      attemptedSteps.value[issue.step - 1],
  );
});

const frontendIssueForStep = (
  value: number,
): ProgramConfigurationFrontendIssue | undefined => {
  return visibleFrontendIssues.value.find(
    (issue) =>
      issue.step === value &&
      issue.code !== "STEP_CONFIRMATION_REQUIRED",
  );
};

const educationIssue = computed(() => {
  return issues.value.find(
    (issue) => issueStep(issue.field) === 3,
  );
});

const moduleIssue = computed(() => {
  return issues.value.find(
    (issue) => issueStep(issue.field) === 4,
  );
});

const programIssue = computed(() => {
  return issues.value.find(
    (issue) => issueStep(issue.field) === 1,
  );
});

function issueStep(field: string): number {
  if (
    field === "program" ||
    field === "programma"
  ) {
    return 1;
  }

  if (
    field === "studentCount" ||
    field === "aantal_leerlingen" ||
    field === "aantalLeerlingen"
  ) {
    return 2;
  }

  if (
    field.startsWith("educationSelection") ||
    field.includes("selectedLevels") ||
    field.includes("selectedGroupsByLevel")
  ) {
    return 3;
  }

  if (
    field === "choiceModule" ||
    field === "keuzemodule"
  ) {
    return 4;
  }

  return 5;
}

const wizardSteps = computed<AdminWizardStep[]>(
  () =>
    [
      "Programma",
      "Leerlingen",
      "Onderwijs",
      "Keuzemodule",
      "Controle",
    ].map((label, index) => {
      const stepNumber = index + 1;

      const invalid =
        visibleFrontendIssues.value.some(
          (issue) => issue.step === stepNumber,
        ) ||
        issues.value.some(
          (issue) =>
            issueStep(issue.field) === stepNumber,
        );

      const completed =
        checked.value[index] &&
        stepIssues(stepNumber).length === 0 &&
        !invalid;

      return {
        label,
        error: invalid,
        state:
          step.value === stepNumber
            ? "current"
            : completed
              ? "completed"
              : stepNumber < step.value
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

  return {
    program: value.program,
    studentCount: value.studentCount,
    educationSelection:
      value.educationSelection,
    choiceModule: value.choiceModule,
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

  checked.value = [
    false,
    false,
    false,
    false,
    false,
  ];

  confirmationErrors.value = [
    null,
    null,
    null,
    null,
    null,
  ];

  attemptedSteps.value = [
    false,
    false,
    false,
    false,
    false,
  ];

  frontendIssues.value = [];
  step.value = 1;
  weekdayAccepted.value = false;
  issues.value = [];
  reasons.value = {};
  error.value = null;
  staleModuleMessage.value = null;
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
  index: number,
  value: boolean,
): void {
  checked.value[index] = value;

  if (value) {
    confirmationErrors.value[index] = null;

    frontendIssues.value =
      frontendIssues.value.filter(
        (issue) =>
          !(
            issue.step === index + 1 &&
            issue.code ===
              "STEP_CONFIRMATION_REQUIRED"
          ),
      );
  }
}

function invalidateStep(index: number): void {
  checked.value[index] = false;
  confirmationErrors.value[index] = null;
  attemptedSteps.value[index] = false;

  frontendIssues.value =
    frontendIssues.value.filter(
      (issue) => issue.step !== index + 1,
    );

  issues.value = issues.value.filter(
    (issue) =>
      issueStep(issue.field) !== index + 1,
  );
}

function invalidateFrom(index: number): void {
  for (
    let current = index;
    current < checked.value.length;
    current += 1
  ) {
    invalidateStep(current);
  }
}

function chooseProgram(value: string): void {
  proposed.value.program = value;
  invalidateFrom(0);
  weekdayAccepted.value = false;
  validateCurrentModule();
}

function updateStudents(
  value: number | null,
): void {
  proposed.value.studentCount = value;
  invalidateStep(1);
  invalidateStep(4);
}

function updateEducation(
  value: EducationSelectionValue,
): void {
  proposed.value.educationSelection = value;
  invalidateFrom(2);
  validateCurrentModule();
}

function updateModule(
  value: string | null,
): void {
  proposed.value.choiceModule = value;
  staleModuleMessage.value = null;
  invalidateStep(3);
  invalidateStep(4);
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

    invalidateStep(3);
    invalidateStep(4);
  }
}

async function showLocalErrors(
  targetStep: number,
  found: ProgramConfigurationFrontendIssue[],
): Promise<void> {
  attemptedSteps.value[targetStep - 1] = true;

  frontendIssues.value = [
    ...frontendIssues.value.filter(
      (issue) => issue.step !== targetStep,
    ),
    ...found,
  ];

  const confirmation = found.find(
    (issue) =>
      issue.code ===
      "STEP_CONFIRMATION_REQUIRED",
  );

  confirmationErrors.value[targetStep - 1] =
    confirmation?.message ?? null;

  error.value =
    "Controleer de gemarkeerde velden voordat u verdergaat.";

  await nextTick();
  errorSummary.value?.focus();
}

function validateStep(
  targetStep: number,
): boolean {
  const found = stepIssues(targetStep);

  if (found.length) {
    void showLocalErrors(targetStep, found);
    return false;
  }

  attemptedSteps.value[targetStep - 1] = true;

  frontendIssues.value =
    frontendIssues.value.filter(
      (issue) => issue.step !== targetStep,
    );

  confirmationErrors.value[targetStep - 1] =
    null;

  error.value = null;

  return true;
}

function nextStep(): void {
  if (!validateStep(step.value)) {
    return;
  }

  if (step.value < 5) {
    step.value += 1;
  }
}

function previousStep(): void {
  if (step.value > 1) {
    step.value -= 1;
  }
}

function selectStep(value: number): void {
  if (value < step.value) {
    step.value = value;
  }
}

function submitWizard(): void {
  const found = stepIssues(5);

  if (found.length) {
    attemptedSteps.value = [
      true,
      true,
      true,
      true,
      true,
    ];

    frontendIssues.value = found;

    const first = Math.min(
      ...found.map((issue) => issue.step),
    );

    step.value = first;

    void showLocalErrors(
      first,
      found.filter(
        (issue) => issue.step === first,
      ),
    );

    return;
  }

  if (!validateStep(5)) {
    return;
  }

  void save(false);
}

function reconsider(): void {
  expected.value = snapshot();

  checked.value = [
    false,
    false,
    false,
    false,
    false,
  ];

  confirmationErrors.value = [
    null,
    null,
    null,
    null,
    null,
  ];

  conflicted.value = false;

  error.value =
    "De servergegevens zijn bijgewerkt. Controleer iedere stap opnieuw; uw voorgestelde waarden zijn behouden.";

  step.value = 1;
}

async function save(
  withOverrides = false,
): Promise<void> {
  if (
    submitting.value ||
    props.refreshing ||
    !canSubmit.value
  ) {
    return;
  }

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
          expected: expected.value,
          proposed: proposed.value,
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

      step.value = issues.value.length
        ? Math.min(
            ...issues.value.map((issue) =>
              issueStep(issue.field),
            ),
          )
        : 5;

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
          v-if="step === 1"
          :model-value="proposed.program"
          :options="options"
          :confirmed="checked[0] ?? false"
          :confirmation-error="confirmationErrors[0]"
          :field-error="
            frontendIssueForStep(1)?.message ??
            (programIssue
              ? safeIssueText(programIssue)
              : null)
          "
          :sector-mismatch="sectorMismatch"
          :weekday-mismatch="weekdayMismatch"
          :weekday-accepted="weekdayAccepted"
          @update:model-value="chooseProgram"
          @update:confirmed="
            (value) => updateConfirmation(0, value)
          "
          @update:weekday-accepted="
            (value) => {
              weekdayAccepted = value;
              invalidateStep(0);
            }
          "
        />

        <BookingProgramStudentStep
          v-else-if="
            step === 2 &&
            studentLimits
          "
          :model-value="proposed.studentCount"
          :minimum="studentLimits.minimum"
          :maximum="studentLimits.maximum"
          :confirmed="checked[1] ?? false"
          :confirmation-error="confirmationErrors[1]"
          :field-error="
            frontendIssueForStep(2)?.message ??
            fieldIssue.aantalLeerlingen ??
            fieldIssue.studentCount ??
            fieldIssue.aantal_leerlingen
          "
          @update:model-value="updateStudents"
          @update:confirmed="
            (value) => updateConfirmation(1, value)
          "
        />

        <AdminInlineNotice
          v-else-if="step === 2"
          variant="error"
          title="Leerlinglimieten ontbreken"
        >
          Voor dit programma zijn geen centrale
          leerlinglimieten beschikbaar.
        </AdminInlineNotice>

        <BookingProgramEducationStep
          v-else-if="step === 3"
          :model-value="
            proposed.educationSelection
          "
          :levels="configuration.schoolLevels"
          :rules="configuration.selectionRules"
          :attempted="
            attemptedSteps[2] ?? false
          "
          :issues="
            visibleFrontendIssues.filter(
              (issue) => issue.step === 3,
            )
          "
          :confirmed="checked[2] ?? false"
          :confirmation-error="confirmationErrors[2]"
          :field-error="
            educationIssue
              ? safeIssueText(educationIssue)
              : null
          "
          @update:model-value="updateEducation"
          @update:confirmed="
            (value) => updateConfirmation(2, value)
          "
        />

        <BookingProgramModuleStep
          v-else-if="step === 4"
          :model-value="proposed.choiceModule"
          :options="moduleOptions"
          :confirmed="checked[3] ?? false"
          :confirmation-error="confirmationErrors[3]"
          :field-error="
            frontendIssueForStep(4)?.message ??
            (moduleIssue
              ? safeIssueText(moduleIssue)
              : null)
          "
          :stale-message="staleModuleMessage"
          @update:model-value="updateModule"
          @update:confirmed="
            (value) => updateConfirmation(3, value)
          "
          @edit-program="step = 1"
          @edit-education="step = 3"
        />

        <BookingProgramReviewStep
          v-else
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
          :confirmed="checked[4] ?? false"
          :confirmation-error="confirmationErrors[4]"
          @update:confirmed="
            (value) => updateConfirmation(4, value)
          "
          @edit="(value) => (step = value)"
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
        :step="step"
        :total-steps="5"
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
<script setup lang="ts">
import { computed, ref, toRef } from "vue";
import { Check, Puzzle } from "lucide-vue-next";

import FieldFlash from "./FieldFlash.vue";
import { useFieldFlash } from "../../composables/useFieldFlash";

import type { ValidationShape } from "../../types/validation/FieldErrorTypes";

import type {
  EducationModuleKey,
  EducationModuleOption,
} from "../../types/booking/BookingProgramConfigTypes";

const props = withDefaults(
  defineProps<{
    id: string;
    label: string;
    modelValue: EducationModuleKey | "";
    standardModules: readonly EducationModuleOption[];
    choiceModules: readonly EducationModuleOption[];
    required?: boolean;
    disabled?: boolean;
    issue?: string | null;
    flashTrigger: number;
  }>(),
  {
    required: false,
    disabled: false,
    issue: null,
  },
);

const emit = defineEmits<{
  (e: "update:modelValue", value: EducationModuleKey | ""): void;
  (e: "change", value: EducationModuleKey | ""): void;
  (e: "blur"): void;
}>();

const behaviorRef = ref<"persistent">("persistent");
const triggerRef = toRef(props, "flashTrigger");

/**
 * Belangrijk:
 * issue mag al bestaan zodra de stap verschijnt,
 * maar we tonen hem pas als echte fout nadat de parent een flash-trigger geeft.
 *
 * Daardoor wordt de stap niet direct rood zodra de gebruiker hier aankomt.
 */
const shouldShowError = computed(() => {
  return props.flashTrigger > 0 && Boolean(props.issue);
});

const normalizedIssue = computed<ValidationShape>(() => {
  if (!shouldShowError.value || !props.issue) {
    return {};
  }

  return {
    error: props.issue,
  };
});

const { visible, msg } = useFieldFlash({
  issue: normalizedIssue,
  trigger: triggerRef,
  behavior: behaviorRef,
});

const hasError = computed(() => shouldShowError.value);
const hasValue = computed(() => props.modelValue !== "");

const hasChoiceModules = computed(() => props.choiceModules.length > 0);
const hasStandardModules = computed(() => props.standardModules.length > 0);

const selectedChoiceModule = computed(() => {
  return props.choiceModules.find((module) => module.key === props.modelValue);
});

/**
 * Rustige instructiemelding.
 *
 * Dit is dus géén foutmelding.
 * Deze verdwijnt zodra de gebruiker een module kiest.
 */
const showChoiceHint = computed(() => {
  return (
    props.required &&
    hasChoiceModules.value &&
    !hasValue.value &&
    !hasError.value
  );
});

function selectModule(moduleKey: EducationModuleKey): void {
  if (props.disabled) {
    return;
  }

  emit("update:modelValue", moduleKey);
  emit("change", moduleKey);
}

function onBlur(): void {
  emit("blur");
}
</script>

<template>
  <section
    class="education-module-field"
    :class="{
      'has-error': hasError,
      'has-value': hasValue,
      'is-valid': hasValue && !hasError,
      'is-disabled': disabled,
    }"
    @focusout="onBlur"
  >
    <div class="education-module-field__label-row">
      <label :id="`${id}-label`" class="input-label">
        <span class="input-label__icon education-module" aria-hidden="true">
          <Puzzle :size="17" :stroke-width="2.4" />
        </span>

        <span>{{ label }}</span>

        <span v-if="required" class="input-label__required" aria-hidden="true">
          *
        </span>
      </label>
    </div>

    <div class="fieldflash-shell-wrapper">
      <FieldFlash
        :visible="visible"
        :has-error="hasError"
        :has-warning="false"
        :id="id"
        :msg="msg"
        :behavior="behaviorRef"
      />

      <div class="education-module-card">
        <div class="education-module-card__header">


          <h3 class="education-module-card__title">
            Standaard inbegrepen en keuzemodule
          </h3>

          <p class="education-module-card__description">
            De standaard onderdelen staan vast op basis van de gekozen datum,
            onderwijssector, programma en groepssamenstelling. Als er
            keuzemodules beschikbaar zijn, kiest u hieronder één extra onderdeel.
          </p>
        </div>

        <div
          v-if="hasStandardModules"
          class="education-module-card__section"
        >
          <h4 class="education-module-card__section-title">
            Standaard inbegrepen
          </h4>

          <ul class="education-module-card__standard-list">
            <li
              v-for="module in standardModules"
              :key="module.key"
              class="education-module-card__standard-item"
            >
              {{ module.label }}
            </li>
          </ul>
        </div>

        <div
          v-if="hasChoiceModules"
          class="education-module-card__section"
        >
          <div class="education-module-card__section-heading">
            <h4 class="education-module-card__section-title">
              Kies één keuzemodule
            </h4>

            <p
              v-if="showChoiceHint"
              class="education-module-card__choice-hint"
            >
              Selecteer de gewenste keuzemodule.
            </p>
          </div>

          <div
            class="education-module-card__choice-grid"
            role="radiogroup"
            :aria-labelledby="`${id}-label`"
            :aria-invalid="hasError ? 'true' : 'false'"
            :aria-describedby="hasError ? `${id}-issue` : undefined"
          >
            <button
              v-for="module in choiceModules"
              :key="module.key"
              type="button"
              class="education-module-choice"
              :class="{
                'is-selected': modelValue === module.key,
              }"
              role="radio"
              :aria-checked="modelValue === module.key ? 'true' : 'false'"
              :disabled="disabled"
              @click="selectModule(module.key)"
            >
              <span class="education-module-choice__content">
                <span class="education-module-choice__label">
                  {{ module.label }}
                </span>
              </span>

              <span
                v-if="modelValue === module.key"
                class="education-module-choice__indicator"
                aria-hidden="true"
              >
                <Check
                  :size="18"
                  :stroke-width="2.8"
                />
              </span>
            </button>
          </div>
        </div>

        <p
          v-else
          class="education-module-card__no-choice"
        >
          Voor deze combinatie is geen losse keuzemodule nodig.
        </p>

        <p
          v-if="selectedChoiceModule"
          class="education-module-card__summary"
        >
          Gekozen keuzemodule:
          <strong>{{ selectedChoiceModule.label }}</strong>
        </p>
      </div>
    </div>
  </section>
</template>

<style scoped>
</style>
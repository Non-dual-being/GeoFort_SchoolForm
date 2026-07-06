<script setup lang="ts">
import { computed, ref, toRef } from "vue";
import { UserRoundCheck } from "lucide-vue-next";

import FieldFlash from "./FieldFlash.vue";
import { useFieldFlash } from "../../composables/useFieldFlash";

import type { ValidationShape } from "../../types/validation/FieldErrorTypes";

const props = withDefaults(
  defineProps<{
    id: string;
    label: string;
    modelValue: string;
    required?: boolean;
    disabled?: boolean;
    placeholder: string;

    maxSupervisors: number | null;
    freeSupervisors: number | null;

    limitText: string;
    helpText: string;

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
  (e: "update:modelValue", value: string): void;
  (e: "change", value: string): void;
  (e: "blur"): void;
}>();

const inputRef = ref<HTMLInputElement | null>(null);
const behaviorRef = ref<"persistent">("persistent");
const triggerRef = toRef(props, "flashTrigger");

const normalizedIssue = computed<ValidationShape>(() => {
  if (!props.issue) {
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

const hasError = computed(() => Boolean(props.issue));
const hasValue = computed(() => props.modelValue.trim().length > 0);
const isValid = computed(() => hasValue.value && !hasError.value);

const describedBy = computed(() => {
  return hasError.value ? `${props.id}-issue` : undefined;
});

defineExpose({
  focus,
});

function focus(): void {
  inputRef.value?.focus();
}

function onInput(event: Event): void {
  const target = event.target as HTMLInputElement;
  const value = target.value.replace(/[^\d]/g, "");

  emit("update:modelValue", value);
  emit("change", value);
}

function onBlur(): void {
  emit("blur");
}
</script>

<template>
  <section
    class="supervisor-count-field"
    :class="{
      'has-error': hasError,
      'has-value': hasValue,
      'is-valid': isValid,
      'is-disabled': disabled,
    }"
  >
    <label
      class="input-label"
      :for="id"
    >
      <span
        class="input-label__icon supervisor-count"
        aria-hidden="true"
      >
        <UserRoundCheck
          :size="17"
          :stroke-width="2.4"
        />
      </span>

      <span>{{ label }}</span>

      <span
        v-if="required"
        class="input-label__required"
        aria-hidden="true"
      >
        *
      </span>
    </label>

    <div class="fieldflash-shell-wrapper">
      <div class="supervisor-count-card">
        <div class="supervisor-count-card__content">
          <p class="supervisor-count-card__title">
            Aantal begeleiders
          </p>

          <p class="supervisor-count-card__description">
            Vul het verwachte aantal begeleiders in. Dit aantal gebruiken we om
            de aanvraag compleet te verwerken.
          </p>

          <div class="supervisor-count-card__meta">
            <span v-if="limitText">
              {{ limitText }}
            </span>

            <span v-if="helpText">
              {{ helpText }}
            </span>
          </div>
        </div>

        <input
          :id="id"
          ref="inputRef"
          class="supervisor-count-card__input"
          :class="{
            'has-error': hasError,
            'has-value': hasValue,
            'is-valid': isValid,
          }"
          type="text"
          inputmode="numeric"
          pattern="[0-9]*"
          autocomplete="off"
          :required="required"
          :disabled="disabled"
          :value="modelValue"
          :aria-invalid="hasError ? 'true' : 'false'"
          :aria-describedby="describedBy"
          :placeholder="placeholder"
          @input="onInput"
          @blur="onBlur"
        />
      </div>

      <FieldFlash
        :visible="visible"
        :has-error="hasError"
        :has-warning="false"
        :id="id"
        :msg="msg"
        :behavior="behaviorRef"
      />
    </div>
  </section>
</template>

<style scoped>
.supervisor-count-field {
  display: flex;
  flex-direction: column;
  width: 100%;
  gap: var(--field-gap);
  margin-top: 1.05rem;
  margin-bottom: var(--field-stack-gap);
}

.input-label__icon.supervisor-count {
  display: inline-flex;
  align-items: center;
  color: var(--color-main-blue);
}

.supervisor-count-card {
  display: grid;
  grid-template-columns: 1fr minmax(7.5rem, 10rem);
  gap: 1rem;
  align-items: center;
  padding: 1rem;
  border: 1px solid rgba(8, 21, 64, 0.16);
  border-radius: calc(var(--field-radius) + 0.25rem);
  background:
    radial-gradient(
      circle at 100% 0%,
      rgba(38, 57, 111, 0.08),
      transparent 34%
    ),
    linear-gradient(
      135deg,
      rgba(255, 255, 255, 0.99),
      rgba(246, 249, 255, 0.96)
    );
  box-shadow:
    0 0.55rem 1.15rem rgba(8, 21, 64, 0.065),
    inset 0 1px 0 rgba(255, 255, 255, 0.9);
}

.supervisor-count-card__content {
  display: grid;
  gap: 0.35rem;
}

.supervisor-count-card__title {
  margin: 0;
  color: var(--color-main-blue-dark);
  font-size: 1rem;
  font-weight: 900;
  line-height: 1.2;
}

.supervisor-count-card__description {
  max-width: 62ch;
  margin: 0;
  color: rgba(8, 21, 64, 0.72);
  font-size: 0.92rem;
  line-height: 1.45;
}

.supervisor-count-card__meta {
  display: flex;
  flex-wrap: wrap;
  gap: 0.45rem;
  margin-top: 0.15rem;
}

.supervisor-count-card__meta span {
  display: inline-flex;
  align-items: center;
  padding: 0.34rem 0.58rem;
  border: 1px solid rgba(38, 57, 111, 0.13);
  border-radius: 999px;
  background:
    linear-gradient(
      180deg,
      rgba(255, 255, 255, 0.96),
      rgba(241, 246, 255, 0.9)
    );
  color: rgba(8, 21, 64, 0.78);
  font-size: 0.82rem;
  font-weight: 760;
  line-height: 1.2;
}

.supervisor-count-card__input {
  width: 100%;
  min-height: 3.3rem;
  padding: 0.65rem 0.8rem;
  border: 1px solid rgba(8, 21, 64, 0.18);
  border-radius: var(--field-radius);
  background: rgba(255, 255, 255, 0.96);
  color: var(--color-main-blue-dark);
  font: inherit;
  font-size: 1.25rem;
  font-weight: 900;
  text-align: center;
  box-shadow:
    inset 0 1px 0 rgba(255, 255, 255, 0.9),
    0 0.14rem 0.34rem rgba(8, 21, 64, 0.035);
  transition:
    border-color var(--field-transition),
    box-shadow var(--field-transition),
    background-color var(--field-transition);
}

.supervisor-count-card__input:focus {
  outline: none;
  border-color: var(--field-border-focus);
  box-shadow: var(--shadow-control-focus);
}

.supervisor-count-card__input.has-error {
  border-color: var(--field-border-error);
}

.supervisor-count-field.has-error .supervisor-count-card {
  border-color: rgba(217, 49, 52, 0.5);
  box-shadow:
    0 0 0 2px rgba(217, 49, 52, 0.055),
    0 0.55rem 1.1rem rgba(217, 49, 52, 0.045);
}

.supervisor-count-field.is-valid .supervisor-count-card {
  border-color: rgba(38, 57, 111, 0.24);
}

.supervisor-count-field.is-disabled {
  opacity: 0.72;
}

@media (max-width: 680px) {
  .supervisor-count-card {
    grid-template-columns: 1fr;
  }

  .supervisor-count-card__input {
    text-align: left;
  }
}
</style>

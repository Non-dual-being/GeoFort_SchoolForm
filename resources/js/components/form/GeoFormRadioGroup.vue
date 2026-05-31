<script setup lang="ts">
import { computed, ref, toRef } from "vue";
import {
    useFieldFlash,
    type ErrorBehavior
} from "../../composables/useFieldFlash"

import type { ValidationShape } from "../../types/validation/FieldErrorTypes";
import FieldFlash from "./FieldFlash.vue";

import type { RadioOptionCjpUsage, InputMode } from "../../types/booking/BookingFieldTypes.ts";

const props = withDefaults(
    defineProps<{
        id: string;
        label: string;
        options: ReadonlyArray<RadioOptionCjpUsage>
        modelValue: string;
        required?: boolean;
        disabled?: boolean;
        issue?: ValidationShape;
        flashTrigger: number;
        errorBehavior?: ErrorBehavior;
        autoDismissMs?: number;
        inputMode?: InputMode; 
    }>(), 
    {
        required: true,
        disabled: false,
        issue: () => ({}),
        errorBehavior: "auto",
        autoDismissMs: 3000,
        inputMode: undefined
    }
);

const emit = defineEmits<{
    (e: "update:modelValue", value: string): void;
    (e: "change", value: string): void;
    (e: "blur"): void;
}>();

const issueRef      = toRef(props, "issue");
const triggerRef    = toRef(props, "flashTrigger");
const behaviorRef   = toRef(props, "errorBehavior");
const dismissRef     = toRef(props, "autoDismissMs");

const { visible, msg } = useFieldFlash({
    issue: issueRef,
    trigger: triggerRef,
    behavior: behaviorRef,
    autoDismissMs: dismissRef,
});

const hasError = computed(() => !!props.issue?.error);
const hasWarning = computed(
    () => !props.issue?.error && Boolean(props.issue?.warning)
)

const hasValue = computed(() => props.modelValue.trim().length > 0);
const firstRadioRef = ref<HTMLInputElement | null>(null);

function onChange(value: string): void {
    emit("update:modelValue", value);
    emit("change", value);
};

function focus(): void {
    firstRadioRef.value?.focus()
};

defineExpose({
    focus
});

</script>

<template>
  <div
    class="field radio-field"
    :class="{
      'has-error': hasError,
      'has-warning': hasWarning && !hasError,
      'has-value': hasValue,
      'is-valid': hasValue && !hasError && !hasWarning,
      'is-disabled': disabled,
    }"
  >
    <span class="input-label">
      <span class="input-label__icon" aria-hidden="true">●</span>
      <span>{{ label }}</span>
      <span v-if="required" class="input-label__required" aria-hidden="true">
        *
      </span>
    </span>

    <div class="fieldflash-shell-wrapper">
      <FieldFlash
        :visible="visible"
        :has-error="hasError"
        :has-warning="hasWarning"
        :id="id"
        :msg="msg"
        :behavior="errorBehavior"
      />

      <div
        class="radio-card-group"
        role="radiogroup"
        :aria-labelledby="`${id}-legend`"
        :aria-describedby="hasError || hasWarning ? `${id}-issue` : undefined"
      >
        <label
          v-for="(option, index) in options"
          :key="option.value"
          class="radio-card"
          :class="{
            'is-selected': modelValue === option.value,
            'has-error': hasError,
            'has-warning': hasWarning && !hasError,
            'is-disabled': disabled,
          }"
        >
          <input
            :id="`${id}-${option.value}`"
            :ref="index === 0 ? 'firstRadioRef' : undefined"
            class="radio-card__input"
            type="radio"
            :name="id"
            :value="option.value"
            :checked="modelValue === option.value"
            :required="required"
            :disabled="disabled"
            :aria-invalid="hasError ? 'true' : 'false'"
            :inputmode="inputMode"
            :autoDisMissMs="autoDismissMs"
            @change="onChange(option.value)"
            @blur="emit('blur')"
          />

          <span class="radio-card__visual" aria-hidden="true"></span>

          <span class="radio-card__content">
            <span class="radio-card__label">{{ option.label }}</span>
            <span v-if="option.description" class="radio-card__description">
              {{ option.description }}
            </span>
          </span>
        </label>
      </div>
    </div>
  </div>
</template>

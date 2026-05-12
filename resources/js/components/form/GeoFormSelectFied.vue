<script setup lang="ts">
import { computed, ref, toRef } from "vue";
import {
  useFieldFlash,
  type ErrorBehavior,
} from "../../composables/useFieldFlash";
import type { ValidationShape } from "../../types/validation/FieldErrorTypes";
import FieldFlash from "./FieldFlash.vue";

type Option = {
  value: string;
  label: string;
};

const props = withDefaults(
  defineProps<{
    id: string;
    label: string;
    options: ReadonlyArray<Option>;
    required?: boolean;
    disabled?: boolean;
    errorBehavior?: ErrorBehavior;
    autoDismissMs?: number;
    autocomplete?: string;
    issue?: ValidationShape;
    flashTrigger: number;
    modelValue: string;
  }>(),
  {
    required: true,
    disabled: false,
    errorBehavior: "auto",
    autoDismissMs: 3000,
    autocomplete: undefined,
    issue: () => ({}),
  },
);

const emit = defineEmits<{
  (e: "update:modelValue", value: string): void;
  (e: "blur"): void;
  (e: "change", value: string): void;
}>();

const issueRef = toRef(props, "issue");
const triggerRef = toRef(props, "flashTrigger");
const behaviorRef = toRef(props, "errorBehavior");
const dismissRef = toRef(props, "autoDismissMs");

const { visible, msg } = useFieldFlash({
  issue: issueRef,
  trigger: triggerRef,
  behavior: behaviorRef,
  autoDismissMs: dismissRef,
});

const hasError = computed(() => Boolean(props.issue?.error));
const hasWarning = computed(
  () => !props.issue?.error && Boolean(props.issue?.warning),
);
const hasValue = computed(() => props.modelValue.trim().length > 0);

const countryClass = computed(() => {
  if (props.modelValue === "België") return "is-belgium";
  return "is-netherlands";
});

const selectRef = ref<HTMLSelectElement | null>(null);

function onChange(event: Event): void {
  const value = (event.target as HTMLSelectElement).value;

  emit("update:modelValue", value);
  emit("change", value);
}

function focus(): void {
  selectRef.value?.focus();
}

defineExpose({
  focus,
});
</script>

<template>
  <div class="field">
    <FieldFlash
      :visible="visible"
      :has-error="hasError"
      :has-warning="hasWarning"
      :id="id"
      :msg="msg"
    />

    <label :for="id" class="input-label">
      {{ label }}
    </label>

    <div
      class="select-shell"
      :class="[
        countryClass,
        {
          'has-error': hasError,
          'has-warning': hasWarning,
          'has-value': hasValue,
          'is-disabled': disabled,
        },
      ]"
    >
      <select
        :id="id"
        ref="selectRef"
        class="form-select"
        :value="modelValue"
        :required="required"
        :disabled="disabled"
        :autocomplete="autocomplete"
        :aria-invalid="hasError ? 'true' : 'false'"
        :aria-describedby="hasError ? `${id}-error` : undefined"
        @change="onChange"
        @blur="emit('blur')"
      >
        <option
          v-for="option in options"
          :key="option.value"
          :value="option.value"
        >
          {{ option.label }}
        </option>
      </select>
    </div>
  </div>
</template>
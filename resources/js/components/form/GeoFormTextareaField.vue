<script setup lang="ts">
import { computed, ref, toRef } from "vue";
import { useFieldFlash, type ErrorBehavior } from "../../composables/useFieldFlash";
import type { ValidationShape } from "../../types/validation/FieldErrorTypes";
import FieldFlash from "./FieldFlash.vue";

const props = withDefaults(
  defineProps<{
    id: string;
    label: string;
    required?: boolean;
    modelValue: string;
    issue?: ValidationShape;
    flashTrigger: number;
    maxlength?: number;
    rows?: number;
    placeholder?: string;
    disabled?: boolean;
    errorBehavior?: ErrorBehavior;
    autoDismissMs?: number;
  }>(),
  {
    required: false,
    issue: () => ({}),
    maxlength: 600,
    rows: 5,
    placeholder: "",
    disabled: false,
    errorBehavior: "auto",
    autoDismissMs: 3000,
  },
);

const emit = defineEmits<{
  (event: "update:modelValue", value: string): void;
  (event: "change", value: string): void;
  (event: "blur"): void;
}>();

const { visible, msg } = useFieldFlash({
  issue: toRef(props, "issue"),
  trigger: toRef(props, "flashTrigger"),
  behavior: toRef(props, "errorBehavior"),
  autoDismissMs: toRef(props, "autoDismissMs"),
});

const textareaRef = ref<HTMLTextAreaElement | null>(null);
const hasError = computed(() => Boolean(props.issue?.error));
const hasWarning = computed(() => !hasError.value && Boolean(props.issue?.warning));
const hasValue = computed(() => props.modelValue.trim().length > 0);

// Array.from telt Unicode-codepoints. Samengestelde emoji kunnen uit meerdere
// codepoints bestaan en tellen daardoor als meer dan één teken.
const characterCount = computed(() => Array.from(props.modelValue).length);
const remainingCharacters = computed(() => Math.max(0, props.maxlength - characterCount.value));
const counterId = computed(() => `${props.id}-character-count`);
const issueId = computed(() => `${props.id}-issue`);
const describedBy = computed(() => (
  visible.value ? `${counterId.value} ${issueId.value}` : counterId.value
));

function onInput(event: Event): void {
  emit("update:modelValue", (event.target as HTMLTextAreaElement).value);
}

function onChange(event: Event): void {
  emit("change", (event.target as HTMLTextAreaElement).value);
}

function focus(): void {
  textareaRef.value?.focus();
}

defineExpose({ focus });
</script>

<template>
  <div
    class="field textarea-field"
    :class="{
      'has-error': hasError,
      'has-warning': hasWarning,
      'has-value': hasValue,
      'is-valid': hasValue && !hasError && !hasWarning,
      'is-disabled': disabled,
    }"
  >
    <label :for="id" class="input-label">
      <span class="input-label__icon" aria-hidden="true">✎</span>
      <span>{{ label }}</span>
      <span v-if="required" class="input-label__required" aria-hidden="true">*</span>
    </label>

    <div class="fieldflash-shell-wrapper">
      <FieldFlash
        :id="id"
        :visible="visible"
        :has-error="hasError"
        :has-warning="hasWarning"
        :msg="msg"
        :behavior="errorBehavior"
      />

      <textarea
        :id="id"
        ref="textareaRef"
        class="form-control form-textarea"
        :class="{ 'has-error': hasError, 'has-warning': hasWarning }"
        :value="modelValue"
        :required="required"
        :maxlength="maxlength"
        :rows="rows"
        :placeholder="placeholder"
        :disabled="disabled"
        :aria-invalid="hasError ? 'true' : 'false'"
        :aria-describedby="describedBy"
        @input="onInput"
        @change="onChange"
        @blur="emit('blur')"
      />
    </div>

    <p
      :id="counterId"
      class="textarea-field__counter"
      :class="{ 'has-error': characterCount > maxlength }"
      aria-live="polite"
    >
      {{ remainingCharacters }} tekens over
    </p>
  </div>
</template>

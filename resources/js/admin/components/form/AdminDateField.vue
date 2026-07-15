<script setup lang="ts">
import { computed, useId } from "vue";

interface Props {
  modelValue: string;
  label: string;
  name?: string;
  min?: string;
  max?: string;
  disabled?: boolean;
  required?: boolean;
  clearable?: boolean;
  error?: string | null;
  hint?: string | null;
}

const props = withDefaults(defineProps<Props>(), {
  name: undefined,
  min: undefined,
  max: undefined,
  disabled: false,
  required: false,
  clearable: false,
  error: null,
  hint: null,
});

const emit = defineEmits<{
  "update:modelValue": [value: string];
}>();

const generatedId = useId();

const inputId = computed(() => {
  return props.name
    ? `admin-date-${props.name}`
    : `admin-date-${generatedId}`;
});

const descriptionId = computed(() => {
  return `${inputId.value}-description`;
});

function handleInput(event: Event): void {
  const target = event.target;

  if (!(target instanceof HTMLInputElement)) {
    return;
  }

  emit("update:modelValue", target.value);
}

function clearValue(): void {
  if (props.disabled) {
    return;
  }

  emit("update:modelValue", "");
}
</script>

<template>
  <div
    class="admin-field"
    :class="{
      'admin-field--invalid': Boolean(error),
      'admin-field--disabled': disabled,
    }"
  >
    <label
      class="admin-field__label"
      :for="inputId"
    >
      {{ label }}

      <span
        v-if="required"
        class="admin-field__required"
        aria-hidden="true"
      >
        *
      </span>
    </label>

    <div class="admin-date-field">
      <input
        :id="inputId"
        class="admin-control admin-date-field__control"
        type="date"
        :name="name"
        :value="modelValue"
        :min="min"
        :max="max"
        :disabled="disabled"
        :required="required"
        :aria-invalid="error ? 'true' : undefined"
        :aria-describedby="hint || error ? descriptionId : undefined"
        @input="handleInput"
      >

      <button
        v-if="clearable && modelValue"
        class="admin-date-field__clear"
        type="button"
        :disabled="disabled"
        :aria-label="`${label} wissen`"
        @click="clearValue"
      >
        <svg
          viewBox="0 0 20 20"
          width="18"
          height="18"
          aria-hidden="true"
          focusable="false"
        >
          <path
            d="m6 6 8 8m0-8-8 8"
            fill="none"
            stroke="currentColor"
            stroke-linecap="round"
            stroke-width="1.8"
          />
        </svg>
      </button>
    </div>

    <p
      v-if="error"
      :id="descriptionId"
      class="admin-field__message admin-field__message--error"
      role="alert"
    >
      {{ error }}
    </p>

    <p
      v-else-if="hint"
      :id="descriptionId"
      class="admin-field__message"
    >
      {{ hint }}
    </p>
  </div>
</template>
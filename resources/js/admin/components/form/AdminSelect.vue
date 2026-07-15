<script setup lang="ts">
import { computed, useId } from "vue";
import type { AdminSelectOption } from "./types";

interface Props {
  modelValue: string;
  label: string;
  options: readonly AdminSelectOption[];
  placeholder?: string;
  name?: string;
  disabled?: boolean;
  required?: boolean;
  error?: string | null;
  hint?: string | null;
}

const props = withDefaults(defineProps<Props>(), {
  placeholder: "Maak een keuze",
  name: undefined,
  disabled: false,
  required: false,
  error: null,
  hint: null,
});

const emit = defineEmits<{
  "update:modelValue": [value: string];
}>();

const generatedId = useId();

const selectId = computed(() => {
  return props.name
    ? `admin-select-${props.name}`
    : `admin-select-${generatedId}`;
});

const descriptionId = computed(() => {
  return `${selectId.value}-description`;
});

function handleChange(event: Event): void {
  const target = event.target;

  if (!(target instanceof HTMLSelectElement)) {
    return;
  }

  emit("update:modelValue", target.value);
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
      :for="selectId"
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

    <div class="admin-select">
      <select
        :id="selectId"
        class="admin-control admin-select__control"
        :name="name"
        :value="modelValue"
        :disabled="disabled"
        :required="required"
        :aria-invalid="error ? 'true' : undefined"
        :aria-describedby="hint || error ? descriptionId : undefined"
        @change="handleChange"
      >
        <option value="">
          {{ placeholder }}
        </option>

        <option
          v-for="option in options"
          :key="option.value"
          :value="option.value"
          :disabled="option.disabled"
        >
          {{ option.label }}
        </option>
      </select>

      <span
        class="admin-select__icon"
        aria-hidden="true"
      >
        <svg
          viewBox="0 0 20 20"
          width="20"
          height="20"
          focusable="false"
        >
          <path
            d="m5.5 7.5 4.5 4.5 4.5-4.5"
            fill="none"
            stroke="currentColor"
            stroke-linecap="round"
            stroke-linejoin="round"
            stroke-width="1.8"
          />
        </svg>
      </span>
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
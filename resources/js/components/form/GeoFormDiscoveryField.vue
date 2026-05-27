<script setup lang="ts">
import { computed, nextTick, ref, toRef, watch } from "vue";
import {
    useFieldFlash,
    type ErrorBehavior,
} from "./../../composables/useFieldFlash"

import type { ValidationShape } from "../../types/validation/FieldErrorTypes";
import FieldFlash from "./FieldFlash.vue";

import { normalizeGeoFortDiscovery } from "../../config/validation/booking.ts";

type Option = {
    value: string;
    label: string;
}

const props = withDefaults(
  defineProps<{
    id: string;
    label: string;
    options: ReadonlyArray<Option>;
    otherOption: string;
    required?: boolean;
    disabled?: boolean;
    errorBehavior?: ErrorBehavior;
    autoDismissMs?: number;
    issue?: ValidationShape;
    flashTrigger: number;
    modelValue: string;
  }>(),
  {
    required: false,
    disabled: false,
    errorBehavior: "auto",
    autoDismissMs: 3000,
    issue: () => ({}),
  },
);

const emit = defineEmits<{
    (e: "update:modelValue", value: string): void;
    (e: "blur"): void;
    (e: "change", value: string): void
}>();

const issueRef = toRef(props, "issue");
const triggerRef = toRef(props, "flashTrigger");
const behaviorRef = toRef(props, "errorBehavior");
const dismissRef = toRef(props, "autoDismissMs");

const { visible, msg } = useFieldFlash({
    issue: issueRef,
    trigger: triggerRef,
    behavior: behaviorRef,
    autoDismissMs: dismissRef
});

const selectRef = ref<HTMLSelectElement | null>(null);
const customInputRef = ref<HTMLInputElement | null>(null);

const selectedValue = ref("");
const customValue = ref("");

const hasError = computed(() => Boolean(props.issue?.error));
const hasWarning = computed(() => 
    !hasError && Boolean(props.issue?.warning)
);

const hasValue = computed(() => props.modelValue.trim().length > 0);

const isOtherSelected = computed(() => 
    selectedValue.value === props.otherOption);

const optionValues = computed(() => props.options.map((option) => option.value));

function getOtherSeparator(): string {
  return `${props.otherOption}:`;
}

function normalizeCustomValue(value: string): string {
  return normalizeGeoFortDiscovery(value).replace(/^:\s*/, "");
}

function syncFromModelValue(value: string): void {
    const raw = normalizeGeoFortDiscovery(value);

    if (raw.length === 0) {
        selectedValue.value = "";
        customValue.value = "";
        return;
    }

    if (optionValues.value.includes(raw)) {
        selectedValue.value = raw;
        customValue.value = "";
        return;
    }

    const customPrefix = `${props.otherOption}`;

    selectedValue.value = props.otherOption;

    if (raw.startsWith(customPrefix)) {
        customValue.value = raw.slice(getOtherSeparator().length).trim();
        return;
    }

    customValue.value = raw;
}

function  buildModelValue(): string {
    if (selectedValue.value.length === 0) {
        return "";
    }

    if (selectedValue.value !== props.otherOption) {
        return selectedValue.value;
    }

    const custom = normalizeCustomValue(customValue.value);

    if (custom.length === 0) {
        return props.otherOption;
    }

    
    return `${props.otherOption}: ${custom}`;

}

function commit(): void {
  const value = buildModelValue();

  emit("update:modelValue", value);
  emit("change", value);
}

function onSelectChange(): void {
  if (selectedValue.value !== props.otherOption) {
    customValue.value = "";
  }

  commit();

  if (selectedValue.value === props.otherOption) {
    nextTick(() => {
      customInputRef.value?.focus();
    });
  }
}

function onCustomInput(): void {
  commit();
}

function onBlur(): void {
  emit("blur");
}

function focus(): void {
    if (isOtherSelected) {
        customInputRef.value?.focus();
    }
    
    selectRef.value?.focus();
}

watch(() => props.modelValue, 
    (value) => {syncFromModelValue(value)},
{
    immediate: true
})
</script>

<template>
  <div
    class="field"
    :class="{
      'has-error': hasError,
      'has-warning': hasWarning && !hasError,
      'has-value': hasValue,
      'is-valid': hasValue && !hasError && !hasWarning,
      'is-disabled': disabled,
    }"
  >
    <label :for="id" class="input-label">
      <span class="input-label__icon" aria-hidden="true">⌄</span>
      <span>{{ label }}</span>
      <span v-if="required" class="input-label__required" aria-hidden="true">
        *
      </span>
    </label>

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
        class="select-shell select-shell--plain no-country-shell"
        :class="{
          'has-error': hasError,
          'has-warning': hasWarning && !hasError,
          'has-value': hasValue,
          'is-disabled': disabled,
        }"
      >
        <select
          :id="id"
          ref="selectRef"
          v-model="selectedValue"
          class="form-select"
          :required="required"
          :disabled="disabled"
          autocomplete="off"
          :aria-invalid="hasError ? 'true' : 'false'"
          :aria-describedby="hasError || hasWarning ? `${id}-issue` : undefined"
          @change="onSelectChange"
          @blur="onBlur"
        >
          <option value="">Geen keuze</option>

          <option
            v-for="option in options"
            :key="option.value"
            :value="option.value"
          >
            {{ option.label }}
          </option>
        </select>
      </div>

      <div v-if="isOtherSelected" class="discovery-custom">
        <label :for="`${id}-custom`" class="discovery-custom__label">
          Wilt u dit toelichten?
        </label>

        <input
          :id="`${id}-custom`"
          ref="customInputRef"
          v-model="customValue"
          class="form-input discovery-custom__input"
          :class="{
            'has-error': hasError,
            'has-warning': hasWarning && !hasError,
          }"
          type="text"
          inputmode="text"
          maxlength="80"
          placeholder="Bijvoorbeeld: via een collega, nieuwsbrief of evenement"
          :disabled="disabled"
          :aria-invalid="hasError ? 'true' : 'false'"
          :aria-describedby="hasError || hasWarning ? `${id}-issue` : undefined"
          @input="onCustomInput"
          @blur="onBlur"
        />
      </div>
    </div>
  </div>
</template>
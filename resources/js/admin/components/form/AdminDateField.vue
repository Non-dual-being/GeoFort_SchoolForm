<script setup lang="ts">
import {
  computed,
  onBeforeUnmount,
  onMounted,
  ref,
  useId,
  watch,
} from "vue";
import flatpickr from "flatpickr";
import { Dutch } from "flatpickr/dist/l10n/nl.js";
import type { Instance as FlatpickrInstance } from "flatpickr/dist/types/instance";
import {
  CalendarDays,
  X,
} from "lucide-vue-next";

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
const inputElement = ref<HTMLInputElement | null>(null);
const picker = ref<FlatpickrInstance | null>(null);

const inputId = computed(() => {
  return props.name
    ? `admin-date-${props.name}`
    : `admin-date-${generatedId}`;
});

const descriptionId = computed(() => {
  return `${inputId.value}-description`;
});

function openPicker(): void {
  if (props.disabled) {
    return;
  }

  picker.value?.open();
}

function clearValue(): void {
  if (props.disabled) {
    return;
  }

  emit("update:modelValue", "");
}

function syncAccessibleInput(
  instance: FlatpickrInstance | null = picker.value,
): void {
  const visibleInput = instance?.altInput;

  if (!visibleInput) return;

  visibleInput.id = inputId.value;
  visibleInput.disabled = props.disabled;
  visibleInput.required = props.required;
  visibleInput.setAttribute(
    "aria-invalid",
    props.error ? "true" : "false",
  );

  if (props.hint || props.error) {
    visibleInput.setAttribute("aria-describedby", descriptionId.value);
  } else {
    visibleInput.removeAttribute("aria-describedby");
  }
}

function syncDateLimits(): void {
  const instance = picker.value;

  if (!instance) {
    return;
  }

  const currentValue = props.modelValue;
  instance.set("minDate", props.min);
  instance.set("maxDate", props.max);

  if (currentValue && instance.input.value !== currentValue) {
    instance.setDate(currentValue, false);
  }

  const calendarDate = currentValue
    && (!props.min || currentValue >= props.min)
    && (!props.max || currentValue <= props.max)
    ? currentValue
    : props.min || props.max;

  if (calendarDate) {
    instance.jumpToDate(calendarDate, false);
  }
}

onMounted(() => {
  if (!inputElement.value) {
    return;
  }

  picker.value = flatpickr(inputElement.value, {
    locale: Dutch,
    dateFormat: "Y-m-d",
    altInput: true,
    altFormat: "j F Y",
    altInputClass: "admin-control admin-date-field__control",
    ariaDateFormat: "j F Y",
    defaultDate: props.modelValue || undefined,
    minDate: props.min,
    maxDate: props.max,
    disableMobile: true,
    allowInput: false,
    clickOpens: true,
    position: "auto center",
    onReady: (_dates, _value, instance) => {
      instance.input.id = `${inputId.value}-value`;
      instance.calendarContainer.classList.add(
        "admin-flatpickr-calendar",
      );
      syncAccessibleInput(instance);
    },
    onChange: (_dates, value) => {
      if (value !== props.modelValue) {
        emit("update:modelValue", value);
      }
    },
  });

  if (props.disabled) {
    syncAccessibleInput();
  }
});

watch(
  () => props.modelValue,
  (value) => {
    if (picker.value?.input.value !== value) {
      picker.value?.setDate(value, false);
    }
  },
);

watch(
  () => [props.min, props.max] as const,
  () => syncDateLimits(),
);

watch(
  () => props.disabled,
  (disabled) => {
    if (inputElement.value) {
      inputElement.value.disabled = disabled;
    }

    syncAccessibleInput();
  },
);

watch(
  () => [props.error, props.hint, props.required] as const,
  () => syncAccessibleInput(),
);

onBeforeUnmount(() => {
  picker.value?.destroy();
  picker.value = null;
});
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
        ref="inputElement"
        class="admin-control admin-date-field__control"
        type="text"
        :name="name"
        :value="modelValue"
        :min="min"
        :max="max"
        :disabled="disabled"
        :required="required"
        :aria-invalid="error ? 'true' : undefined"
        :aria-describedby="
          hint || error
            ? descriptionId
            : undefined
        "
      >

      <button
        v-if="clearable && modelValue"
        class="admin-date-field__clear"
        type="button"
        :disabled="disabled"
        :aria-label="`${label} wissen`"
        @click.stop="clearValue"
      >
        <X
          :size="17"
          aria-hidden="true"
        />
      </button>

      <button
        class="admin-date-field__picker"
        type="button"
        :disabled="disabled"
        :aria-label="`${label} kiezen`"
        @click="openPicker"
      >
        <CalendarDays
          :size="18"
          aria-hidden="true"
        />
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

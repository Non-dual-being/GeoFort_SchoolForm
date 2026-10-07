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

interface DisabledDateReason {
  title: string;
  description: string;
}

interface Props {
  modelValue: string;
  label: string;
  name?: string;
  min?: string;
  max?: string;
  disabledDates?: string[];
  disabledDateReasons?: Record<string, DisabledDateReason>;
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
  disabledDates: () => [],
  disabledDateReasons: undefined,
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
let calendarHelpElement: HTMLDivElement | null = null;

const inputId = computed(() => {
  return props.name
    ? `admin-date-${props.name}`
    : `admin-date-${generatedId}`;
});

const descriptionId = computed(() => {
  return `${inputId.value}-description`;
});

function dateKey(date: Date): string {
  return [
    date.getFullYear(),
    String(date.getMonth() + 1).padStart(2, "0"),
    String(date.getDate()).padStart(2, "0"),
  ].join("-");
}

function setCalendarHelp(reason?: DisabledDateReason): void {
  if (!calendarHelpElement) return;

  const icon = document.createElement("span");
  icon.className = "admin-flatpickr-help__icon";
  icon.textContent = "?";
  icon.setAttribute("aria-hidden", "true");

  const copy = document.createElement("span");
  copy.className = "admin-flatpickr-help__copy";

  const title = document.createElement("strong");
  const description = document.createElement("small");

  if (reason) {
    title.textContent = reason.title;
    description.textContent = reason.description;
    calendarHelpElement.dataset.state = "reason";
  } else {
    title.textContent = "Waarom is een datum niet beschikbaar?";
    description.textContent =
      "Beweeg over een niet-beschikbare datum om te zien waarom deze niet gekozen kan worden.";
    calendarHelpElement.dataset.state = "default";
  }

  copy.append(title, description);
  calendarHelpElement.replaceChildren(icon, copy);
}

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
    disable: [...props.disabledDates],
    disableMobile: true,
    allowInput: false,
    clickOpens: true,
    position: "auto center",
    onReady: (_dates, _value, instance) => {
      instance.input.id = `${inputId.value}-value`;
      instance.calendarContainer.classList.add(
        "admin-flatpickr-calendar",
      );

      if (props.disabledDateReasons !== undefined) {
        calendarHelpElement = document.createElement("div");
        calendarHelpElement.className = "admin-flatpickr-help";
        calendarHelpElement.setAttribute("role", "status");
        calendarHelpElement.setAttribute("aria-live", "polite");
        instance.calendarContainer.appendChild(calendarHelpElement);
        setCalendarHelp();
      }

      syncAccessibleInput(instance);
    },
    onOpen: () => setCalendarHelp(),
    onMonthChange: () => setCalendarHelp(),
    onYearChange: () => setCalendarHelp(),
    onDayCreate: (_dates, _value, _instance, dayElement) => {
      const reason = props.disabledDateReasons?.[dateKey(dayElement.dateObj)];

      if (!reason) return;

      dayElement.classList.add("admin-flatpickr-day-has-help");
      dayElement.title = `${reason.title} - ${reason.description}`;

      const showReason = () => setCalendarHelp(reason);
      const resetReason = () => setCalendarHelp();

      dayElement.addEventListener("mouseenter", showReason);
      dayElement.addEventListener("focus", showReason);
      dayElement.addEventListener("pointerdown", showReason);
      dayElement.addEventListener("mouseleave", resetReason);
      dayElement.addEventListener("blur", resetReason);
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
  () => props.disabledDates,
  (disabledDates) => {
    picker.value?.set("disable", [...disabledDates]);
  },
  { deep: true },
);

watch(
  () => props.disabledDateReasons,
  () => {
    picker.value?.redraw();
    setCalendarHelp();
  },
  { deep: true },
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
  calendarHelpElement = null;
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

<script setup lang="ts">
import {
  computed,
  nextTick,
  onBeforeUnmount,
  onMounted,
  ref,
  toRef,
  watch,
} from "vue";
import flatpickr from "flatpickr";
import { Dutch } from "flatpickr/dist/l10n/nl.js";
import type { Instance as FlatpickrInstance } from "flatpickr/dist/types/instance";
import type { ValidationShape } from "../../types/validation/FieldErrorTypes";
import {
  useFieldFlash,
  type ErrorBehavior,
} from "../../composables/useFieldFlash";
import { fetchDisabledDates } from "../../services/bookingDisabledDatesApi";
import FieldFlash from "./FieldFlash.vue";

const props = withDefaults(
  defineProps<{
    id: string;
    label: string;
    modelValue: string;
    required?: boolean;
    issue?: ValidationShape;
    errorBehavior?: ErrorBehavior;
    autoDismissMs?: number;
    flashTrigger: number;
    autocomplete?: string;
    disabled?: boolean;
  }>(),
  {
    disabled: false,
    errorBehavior: "persistent",
    autoDismissMs: 3000,
    issue: () => ({}),
  },
);

const emit = defineEmits<{
  "update:modelValue": [value: string];
  blur: [];
  loaded: [];
  error: [message: string];
}>();

const inputRef = ref<HTMLInputElement | null>(null);
const fp = ref<FlatpickrInstance | null>(null);

const isLoading = ref(true);
const localError = ref("");
const localFlashTrigger = ref(0);

const issueRef = toRef(props, "issue");
const behaviorRef = toRef(props, "errorBehavior");
const dismissRef = toRef(props, "autoDismissMs");

const effectiveIssue = computed<ValidationShape>(() => {
  if (localError.value) {
    return {
      error: localError.value,
    };
  }

  return issueRef.value ?? {};
});

const effectiveFlashTrigger = computed(() => {
  return props.flashTrigger + localFlashTrigger.value;
});

const { visible, msg } = useFieldFlash({
  issue: effectiveIssue,
  trigger: effectiveFlashTrigger,
  behavior: behaviorRef,
  autoDismissMs: dismissRef,
});

const hasValue = computed(() => props.modelValue.trim().length > 0);

const hasError = computed(() => {
  return Boolean(effectiveIssue.value.error);
});

const hasWarning = computed(() => {
  return !hasError.value && Boolean(effectiveIssue.value.warning);
});

const describedBy = computed(() => {
  if (hasError.value || hasWarning.value) {
    return `${props.id}-issue`;
  }

  return undefined;
});

const inputDisabled = computed(() => {
  return props.disabled || isLoading.value;
});

defineExpose({
  focus,
});

function focus(): void {
  if (fp.value?.altInput) {
    fp.value.altInput.focus();
    return;
  }

  inputRef.value?.focus();
}

onMounted(async () => {
  try {
    const result = await fetchDisabledDates();

    const disabledDatesSet = new Set(result.data.disabledDates);

    const detailByDate = new Map(
      result.data.details.map((detail) => [detail.datum, detail]),
    );

    await nextTick();

    if (!inputRef.value) {
      return;
    }

    fp.value = flatpickr(inputRef.value, {
      locale: Dutch,
      enableTime: false,
      dateFormat: "Y-m-d",

      altInput: true,
      altFormat: "l d F Y",
      altInputClass: "geo-date-field__input geo-date-field__input--alt",

      allowInput: false,
      clickOpens: !props.disabled,
      disableMobile: true,

      minDate: result.data.minDate,
      maxDate: result.data.maxDate,

      disable: [
        ...result.data.disabledDates,

        (date: Date): boolean => {
          const day = date.getDay();

          return day === 0 || day === 6;
        },
      ],

      defaultDate: props.modelValue || undefined,

      onChange: (_selectedDates, dateStr) => {
        localError.value = "";
        emit("update:modelValue", dateStr);
      },

      onClose: () => {
        emit("blur");
      },

      onDayCreate: (_dObj, _dStr, _fp, dayElem) => {
        const date = toIsoDate(dayElem.dateObj);

        if (!disabledDatesSet.has(date)) {
          return;
        }

        const detail = detailByDate.get(date);

        dayElem.classList.add("geo-disabled-date");

        if (detail?.type === "school_vacation") {
          dayElem.classList.add("geo-school-vacation");
          dayElem.title = detail.reden ?? "Schoolvakantie";
          return;
        }

        if (detail?.type === "weekend") {
          dayElem.classList.add("geo-weekend");
          dayElem.title = "Weekend";
          return;
        }

        if (detail?.type === "manual") {
          dayElem.classList.add("geo-manual-blocked");
          dayElem.title = detail.reden ?? "Niet beschikbaar";
        }
      },
    });

    applyDisabledState();

    emit("loaded");
  } catch (error) {
    const message =
      error instanceof Error
        ? error.message
        : "Kalender kon niet worden geladen.";

    localError.value = message;
    localFlashTrigger.value++;

    emit("error", message);
  } finally {
    isLoading.value = false;
    applyDisabledState();
  }
});

watch(
  () => props.modelValue,
  (value) => {
    if (!fp.value) {
      return;
    }

    if (!value) {
      fp.value.clear();
      return;
    }

    if (value !== fp.value.input.value) {
      fp.value.setDate(value, false, "Y-m-d");
    }
  },
);

watch(
  () => props.disabled,
  () => {
    applyDisabledState();
  },
);

onBeforeUnmount(() => {
  fp.value?.destroy();
  fp.value = null;
});

function applyDisabledState(): void {
  if (!fp.value) {
    if (inputRef.value) {
      inputRef.value.disabled = inputDisabled.value;
    }

    return;
  }

  fp.value.set("clickOpens", !inputDisabled.value);

  fp.value.input.disabled = inputDisabled.value;

  if (fp.value.altInput) {
    fp.value.altInput.disabled = inputDisabled.value;
  }
}

function toIsoDate(date: Date): string {
  const year = date.getFullYear();
  const month = String(date.getMonth() + 1).padStart(2, "0");
  const day = String(date.getDate()).padStart(2, "0");

  return `${year}-${month}-${day}`;
}
</script>

<template>
  <div class="geo-date-field">
    <label :for="id">
      {{ label }}
      <span v-if="required" aria-hidden="true">*</span>
    </label>

    <input
      :id="id"
      ref="inputRef"
      class="geo-date-field__input"
      :class="{
        'has-error': hasError && hasValue,
        'has-warning': hasWarning && !hasError,
      }"
      type="text"
      :value="modelValue"
      :required="required"
      :disabled="inputDisabled"
      :autocomplete="autocomplete ?? 'off'"
      :aria-invalid="hasError ? 'true' : 'false'"
      :aria-describedby="describedBy"
    />

    <p v-if="isLoading" class="geo-date-field__help">
      Kalender wordt geladen...
    </p>

    <FieldFlash
      :visible="visible"
      :has-error="hasError"
      :has-warning="hasWarning"
      :id="id"
      :msg="msg"
    />
  </div>
</template>

<style scoped>
.geo-date-field {
  display: flex;
  flex-direction: column;
  gap: 0.4rem;
}

.geo-date-field__input {
  width: 100%;
}

.geo-date-field__help {
  color: #555;
}
</style>

<style>
.flatpickr-day.geo-disabled-date {
  opacity: 0.45;
}

.flatpickr-day.geo-school-vacation {
  background: rgba(8, 21, 64, 0.12);
  border-color: rgba(8, 21, 64, 0.3);
}

.flatpickr-day.geo-weekend {
  background: rgba(120, 120, 120, 0.12);
}

.flatpickr-day.geo-manual-blocked {
  background: rgba(180, 0, 0, 0.15);
  border-color: rgba(180, 0, 0, 0.45);
}

.flatpickr-day.flatpickr-disabled {
  cursor: not-allowed;
}
</style>
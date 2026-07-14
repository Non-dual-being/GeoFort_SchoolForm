<script setup lang="ts">
import { 
  computed, 
  nextTick, 
  ref, 
  toRef, 
  watch,
  onMounted,
  onBeforeUnmount } from "vue";
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

const rootRef = ref<HTMLElement | null>(null);
const buttonRef = ref<HTMLButtonElement | null>(null);
const optionRefs = ref<Array<HTMLButtonElement | null>>([]);
const customInputRef = ref<HTMLInputElement | null>(null);
const selectedValue = ref("");
const customValue = ref("");


const isOpen = ref<boolean>(false);
const activeIndex = ref<number>(-1);

const listboxId = computed(() => `${props.id}-listbox`);
const selectedOption = computed(() => props.options.find((option) =>
  option.value === selectedValue.value
));


const hasError = computed(() => Boolean(props.issue?.error));
const hasWarning = computed(() => 
    !hasError.value && Boolean(props.issue?.warning)
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

    const customPrefix = `${props.otherOption}:`;

    selectedValue.value = props.otherOption;

    if (raw.startsWith(customPrefix)) {
        customValue.value = normalizeGeoFortDiscovery(raw.slice(getOtherSeparator().length));
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

function onCustomInput(): void {
  commit();
}

function onBlur(): void {
  emit("blur");
}

function focus(): void {
  if (isOtherSelected.value) {
    customInputRef.value?.focus();
    return;
  }

  buttonRef.value?.focus();
}

//custom select functions
function openList(): void {
  if (props.disabled) return;

  isOpen.value = true;

  const selectedIndex = props.options.findIndex((option) =>
    option.value === selectedValue.value);
  
    activeIndex.value = selectedValue.value === ""
      ? 0
      : selectedIndex >= 0
        ? selectedIndex + 1
        : 0;

  nextTick(() => {
    optionRefs.value[activeIndex.value]?.focus();
  })

}

function closeList(shouldFocusButton = true): void {
  isOpen.value = false;
  activeIndex.value = -1;

  if (shouldFocusButton) {
    nextTick(() => buttonRef.value?.focus());
  }
}

function toggleList(): void {
  if (isOpen.value) {
    closeList();
    return;
  }

  openList();
}

function selectOption(value: string): void {
  selectedValue.value = value;

  if (selectedValue.value !== props.otherOption) {
    customValue.value = "";
  }

  commit();
  closeList();

  if (selectedValue.value === props.otherOption) {
    nextTick(() => {
      customInputRef.value?.focus();
    });
  }
}

function onButtonKeydown(event: KeyboardEvent): void {
  if (["ArrowDown", "ArrowUp", "Enter", " "].includes(event.key)) {
    event.preventDefault();
    openList();
  }
}

function onOptionKeydown(event: KeyboardEvent): void {
  if (event.key === "Escape") {
    event.preventDefault();
    closeList();
    return;
  }

  if (event.key === "Tab") {
    closeList(false);
    return;
  }

  if (event.key === "ArrowDown") {
    event.preventDefault();
    activeIndex.value = Math.min(activeIndex.value + 1, props.options.length);
    optionRefs.value[activeIndex.value]?.focus();
    return;
  }

  if (event.key === "ArrowUp") {
    event.preventDefault();
    activeIndex.value = Math.max(activeIndex.value - 1, 0);
    optionRefs.value[activeIndex.value]?.focus();
    return;
  }

  if (event.key === "Enter" || event.key === " ") {
    event.preventDefault();

    if (activeIndex.value === 0) {
      selectOption("");
      return;
    }

    const option = props.options[activeIndex.value - 1];

    if (option) {
      selectOption(option.value);
    }
  }
}

function onDocumentPointerDown(event: PointerEvent): void {
  if (!rootRef.value) return;

  if (!rootRef.value.contains(event.target as Node)) {
    if (isOpen.value) {
      isOpen.value = false;
      emit("blur");
    }
  }
}

onMounted(() => {
  document.addEventListener("pointerdown", onDocumentPointerDown);
});

onBeforeUnmount(() => {
  document.removeEventListener("pointerdown", onDocumentPointerDown);
});

defineExpose({
  focus,
});

//dropdown sluiten als waarde wijzigt
watch(
  () => props.modelValue,
  (value) => {
    syncFromModelValue(value);
  },
  { immediate: true },
);


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
    <label :id="`${id}-label`" :for="id" class="input-label">
      <span class="input-label__icon select" aria-hidden="true"></span>
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
        ref="rootRef"
        class="discovery-combobox"
        :class="{ 'is-open': isOpen }"
      >
        <div
          class="select-shell select-shell--plain select-shell--discovery no-country-shell"
          :class="{
            'has-error': hasError,
            'has-warning': hasWarning && !hasError,
            'has-value': hasValue,
            'is-disabled': disabled,
            'is-open': isOpen,
          }"
        >
          <button
            :id="id"
            ref="buttonRef"
            class="form-select discovery-combobox__button"
            type="button"
            role="combobox"
            :aria-labelledby="`${id}-label ${id}`"
            :aria-expanded="isOpen ? 'true' : 'false'"
            :aria-controls="listboxId"
            :aria-invalid="hasError ? 'true' : 'false'"
            :aria-describedby="hasError || hasWarning ? `${id}-issue` : undefined"
            :disabled="disabled"
            @click="toggleList"
            @keydown="onButtonKeydown"
            @blur="!isOpen && emit('blur')"
          >
            <span
              class="discovery-combobox__value"
              :class="{ 'is-placeholder': selectedValue.length === 0 }"
            >
              {{ (selectedOption?.label ?? selectedValue) || "Geen keuze" }}
            </span>
          </button>
        </div>

        <Transition name="discovery-list">
          <div
            v-if="isOpen"
            class="discovery-combobox__list-shell"
          >
            <div
              :id="listboxId"
              class="discovery-combobox__list"
              role="listbox"
              :aria-labelledby="`${id}-label`"
            >
              <button
                :ref="(el) => (optionRefs[0] = el as HTMLButtonElement | null)"
                class="discovery-combobox__option"
                :class="{
                  'is-selected': selectedValue === '',
                  'is-active': activeIndex === 0,
                }"
                type="button"
                role="option"
                :aria-selected="selectedValue === '' ? 'true' : 'false'"
                @click="selectOption('')"
                @keydown="onOptionKeydown"
                @focus="activeIndex = 0"
              >
                Geen keuze
              </button>

              <button
                v-for="(option, index) in options"
                :key="option.value"
                :ref="(el) => (optionRefs[index + 1] = el as HTMLButtonElement | null)"
                class="discovery-combobox__option"
                :class="{
                  'is-selected': option.value === selectedValue,
                  'is-active': index + 1 === activeIndex,
                }"
                type="button"
                role="option"
                :aria-selected="option.value === selectedValue ? 'true' : 'false'"
                @click="selectOption(option.value)"
                @keydown="onOptionKeydown"
                @focus="activeIndex = index + 1"
              >
                {{ option.label }}
              </button>
            </div>
          </div>
        </Transition>
      </div>

      <Transition name="discovery-custom-reveal">
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
      </Transition>
    </div>
  </div>
</template>

<style scoped>
.discovery-custom-reveal-enter-active {
  overflow: hidden;
  transition: all 0.6s ease;
}
.discovery-custom-reveal-leave-active {
  overflow: hidden;
  transition:
    all 0.3s ease;
}

.discovery-custom-reveal-enter-from,
.discovery-custom-reveal-leave-to {
  max-height: 0;
  opacity: 0;
  transform: scale(0.5);
}

.discovery-custom-reveal-enter-to,
.discovery-custom-reveal-leave-from {
  max-height: 8rem;
  opacity: 1;
  transform: scale(1);
}

</style>
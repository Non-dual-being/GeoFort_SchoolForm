<script setup lang="ts">
import {
  computed,
  nextTick,
  onBeforeUnmount,
  onMounted,
  ref,
} from "vue";

import {
  CalendarClock,
  Check,
  ChevronDown,
} from "lucide-vue-next";

import type {
  ProgramKey,
  ProgramOption,
} from "../../types/booking/BookingProgramConfigTypes";

import {
  formatProgramMeta,
  getNoProgramAvailableText,
} from "../../config/booking/educationProgramHelpers";

const props = withDefaults(
  defineProps<{
    id: string;
    label: string;
    modelValue: ProgramKey | "";
    options: ProgramOption[];
    required?: boolean;
    disabled?: boolean;
    issue?: string | null;
    flashTrigger?: number;
  }>(),
  {
    required: true,
    disabled: false,
    issue: null,
    flashTrigger: 0,
  },
);

const emit = defineEmits<{
  (event: "update:modelValue", value: ProgramKey | ""): void;
  (event: "change", value: ProgramKey | ""): void;
  (event: "blur"): void;
}>();

const rootRef = ref<HTMLElement | null>(null);
const buttonRef = ref<HTMLButtonElement | null>(null);
const optionRefs = ref<Array<HTMLButtonElement | null>>([]);

const isOpen = ref(false);
const activeIndex = ref(-1);

const selectedOption = computed(() => {
  return props.options.find((option) => option.key === props.modelValue) ?? null;
});

const hasValue = computed(() => props.modelValue !== "");
const hasIssue = computed(() => props.issue !== null);

const isSelectDisabled = computed(() => {
  return props.disabled || props.options.length === 0;
});

const listboxId = computed(() => `${props.id}-listbox`);

const placeholderText = computed(() => {
  if (props.options.length === 0) {
    return getNoProgramAvailableText();
  }

  return "Selecteer ochtend of dag";
});

const issueKey = computed(() => {
  return `${props.id}-issue-${props.flashTrigger}`;
});

function openList(): void {
  if (isSelectDisabled.value) {
    return;
  }

  isOpen.value = true;

  const selectedIndex = props.options.findIndex(
    (option) => option.key === props.modelValue,
  );

  activeIndex.value = selectedIndex >= 0 ? selectedIndex : 0;

  nextTick(() => {
    optionRefs.value[activeIndex.value]?.focus();
  });
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

function selectOption(option: ProgramOption): void {
  emit("update:modelValue", option.key);
  emit("change", option.key);
  closeList();
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
    activeIndex.value = Math.min(
      activeIndex.value + 1,
      props.options.length - 1,
    );

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

    const option = props.options[activeIndex.value];

    if (option) {
      selectOption(option);
    }
  }
}

function onDocumentPointerDown(event: PointerEvent): void {
  if (!rootRef.value) {
    return;
  }

  if (!rootRef.value.contains(event.target as Node)) {
    if (isOpen.value) {
      isOpen.value = false;
      emit("blur");
    }
  }
}

function focus(): void {
  buttonRef.value?.focus();
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
</script>

<template>
  <div
    ref="rootRef"
    class="program-select-field"
    :class="{
      'has-value': hasValue,
      'has-issue': hasIssue,
      'is-disabled': isSelectDisabled,
      'is-open': isOpen,
    }"
  >
    <label :id="`${id}-label`" class="input-label">
      <span class="input-label__icon program" aria-hidden="true">
        <CalendarClock :size="17" :stroke-width="2.4" />
      </span>

      <span>{{ label }}</span>

      <span
        v-if="required"
        class="input-label__required"
        aria-hidden="true"
      >
        *
      </span>
    </label>

    <p
      v-if="issue"
      :key="issueKey"
      class="program-select__issue"
      role="alert"
    >
      {{ issue }}
    </p>

    <button
      :id="id"
      ref="buttonRef"
      class="program-select__button"
      type="button"
      role="combobox"
      :aria-labelledby="`${id}-label ${id}`"
      :aria-expanded="isOpen ? 'true' : 'false'"
      :aria-controls="listboxId"
      :aria-invalid="hasIssue ? 'true' : 'false'"
      :aria-describedby="issue ? `${id}-issue` : undefined"
      :disabled="isSelectDisabled"
      @click="toggleList"
      @keydown="onButtonKeydown"
      @blur="!isOpen && emit('blur')"
    >
      <span class="program-select__badge" aria-hidden="true">
        TIJD
      </span>

      <span class="program-select__content">
        <span
          class="program-select__value"
          :class="{ 'is-placeholder': !selectedOption }"
        >
          {{ selectedOption?.label ?? placeholderText }}
        </span>

        <span
          v-if="selectedOption"
          class="program-select__meta"
        >
          {{ formatProgramMeta(selectedOption) }}
        </span>

        <span
          v-else
          class="program-select__meta"
        >
          De beschikbare programma’s worden bepaald door datum en onderwijssector.
        </span>
      </span>

      <ChevronDown
        class="program-select__chevron"
        :size="20"
        :stroke-width="2.3"
        aria-hidden="true"
      />
    </button>

    <Transition name="program-select-popover">
      <div
        v-if="isOpen"
        class="program-select__list-shell"
      >
        <div
          :id="listboxId"
          class="program-select__list"
          role="listbox"
          :aria-labelledby="`${id}-label`"
        >
          <button
            v-for="(option, index) in options"
            :key="option.key"
            :ref="(el) => (optionRefs[index] = el as HTMLButtonElement | null)"
            class="program-select__option"
            :class="{
              'is-selected': option.key === modelValue,
              'is-active': index === activeIndex,
            }"
            type="button"
            role="option"
            :aria-selected="option.key === modelValue ? 'true' : 'false'"
            @click="selectOption(option)"
            @keydown="onOptionKeydown"
            @focus="activeIndex = index"
          >
            <span class="program-select__option-main">
              <span class="program-select__option-label">
                {{ option.label }}
              </span>

              <span class="program-select__option-description">
                {{ formatProgramMeta(option) }}
              </span>

              <span
                v-if="option.description.length > 0"
                class="program-select__option-extra"
              >
                {{ option.description.join(" ") }}
              </span>
            </span>

            <Check
              v-if="option.key === modelValue"
              class="program-select__check"
              :size="18"
              :stroke-width="2.8"
              aria-hidden="true"
            />
          </button>
        </div>
      </div>
    </Transition>
  </div>
</template>

<style scoped>
.program-select-field {
  margin-top: 1.15rem;
}

.program-select__issue {
  margin: 0 0 0.5rem;
  color: var(--color-main-red);
  font-size: 0.92rem;
  font-weight: 700;
}

.program-select__button {
  width: 100%;
  min-height: 4.35rem;
  display: flex;
  align-items: center;
  gap: 0.9rem;
  padding: 0.95rem 1rem;
  border: 1px solid rgba(8, 21, 64, 0.18);
  border-radius: 18px;
  background: #fff;
  text-align: left;
  cursor: pointer;
  transition:
    border-color 160ms ease,
    box-shadow 160ms ease,
    transform 160ms ease,
    background-color 160ms ease;
}

.program-select__button:hover {
  border-color: rgba(38, 57, 111, 0.42);
  box-shadow: 0 10px 24px rgba(8, 21, 64, 0.08);
}

.program-select-field.is-open .program-select__button,
.program-select-field.has-value .program-select__button {
  border-color: rgba(38, 57, 111, 0.55);
}

.program-select-field.has-issue .program-select__button {
  border-color: rgba(217, 49, 52, 0.72);
}

.program-select-field.is-disabled .program-select__button {
  cursor: not-allowed;
  opacity: 0.72;
  background: #f7f7f8;
}

.program-select__badge {
  flex: 0 0 auto;
  min-width: 2.6rem;
  height: 2.6rem;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  border-radius: 999px;
  background: rgba(38, 57, 111, 0.1);
  color: var(--color-main-blue-dark);
  font-size: 0.7rem;
  font-weight: 850;
  letter-spacing: 0.07em;
}

.program-select__content {
  min-width: 0;
  flex: 1;
  display: flex;
  flex-direction: column;
  gap: 0.18rem;
}

.program-select__value {
  color: var(--color-main-blue-dark);
  font-weight: 850;
  line-height: 1.2;
}

.program-select__value.is-placeholder {
  color: rgba(8, 21, 64, 0.52);
  font-weight: 700;
}

.program-select__meta {
  color: rgba(8, 21, 64, 0.68);
  font-size: 0.9rem;
  line-height: 1.35;
}

.program-select__chevron {
  flex: 0 0 auto;
  color: var(--color-main-blue);
  transition: transform 160ms ease;
}

.program-select-field.is-open .program-select__chevron {
  transform: rotate(180deg);
}

.program-select__list-shell {
  position: relative;
  z-index: 30;
}

.program-select__list {
  margin-top: 0.55rem;
  padding: 0.45rem;
  border: 1px solid rgba(8, 21, 64, 0.16);
  border-radius: 18px;
  background: #fff;
  box-shadow: 0 18px 38px rgba(8, 21, 64, 0.14);
}

.program-select__option {
  width: 100%;
  display: flex;
  align-items: center;
  gap: 1rem;
  padding: 0.9rem;
  border: 0;
  border-radius: 14px;
  background: transparent;
  text-align: left;
  cursor: pointer;
}

.program-select__option:hover,
.program-select__option.is-active {
  background: rgba(38, 57, 111, 0.08);
}

.program-select__option.is-selected {
  background: rgba(38, 57, 111, 0.12);
}

.program-select__option-main {
  flex: 1;
  min-width: 0;
  display: flex;
  flex-direction: column;
  gap: 0.18rem;
}

.program-select__option-label {
  color: var(--color-main-blue-dark);
  font-weight: 850;
}

.program-select__option-description {
  color: rgba(8, 21, 64, 0.7);
  font-size: 0.88rem;
  line-height: 1.35;
}

.program-select__option-extra {
  color: rgba(8, 21, 64, 0.58);
  font-size: 0.84rem;
  line-height: 1.35;
}

.program-select__check {
  flex: 0 0 auto;
  color: var(--color-main-blue);
}

.program-select-popover-enter-active,
.program-select-popover-leave-active {
  transition:
    opacity 150ms ease,
    transform 150ms ease;
}

.program-select-popover-enter-from,
.program-select-popover-leave-to {
  opacity: 0;
  transform: translateY(-4px);
}
</style>
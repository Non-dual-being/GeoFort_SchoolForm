<script setup lang="ts">
import {
  computed,
  nextTick,
  onBeforeUnmount,
  onMounted,
  ref,
  useId,
  watch,
} from "vue";
import { Check, ChevronDown } from "lucide-vue-next";
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
  allowEmpty?: boolean;
}

const props = withDefaults(defineProps<Props>(), {
  placeholder: "Maak een keuze",
  name: undefined,
  disabled: false,
  required: false,
  error: null,
  hint: null,
  allowEmpty: true,
});

const emit = defineEmits<{
  "update:modelValue": [value: string];
  blur: [];
  change: [value: string];
  open: [];
  close: [];
}>();

const generatedId = useId();
const rootElement = ref<HTMLElement | null>(null);
const triggerElement = ref<HTMLButtonElement | null>(null);
const optionElements = ref<HTMLButtonElement[]>([]);

const open = ref(false);
const activeIndex = ref(-1);
const openAbove = ref(false);
const menuMaxHeight = ref("14rem");
let typeahead = "";
let typeaheadTimer: ReturnType<typeof setTimeout> | undefined;

const selectId = computed(() => {
  return props.name
    ? `admin-select-${props.name}`
    : `admin-select-${generatedId}`;
});

const listboxId = computed(() => {
  return `${selectId.value}-listbox`;
});

const descriptionId = computed(() => {
  return `${selectId.value}-description`;
});

const selectedOption = computed(() => {
  return props.options.find(
    (option) => option.value === props.modelValue,
  );
});

const displayLabel = computed(() => {
  return selectedOption.value?.label ?? props.placeholder;
});

const labelId = computed(() => `${selectId.value}-label`);
const valueId = computed(() => `${selectId.value}-value`);

const menuOptions = computed<readonly AdminSelectOption[]>(() => [
  ...(props.allowEmpty ? [{ value: "", label: props.placeholder }] : []),
  ...props.options,
]);

function setOptionElement(
  element: HTMLButtonElement | null,
  index: number,
): void {
  if (!element) {
    return;
  }

  optionElements.value[index] = element;
}

function firstEnabledIndex(): number {
  return menuOptions.value.findIndex((option) => !option.disabled);
}

function lastEnabledIndex(): number {
  for (let index = menuOptions.value.length - 1; index >= 0; index -= 1) {
    if (!menuOptions.value[index]?.disabled) {
      return index;
    }
  }

  return -1;
}

function selectedIndex(): number {
  return menuOptions.value.findIndex(
    (option) => option.value === props.modelValue,
  );
}

function nextEnabledIndex(
  startIndex: number,
  direction: 1 | -1,
): number {
  if (menuOptions.value.length === 0) {
    return -1;
  }

  let index = startIndex;

  for (let attempts = 0; attempts < menuOptions.value.length; attempts += 1) {
    index += direction;

    if (index >= menuOptions.value.length) {
      index = 0;
    }

    if (index < 0) {
      index = menuOptions.value.length - 1;
    }

    if (!menuOptions.value[index]?.disabled) {
      return index;
    }
  }

  return -1;
}

async function focusActiveOption(): Promise<void> {
  await nextTick();

  if (activeIndex.value < 0) {
    return;
  }

  optionElements.value[activeIndex.value]?.focus();
}

async function openMenu(): Promise<void> {
  if (props.disabled || open.value) {
    return;
  }

  open.value = true;
  emit("open");
  updateMenuPlacement();

  const currentSelectedIndex = selectedIndex();

  activeIndex.value = currentSelectedIndex >= 0
    ? currentSelectedIndex
    : firstEnabledIndex();

  await focusActiveOption();
}

function closeMenu(restoreFocus = true): void {
  if (!open.value) {
    return;
  }

  open.value = false;
  emit("close");
  activeIndex.value = -1;

  if (restoreFocus) {
    void nextTick(() => {
      triggerElement.value?.focus();
    });
  }
}

function toggleMenu(): void {
  if (open.value) {
    closeMenu();
    return;
  }

  void openMenu();
}

function selectOption(option: AdminSelectOption): void {
  if (option.disabled) {
    return;
  }

  if (option.value !== props.modelValue) {
    emit("update:modelValue", option.value);
    emit("change", option.value);
  }

  closeMenu();
}

function updateMenuPlacement(): void {
  const trigger = triggerElement.value;

  if (!trigger) return;

  const rect = trigger.getBoundingClientRect();
  const gap = 8;
  const desiredHeight = 224;
  const spaceBelow = window.innerHeight - rect.bottom - gap;
  const spaceAbove = rect.top - gap;

  openAbove.value = spaceBelow < 160 && spaceAbove > spaceBelow;

  const availableSpace = openAbove.value ? spaceAbove : spaceBelow;
  menuMaxHeight.value = `${Math.max(
    96,
    Math.min(desiredHeight, availableSpace - gap),
  )}px`;
}

function handleViewportChange(): void {
  if (open.value) updateMenuPlacement();
}

function focusMatchingOption(key: string): void {
  if (key.length !== 1 || key.trim() === "") return;

  clearTimeout(typeaheadTimer);
  typeahead += key.toLocaleLowerCase("nl-NL");

  const matchIndex = menuOptions.value.findIndex((option) => {
    return !option.disabled
      && option.label.toLocaleLowerCase("nl-NL").startsWith(typeahead);
  });

  if (matchIndex >= 0) {
    activeIndex.value = matchIndex;
    void focusActiveOption();
  }

  typeaheadTimer = setTimeout(() => {
    typeahead = "";
  }, 700);
}

function handleTriggerKeydown(event: KeyboardEvent): void {
  if (props.disabled) {
    return;
  }

  if (
    event.key === "ArrowDown"
    || event.key === "ArrowUp"
    || event.key === "Enter"
    || event.key === " "
  ) {
    event.preventDefault();
    void openMenu();
    return;
  }

  if (event.key === "Home" || event.key === "End") {
    event.preventDefault();
    void openMenu().then(() => {
      activeIndex.value = event.key === "Home" ? firstEnabledIndex() : lastEnabledIndex();
      void focusActiveOption();
    });
    return;
  }

  if (event.key.length === 1 && event.key.trim() !== "") {
    event.preventDefault();
    void openMenu().then(() => focusMatchingOption(event.key));
  }
}

function handleOptionKeydown(
  event: KeyboardEvent,
  option: AdminSelectOption,
): void {
  if (event.key === "ArrowDown") {
    event.preventDefault();

    activeIndex.value = nextEnabledIndex(
      activeIndex.value,
      1,
    );

    void focusActiveOption();
    return;
  }

  if (event.key === "ArrowUp") {
    event.preventDefault();

    activeIndex.value = nextEnabledIndex(
      activeIndex.value,
      -1,
    );

    void focusActiveOption();
    return;
  }

  if (event.key === "Home") {
    event.preventDefault();
    activeIndex.value = firstEnabledIndex();
    void focusActiveOption();
    return;
  }

  if (event.key === "End") {
    event.preventDefault();
    activeIndex.value = lastEnabledIndex();
    void focusActiveOption();
    return;
  }

  if (event.key === "Enter" || event.key === " ") {
    event.preventDefault();
    selectOption(option);
    return;
  }

  if (event.key === "Escape") {
    event.preventDefault();
    closeMenu();
    return;
  }

  if (event.key === "Tab") {
    closeMenu(false);
    return;
  }

  focusMatchingOption(event.key);
}

function handleDocumentPointerDown(event: PointerEvent): void {
  if (!(event.target instanceof Node)) {
    return;
  }

  if (!rootElement.value?.contains(event.target)) {
    closeMenu(false);
  }
}

function handleFocusout(event: FocusEvent): void {
  const next = event.relatedTarget;
  if (!(next instanceof Node) || !rootElement.value?.contains(next)) emit("blur");
}

onMounted(() => {
  document.addEventListener(
    "pointerdown",
    handleDocumentPointerDown,
  );
  window.addEventListener("resize", handleViewportChange);
  window.addEventListener("scroll", handleViewportChange, true);
});

onBeforeUnmount(() => {
  clearTimeout(typeaheadTimer);
  document.removeEventListener(
    "pointerdown",
    handleDocumentPointerDown,
  );
  window.removeEventListener("resize", handleViewportChange);
  window.removeEventListener("scroll", handleViewportChange, true);
});

watch(
  () => props.disabled,
  (disabled) => {
    if (disabled) closeMenu(false);
  },
);
</script>

<template>
  <div
    ref="rootElement"
    class="admin-field admin-custom-select"
    :class="{
      'admin-field--invalid': Boolean(error),
      'admin-field--disabled': disabled,
      'admin-custom-select--open': open,
      'admin-custom-select--above': openAbove,
    }"
    @focusout="handleFocusout"
  >
    <label
      :id="labelId"
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

    <input
      v-if="name"
      :name="name"
      :value="modelValue"
      type="hidden"
    >

    <button
      :id="selectId"
      ref="triggerElement"
      class="admin-control admin-custom-select__trigger"
      type="button"
      role="combobox"
      :disabled="disabled"
      :aria-expanded="open"
      :aria-controls="listboxId"
      :aria-labelledby="`${labelId} ${valueId}`"
      aria-haspopup="listbox"
      :aria-invalid="error ? 'true' : undefined"
      :aria-required="required || undefined"
      :aria-describedby="hint || error ? descriptionId : undefined"
      @click="toggleMenu"
      @keydown="handleTriggerKeydown"
    >
      <span
        :id="valueId"
        class="admin-custom-select__value"
        :class="{
          'admin-custom-select__value--placeholder':
            !selectedOption,
        }"
      >
        {{ displayLabel }}
      </span>

      <ChevronDown
        class="admin-custom-select__chevron"
        :size="19"
        aria-hidden="true"
      />
    </button>

    <div
      v-if="open"
      :id="listboxId"
      class="admin-custom-select__menu"
      :style="{ maxHeight: menuMaxHeight }"
      role="listbox"
      :aria-labelledby="labelId"
    >
      <button
        v-for="(option, index) in menuOptions"
        :key="option.value"
        :ref="(element) =>
          setOptionElement(
            element as HTMLButtonElement | null,
            index,
          )
        "
        class="admin-custom-select__option"
        :class="{
          'admin-custom-select__option--selected':
            option.value === modelValue,
        }"
        type="button"
        role="option"
        :disabled="option.disabled"
        :aria-selected="option.value === modelValue"
        @click="selectOption(option)"
        @focus="activeIndex = index"
        @keydown="handleOptionKeydown($event, option)"
      >
        <span>
          {{ option.label }}
          <small v-if="option.description" class="admin-custom-select__description">{{ option.description }}</small>
        </span>
        <small v-if="option.badge" class="admin-custom-select__badge">{{ option.badge }}</small>

        <Check
          v-if="option.value === modelValue"
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

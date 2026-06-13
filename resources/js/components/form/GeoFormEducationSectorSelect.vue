<script setup lang="ts">
import { computed, ref, toRef, nextTick, onMounted, onBeforeUnmount } from 'vue';
import { GraduationCap, ChevronDown, Check } from 'lucide-vue-next';
import { useFieldFlash } from '../../composables/useFieldFlash';
import { programOrder } from '../../config/booking/BookingFields.ts';
import FieldFlash from './FieldFlash.vue';

import type { ValidationShape } from '../../types/validation/FieldErrorTypes';
import type {
    SchoolSectorKey,
    ProgramConfig,
    ProgramKey,
} from "./../../types/booking/BookingProgramConfigTypes";
import type {
  BookingField,
  SchoolSectorOption,
} from "../../types/booking/BookingFieldTypes";
import { getIsoWeekdayFromYmd } from '../../config/booking/calendar/helpers.ts';


const props = withDefaults(
    defineProps<{
        id: BookingField,
        label: string,
        options: ReadonlyArray<SchoolSectorOption>
        programs: Record<ProgramKey, ProgramConfig>
        visitDate: string;
        modelValue: string;
        required?: boolean;
        disabled?: boolean;
        issue?: ValidationShape;
        flashTrigger: number;
    }>(), {
        required: true,
        disabled: false,
        issue: () => ({}),

});

const emit = defineEmits<{
    (e: "update:modelValue", value:  SchoolSectorKey | ""): void;
    (e: "change", value: SchoolSectorKey | ""): void;
    (e: "blur"): void;
}>();

const issueRef = toRef(props, "issue");
const triggerRef = toRef(props, "flashTrigger");
const behaviorRef = ref<"persistent">("persistent");


const { visible, msg } = useFieldFlash({
    issue: issueRef,
    trigger: triggerRef,
    behavior: behaviorRef,
});

const rootRef = ref<HTMLElement | null>(null);
const buttonRef = ref<HTMLButtonElement | null>(null);
const optionRefs = ref<Array<HTMLButtonElement | null>>([]);

const isOpen = ref(false);
const activeIndex = ref(-1);

const hasError = computed(() => Boolean(props.issue?.error));
const hasWarning = computed(() => !props.issue?.error && Boolean(props.issue?.warning));

const hasValue = computed(() => props.modelValue.trim().length > 0);

const selectedOption = computed(() => props.options.find((option) => option.value === props.modelValue))

const listboxId = computed(() => `${props.id}-listbox`);

const getAllowedProgramLabels = (schoolSector: SchoolSectorKey): string[] => {
  const selectedWeekday: number | null = getIsoWeekdayFromYmd(props.visitDate);

  return programOrder // ["ochtend", "dag"]
    .filter((programKey) => {
         const program  = props.programs[programKey];
         
         if(!program.allowedSchoolTypes.includes(schoolSector)) return false;

         if (selectedWeekday === null) return true // voorlopg laten staan, maar in principe moet er al een bezoekdag zijn ingevuld

         return program.allowedWeekdays.includes(selectedWeekday as 1 | 2 | 3 | 4 | 5);    
    })
    .map((programKey) => props.programs[programKey].label) // ["ochtendprogramma", "dagprogramma"] || ["dagprogramma"]
 }


function formatProgramAvailability(labels: string[]): string {
  if (labels.length === 0) {
    return "Geen programma beschikbaar op deze datum";
  }

  if (labels.length === 1) {
    return `Beschikbaar: ${labels[0].toLowerCase()}`;
  }

  return `Beschikbaar: ${labels.join(" en ").toLowerCase()}`;
}
 



function openList(): void {
    if (props.disabled) return;
    isOpen.value = true;
    const selectedIndex = props.options.findIndex(
        (option) => option.value === props.modelValue
    );

    activeIndex.value = (selectedIndex >= 0 && selectedIndex < props.options.length)
        ? selectedIndex
        : 0;
    
    nextTick(() => {optionRefs.value[activeIndex.value]?.focus()})
}

function closeList(shouldFocusButton = true): void {
    isOpen.value = false;
    activeIndex.value = -1;

    if (shouldFocusButton) nextTick(() => buttonRef.value?.focus());

}

function toggleList(): void {
    if (isOpen.value) {
        closeList();
        return;
    }

    openList();
}

function selectOption(option: SchoolSectorOption): void {
    emit("update:modelValue", option.value as SchoolSectorKey);
    emit("change", option.value);
    closeList();
}


function focus(): void {
  buttonRef.value?.focus();
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
    activeIndex.value = Math.min(activeIndex.value + 1, props.options.length - 1);
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

</script>

<template>
  <div
    ref="rootRef"
    class="education-select-field"
    :class="{
      'has-error': hasError,
      'has-warning': hasWarning && !hasError,
      'has-value': hasValue,
      'is-valid': hasValue && !hasError && !hasWarning,
      'is-disabled': disabled,
      'is-open': isOpen,
    }"
  >
    <label :id="`${id}-label`" class="input-label">
      <span class="input-label__icon education" aria-hidden="true">
        <GraduationCap :size="17" :stroke-width="2.4" />
      </span>
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
        :behavior="behaviorRef"
      />

      <button
        :id="id"
        ref="buttonRef"
        class="education-select__button"
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
        <span class="education-select__badge" aria-hidden="true">EDU</span>

        <span class="education-select__content">
          <span
            class="education-select__value"
            :class="{ 'is-placeholder': !selectedOption }"
          >
            {{ selectedOption?.label ?? "Kies het schoolniveau" }}
          </span>

          <span v-if="selectedOption" class="education-select__meta">
              {{ formatProgramAvailability(getAllowedProgramLabels(selectedOption.value)) }}
          </span>
        </span>

        <ChevronDown
          class="education-select__chevron"
          :size="20"
          :stroke-width="2.3"
          aria-hidden="true"
        />
      </button>

      <Transition name="education-select-popover">
        <div 
          v-if="isOpen"
          class="education-select__list-shell"
        >
          <div
            :id="listboxId"
            class="education-select__list"
            role="listbox"
            :aria-labelledby="`${id}-label`"
          >
            <button
              v-for="(option, index) in options"
              :key="option.value"
              :ref="(el) => (optionRefs[index] = el as HTMLButtonElement | null)"
              class="education-select__option"
              :class="{
                'is-selected': option.value === modelValue,
                'is-active': index === activeIndex,
              }"
              type="button"
              role="option"
              :aria-selected="option.value === modelValue ? 'true' : 'false'"
              @click="selectOption(option)"
              @keydown="onOptionKeydown"
              @focus="activeIndex = index"
            >
              <span class="education-select__option-main">
                <span class="education-select__option-label">
                  {{ option.label }}
                </span>
                <span class="education-select__option-description">
                  {{ formatProgramAvailability(getAllowedProgramLabels(option.value)) }}
                </span>
              </span>

              <Check
                v-if="option.value === modelValue"
                class="education-select__check"
                :size="18"
                :stroke-width="2.8"
                aria-hidden="true"
              />
            </button>
          </div> 
        </div>
      </Transition>
    </div>
  </div>
</template>


<script setup lang="ts">
import { computed, reactive, ref, toRef, watch } from "vue";
import { Coffee } from "lucide-vue-next";

import FieldFlash from "./FieldFlash.vue";
import { useFieldFlash } from "../../composables/useFieldFlash";

import type {
  FoodAndDrinkOption,
  LunchKey,
} from "../../types/booking/BookingProgramConfigTypes";
import type { ValidationShape } from "../../types/validation/FieldErrorTypes";
import type { SnackFormField } from "../../composables/useFoodAndDrinkSelection";

type SnackSelectionOption = FoodAndDrinkOption & {
  field: SnackFormField;
};

const props = withDefaults(
  defineProps<{
    id: string;
    label: string;
    required?: boolean;
    snackOptions: SnackSelectionOption[];
    lunchOptions: FoodAndDrinkOption[];
    remiseBreak: string;
    kazerneBreak: string;
    fortgrachtBreak: string;
    waterijsje: string;
    glasLimonade: string;
    lunchChoice: "" | "remise_lunch" | "eigen_picknick";
    remiseLunch: string;
    issue?: string | null;
    flashTrigger: number;
    formatCurrency: (price: number) => string;
  }>(),
  {
    required: false,
    issue: null,
  },
);

const emit = defineEmits<{
  (e: "update:remiseBreak", value: string): void;
  (e: "update:kazerneBreak", value: string): void;
  (e: "update:fortgrachtBreak", value: string): void;
  (e: "update:waterijsje", value: string): void;
  (e: "update:glasLimonade", value: string): void;
  (e: "update:lunchChoice", value: "" | "remise_lunch" | "eigen_picknick"): void;
  (e: "update:remiseLunch", value: string): void;
  (e: "change"): void;
  (e: "blur"): void;
}>();

const checkedSnacks = reactive<Record<SnackFormField, boolean>>({
  remiseBreak: false,
  kazerneBreak: false,
  fortgrachtBreak: false,
  waterijsje: false,
  glasLimonade: false,
});

const behaviorRef = ref<"persistent">("persistent");
const triggerRef = toRef(props, "flashTrigger");

const normalizedIssue = computed<ValidationShape>(() => {
  if (!props.issue) {
    return {};
  }

  return {
    error: props.issue,
  };
});

const { visible, msg } = useFieldFlash({
  issue: normalizedIssue,
  trigger: triggerRef,
  behavior: behaviorRef,
});

const hasError = computed(() => Boolean(props.issue));

const lunchByKey = computed<Record<LunchKey, FoodAndDrinkOption | undefined>>(() => {
  return Object.fromEntries(
    props.lunchOptions.map((option) => [option.key, option]),
  ) as Record<LunchKey, FoodAndDrinkOption | undefined>;
});

const remiseLunchOption = computed(() => lunchByKey.value.remise_lunch);
const eigenPicknickOption = computed(() => lunchByKey.value.eigen_picknick);

defineExpose({
  focus,
});

function focus(): void {
  document.getElementById(`${props.id}-lunch-remise_lunch`)?.focus();
}

function numericValue(value: string): string {
  return value.replace(/[^\d]/g, "");
}

function getSnackValue(field: SnackFormField): string {
  return props[field];
}

function emitSnackValue(field: SnackFormField, value: string): void {
  switch (field) {
    case "remiseBreak":
      emit("update:remiseBreak", value);
      break;

    case "kazerneBreak":
      emit("update:kazerneBreak", value);
      break;

    case "fortgrachtBreak":
      emit("update:fortgrachtBreak", value);
      break;

    case "waterijsje":
      emit("update:waterijsje", value);
      break;

    case "glasLimonade":
      emit("update:glasLimonade", value);
      break;
  }

  emit("change");
}

function onSnackToggle(option: SnackSelectionOption, event: Event): void {
  const target = event.target as HTMLInputElement;
  checkedSnacks[option.field] = target.checked;

  if (!target.checked) {
    emitSnackValue(option.field, "0");
    return;
  }

  const currentValue = getSnackValue(option.field).trim();

  if (currentValue === "" || currentValue === "0") {
    emitSnackValue(option.field, String(option.min));
  }
}

function onSnackInput(option: SnackSelectionOption, event: Event): void {
  const target = event.target as HTMLInputElement;
  const value = numericValue(target.value);

  checkedSnacks[option.field] = value !== "0";
  emitSnackValue(option.field, value);
}

function onLunchChoice(value: "remise_lunch" | "eigen_picknick"): void {
  emit("update:lunchChoice", value);

  if (value === "eigen_picknick") {
    emit("update:remiseLunch", "0");
    emit("change");
    return;
  }

  const currentValue = props.remiseLunch.trim();
  const minimumLunchAmount = remiseLunchOption.value?.min ?? 50;

  if (currentValue === "" || currentValue === "0") {
    emit("update:remiseLunch", String(minimumLunchAmount));
  }

  emit("change");
}

function onRemiseLunchInput(event: Event): void {
  const target = event.target as HTMLInputElement;
  emit("update:remiseLunch", numericValue(target.value));
  emit("change");
}

watch(
  () => [
    props.remiseBreak,
    props.kazerneBreak,
    props.fortgrachtBreak,
    props.waterijsje,
    props.glasLimonade,
  ],
  () => {
    for (const option of props.snackOptions) {
      const value = getSnackValue(option.field);

      if (value === "0") {
        checkedSnacks[option.field] = false;
      } else if (value !== "") {
        checkedSnacks[option.field] = true;
      }
    }
  },
  { immediate: true },
);
</script>

<template>
  <section
    class="food-drink-selection-field"
    :class="{ 'has-error': hasError }"
    :aria-label="label"
  >
    <label class="input-label">
      <span
        class="input-label__icon food-drink-selection"
        aria-hidden="true"
      >
        <Coffee
          :size="17"
          :stroke-width="2.4"
        />
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

    <div class="fieldflash-shell-wrapper">
      <div class="food-drink-selection-card">
        <section class="food-drink-selection-section">
          <header class="food-drink-selection-section__header">
            <h3 class="food-drink-selection-section__title">
              Snack aanbod
            </h3>

            <p class="food-drink-selection-section__hint">
              Vink een snack aan en vul het aantal in.
            </p>
          </header>

          <div class="food-drink-selection-options">
            <article
              v-for="option in snackOptions"
              :key="option.key"
              class="food-drink-selection-option"
              :class="{
                'is-selected': checkedSnacks[option.field],
                'is-muted': !checkedSnacks[option.field],
              }"
            >
              <label
                class="food-drink-selection-option__main"
                :for="`${id}-${option.key}`"
              >
                <input
                  :id="`${id}-${option.key}`"
                  class="food-drink-selection-option__choice"
                  type="checkbox"
                  :checked="checkedSnacks[option.field]"
                  @change="(event) => onSnackToggle(option, event)"
                  @blur="emit('blur')"
                />

                <span class="food-drink-selection-option__text">
                  <span class="food-drink-selection-option__title">
                    {{ option.label }}
                  </span>

                  <span class="food-drink-selection-option__description">
                    {{ option.description }}
                  </span>
                </span>
              </label>

              <span class="food-drink-selection-price">
                {{ formatCurrency(option.price) }}
              </span>

              <span class="food-drink-selection-amount-wrap">
                <input
                  class="food-drink-selection-amount"
                  type="text"
                  inputmode="numeric"
                  pattern="[0-9]*"
                  autocomplete="off"
                  :aria-label="`Aantal ${option.label}`"
                  :disabled="!checkedSnacks[option.field]"
                  :value="checkedSnacks[option.field] ? getSnackValue(option.field) : '0'"
                  :placeholder="String(option.min)"
                  @input="(event) => onSnackInput(option, event)"
                  @blur="emit('blur')"
                />

                <span
                  v-if="!checkedSnacks[option.field]"
                  class="food-drink-selection-helper"
                >
                  Vink aan om aantallen door te geven
                </span>
              </span>
            </article>
          </div>
        </section>

        <section class="food-drink-selection-section">
          <header class="food-drink-selection-section__header">
            <h3 class="food-drink-selection-section__title">
              Lunch aanbod
            </h3>

            <p class="food-drink-selection-section__hint">
              Kies een lunchoptie.
            </p>
          </header>

          <div class="food-drink-selection-lunch">
            <article
              v-if="remiseLunchOption"
              class="food-drink-selection-option food-drink-selection-option--lunch"
              :class="{ 'is-selected': lunchChoice === 'remise_lunch' }"
            >
              <label
                class="food-drink-selection-option__main"
                :for="`${id}-lunch-remise_lunch`"
              >
                <input
                  :id="`${id}-lunch-remise_lunch`"
                  class="food-drink-selection-option__choice"
                  type="radio"
                  name="food-drink-lunch-choice"
                  value="remise_lunch"
                  :checked="lunchChoice === 'remise_lunch'"
                  @change="onLunchChoice('remise_lunch')"
                  @blur="emit('blur')"
                />

                <span class="food-drink-selection-option__text">
                  <span class="food-drink-selection-option__title">
                    {{ remiseLunchOption.label }}
                  </span>

                  <span class="food-drink-selection-option__description">
                    {{ remiseLunchOption.description }}
                  </span>
                </span>
              </label>

              <span class="food-drink-selection-price">
                {{ formatCurrency(remiseLunchOption.price) }}
              </span>

              <input
                v-if="lunchChoice === 'remise_lunch'"
                class="food-drink-selection-amount"
                type="text"
                inputmode="numeric"
                pattern="[0-9]*"
                autocomplete="off"
                aria-label="Aantal remiselunches"
                :value="remiseLunch"
                :placeholder="String(remiseLunchOption.min)"
                @input="onRemiseLunchInput"
                @blur="emit('blur')"
              />
            </article>

            <article
              v-if="eigenPicknickOption"
              class="food-drink-selection-option food-drink-selection-option--lunch"
              :class="{ 'is-selected': lunchChoice === 'eigen_picknick' }"
            >
              <label
                class="food-drink-selection-option__main"
                :for="`${id}-lunch-eigen_picknick`"
              >
                <input
                  :id="`${id}-lunch-eigen_picknick`"
                  class="food-drink-selection-option__choice"
                  type="radio"
                  name="food-drink-lunch-choice"
                  value="eigen_picknick"
                  :checked="lunchChoice === 'eigen_picknick'"
                  @change="onLunchChoice('eigen_picknick')"
                  @blur="emit('blur')"
                />

                <span class="food-drink-selection-option__text">
                  <span class="food-drink-selection-option__title">
                    {{ eigenPicknickOption.label }}
                  </span>

                  <span class="food-drink-selection-option__description">
                    {{ eigenPicknickOption.description }}
                  </span>
                </span>
              </label>

              <span class="food-drink-selection-price">
                {{ formatCurrency(eigenPicknickOption.price) }}
              </span>
            </article>
          </div>
        </section>
      </div>

      <FieldFlash
        :visible="visible"
        :has-error="hasError"
        :has-warning="false"
        :id="id"
        :msg="msg"
        :behavior="behaviorRef"
      />
    </div>
  </section>
</template>

<style scoped>
.food-drink-selection-field {
  display: flex;
  flex-direction: column;
  width: 100%;
  gap: var(--field-gap);
  margin-top: 1.05rem;
  margin-bottom: var(--field-stack-gap);
}

.input-label__icon.food-drink-selection {
  display: inline-flex;
  align-items: center;
  color: var(--color-main-blue);
}

.food-drink-selection-card {
  display: grid;
  gap: 1.35rem;
  min-width: 0;
  padding: 1rem;
  border: 1px solid rgba(8, 21, 64, 0.16);
  border-radius: calc(var(--field-radius) + 0.25rem);
  background:
    radial-gradient(
      circle at 100% 0%,
      rgba(38, 57, 111, 0.08),
      transparent 34%
    ),
    linear-gradient(
      135deg,
      rgba(255, 255, 255, 0.99),
      rgba(246, 249, 255, 0.96)
    );
  box-shadow:
    0 0.55rem 1.15rem rgba(8, 21, 64, 0.065),
    inset 0 1px 0 rgba(255, 255, 255, 0.9);
}

.food-drink-selection-section {
  display: grid;
  gap: 0.85rem;
  min-width: 0;
}

.food-drink-selection-section__header {
  display: grid;
  gap: 0.25rem;
  min-width: 0;
}

.food-drink-selection-section__title {
  margin: 0;
  color: var(--color-main-blue-dark);
  font-size: 1rem;
  font-weight: 900;
  line-height: 1.2;
}

.food-drink-selection-section__hint {
  margin: 0;
  color: rgba(8, 21, 64, 0.68);
  font-size: 0.9rem;
  line-height: 1.4;
}

.food-drink-selection-options,
.food-drink-selection-lunch {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(min(100%, 28rem), 1fr));
  gap: 0.75rem;
  min-width: 0;
}

.food-drink-selection-option {
  display: grid;
  grid-template-columns: minmax(0, 1fr) auto minmax(5.6rem, 7rem);
  gap: 0.75rem;
  align-items: center;
  min-width: 0;
  padding: 0.78rem;
  border: 1px solid rgba(38, 57, 111, 0.14);
  border-radius: var(--field-radius);
  background: rgba(255, 255, 255, 0.82);
  transition:
    border-color var(--field-transition),
    box-shadow var(--field-transition),
    background-color var(--field-transition);
}

.food-drink-selection-option--lunch {
  grid-template-columns: minmax(0, 1fr) auto minmax(5.6rem, 7rem);
}

.food-drink-selection-option--lunch:not(:has(.food-drink-selection-amount)) {
  grid-template-columns: minmax(0, 1fr) auto;
}

.food-drink-selection-option.is-selected {
  border-color: rgba(38, 57, 111, 0.35);
  background: rgba(255, 255, 255, 0.97);
  box-shadow: 0 0.35rem 0.75rem rgba(8, 21, 64, 0.055);
}

.food-drink-selection-option.is-muted {
  border-color: rgba(38, 57, 111, 0.1);
  background:
    linear-gradient(
      135deg,
      rgba(255, 255, 255, 0.72),
      rgba(246, 249, 255, 0.62)
    );
}

.food-drink-selection-option.is-muted .food-drink-selection-option__text {
  opacity: 0.76;
}

.food-drink-selection-option.is-muted .food-drink-selection-price {
  opacity: 0.84;
}

.food-drink-selection-option__main {
  display: grid;
  grid-template-columns: auto minmax(0, 1fr);
  gap: 0.65rem;
  align-items: start;
  min-width: 0;
  cursor: pointer;
}

.food-drink-selection-option__choice {
  width: 1.15rem;
  height: 1.15rem;
  margin: 0.18rem 0 0;
  accent-color: var(--color-main-blue);
}

.food-drink-selection-option__text {
  display: grid;
  gap: 0.2rem;
  min-width: 0;
}

.food-drink-selection-option__title {
  color: var(--color-main-blue-dark);
  font-size: 0.95rem;
  font-weight: 900;
  line-height: 1.2;
}

.food-drink-selection-option__description {
  color: rgba(8, 21, 64, 0.7);
  font-size: 0.86rem;
  line-height: 1.35;
}

.food-drink-selection-price {
  justify-self: end;
  flex-shrink: 0;
  padding: 0.32rem 0.56rem;
  border: 1px solid color-mix(in srgb, var(--color-accent-warm-muted) 26%, transparent);
  border-radius: 999px;
  background:
    linear-gradient(
      180deg,
      var(--color-accent-warm-softer),
      var(--color-accent-warm-soft)
    );
  color: var(--color-main-blue-dark);
  font-size: 0.8rem;
  font-weight: 850;
  line-height: 1.15;
  white-space: nowrap;
}

.food-drink-selection-amount-wrap {
  position: relative;
  display: block;
  width: 100%;
  cursor: default;
}

.food-drink-selection-amount {
  width: 100%;
  min-height: 2.75rem;
  padding: 0.55rem 0.65rem;
  border: 1px solid rgba(8, 21, 64, 0.18);
  border-radius: var(--field-radius);
  background: rgba(255, 255, 255, 0.96);
  color: var(--color-main-blue-dark);
  font: inherit;
  font-size: 1.05rem;
  font-weight: 900;
  text-align: center;
}

.food-drink-selection-amount:disabled {
  border-color: rgba(8, 21, 64, 0.1);
  background:
    linear-gradient(
      135deg,
      rgba(255, 255, 255, 0.68),
      rgba(238, 244, 255, 0.72)
    );
  color: rgba(8, 21, 64, 0.52);
  cursor: default;
  opacity: 0.72;
  filter: blur(0.15px);
}

.food-drink-selection-helper {
  position: absolute;
  inset: 0;
  z-index: 2;
  display: grid;
  place-items: center;
  padding: 0.24rem 0.42rem;
  border-radius: var(--field-radius);
  background:
    linear-gradient(
      135deg,
      rgba(255, 255, 255, 0.98),
      rgba(244, 248, 255, 0.98)
    );
  color: var(--color-main-blue-dark);
  font-size: 0.68rem;
  font-weight: 850;
  line-height: 1.1;
  text-align: center;
  opacity: 0;
  pointer-events: none;
  transform: translateY(0.12rem);
  transition:
    opacity 160ms ease,
    transform 180ms ease;
}

.food-drink-selection-amount-wrap:hover .food-drink-selection-helper,
.food-drink-selection-option.is-muted:focus-within .food-drink-selection-helper {
  opacity: 1;
  transform: translateY(0);
}

.food-drink-selection-amount:focus {
  outline: none;
  border-color: var(--field-border-focus);
  box-shadow: var(--shadow-control-focus);
}

.food-drink-selection-field.has-error .food-drink-selection-card {
  border-color: rgba(217, 49, 52, 0.5);
  box-shadow:
    0 0 0 2px rgba(217, 49, 52, 0.055),
    0 0.55rem 1.1rem rgba(217, 49, 52, 0.045);
}

@media (max-width: 760px) {
  .food-drink-selection-option,
  .food-drink-selection-option--lunch,
  .food-drink-selection-option--lunch:not(:has(.food-drink-selection-amount)) {
    grid-template-columns: 1fr;
    align-items: start;
  }

  .food-drink-selection-price {
    justify-self: start;
  }

  .food-drink-selection-amount {
    text-align: left;
  }

  .food-drink-selection-helper {
    place-items: center start;
  }
}
</style>

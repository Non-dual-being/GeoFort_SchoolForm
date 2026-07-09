<script setup lang="ts">
import { computed, ref, toRef } from "vue";
import { FileDown, ShieldCheck } from "lucide-vue-next";

import FieldFlash from "./FieldFlash.vue";
import { useFieldFlash } from "../../composables/useFieldFlash";

import type { ValidationShape } from "../../types/validation/FieldErrorTypes";

const props = withDefaults(
  defineProps<{
    id: string;
    label: string;
    modelValue: boolean;
    required?: boolean;
    termsUrl: string;
    issue?: string | null;
    flashTrigger: number;
  }>(),
  {
    required: false,
    issue: null,
  },
);

const emit = defineEmits<{
  (e: "update:modelValue", value: boolean): void;
  (e: "change"): void;
  (e: "blur"): void;
}>();

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

function onChange(event: Event): void {
  const target = event.target as HTMLInputElement;
  emit("update:modelValue", target.checked);
  emit("change");
}
</script>

<template>
  <section
    class="terms-acceptance-field"
    :class="{ 'has-error': hasError }"
    :aria-label="label"
  >
    <div
      class="input-label"
      :id="`${id}-label`"
    >
      <span
        class="input-label__icon terms-acceptance"
        aria-hidden="true"
      >
        <ShieldCheck
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
    </div>

    <div class="fieldflash-shell-wrapper">
      <div class="terms-acceptance-card">
        <div class="terms-acceptance-card__content">
          <h3 class="terms-acceptance-card__title">Voorwaarden</h3>

          <p class="terms-acceptance-card__description">
            Lees de voorwaarden voordat u de aanvraag verstuurt.
          </p>
        </div>

        <a
          class="terms-acceptance-card__link"
          :href="termsUrl"
          target="_blank"
          rel="noopener"
        >
          <FileDown
            :size="17"
            :stroke-width="2.4"
            aria-hidden="true"
          />
          <span>Download voorwaarden</span>
        </a>

        <label
          class="terms-acceptance-card__check"
          :for="id"
        >
          <input
            :id="id"
            type="checkbox"
            :checked="modelValue"
            @change="onChange"
            @blur="emit('blur')"
          />

          <span>Ik ga akkoord met de voorwaarden.</span>
        </label>
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
.terms-acceptance-field {
  display: flex;
  flex-direction: column;
  width: 100%;
  gap: var(--field-gap);
  margin-top: 1.05rem;
  margin-bottom: var(--field-stack-gap);
}

.input-label__icon.terms-acceptance {
  display: inline-flex;
  align-items: center;
  color: var(--color-main-blue);
}

.terms-acceptance-card {
  display: grid;
  grid-template-columns: minmax(0, 1fr) auto;
  gap: 0.85rem;
  align-items: center;
  min-width: 0;
  padding: 1rem;
  border: 1px solid rgba(8, 21, 64, 0.16);
  border-radius: calc(var(--field-radius) + 0.25rem);
  background: rgba(246, 249, 255, 0.92);
  box-shadow:
    0 0.45rem 1rem rgba(8, 21, 64, 0.055),
    inset 0 1px 0 rgba(255, 255, 255, 0.9);
}

.terms-acceptance-card__content {
  display: grid;
  gap: 0.28rem;
  min-width: 0;
}

.terms-acceptance-card__title {
  margin: 0;
  color: var(--color-main-blue-dark);
  font-size: 1rem;
  font-weight: 900;
  line-height: 1.2;
}

.terms-acceptance-card__description {
  margin: 0;
  color: rgba(8, 21, 64, 0.68);
  font-size: 0.9rem;
  line-height: 1.4;
}

.terms-acceptance-card__link {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 0.42rem;
  min-height: 2.5rem;
  padding: 0.58rem 0.78rem;
  border: 1px solid rgba(38, 57, 111, 0.18);
  border-radius: var(--field-radius);
  background: rgba(255, 255, 255, 0.94);
  color: var(--color-main-blue-dark);
  font-size: 0.86rem;
  font-weight: 850;
  line-height: 1.1;
  text-decoration: none;
  white-space: nowrap;
  transition:
    border-color var(--field-transition),
    box-shadow var(--field-transition),
    transform var(--field-transition);
}

.terms-acceptance-card__link:hover {
  border-color: rgba(38, 57, 111, 0.34);
  transform: translateY(-1px);
  box-shadow: 0 0.25rem 0.55rem rgba(8, 21, 64, 0.08);
}

.terms-acceptance-card__link:focus-visible,
.terms-acceptance-card__check input:focus-visible {
  outline: none;
  box-shadow: var(--shadow-control-focus);
}

.terms-acceptance-card__check {
  grid-column: 1 / -1;
  display: flex;
  gap: 0.65rem;
  align-items: flex-start;
  min-width: 0;
  padding: 0.72rem 0.78rem;
  border: 1px solid rgba(38, 57, 111, 0.12);
  border-radius: var(--field-radius);
  background: rgba(255, 255, 255, 0.84);
  color: rgba(8, 21, 64, 0.82);
  font-size: 0.92rem;
  font-weight: 780;
  line-height: 1.35;
  cursor: pointer;
}

.terms-acceptance-card__check input {
  width: 1.15rem;
  height: 1.15rem;
  margin: 0.08rem 0 0;
  accent-color: var(--color-main-blue);
  flex: 0 0 auto;
}

.terms-acceptance-field.has-error .terms-acceptance-card {
  border-color: rgba(217, 49, 52, 0.5);
  box-shadow:
    0 0 0 2px rgba(217, 49, 52, 0.055),
    0 0.55rem 1.1rem rgba(217, 49, 52, 0.045);
}

@media (max-width: 700px) {
  .terms-acceptance-card {
    grid-template-columns: 1fr;
  }

  .terms-acceptance-card__link {
    justify-self: start;
    white-space: normal;
  }
}
</style>

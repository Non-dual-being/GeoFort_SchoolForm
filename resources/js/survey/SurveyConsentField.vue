<script setup lang="ts">
import { MessageSquareText } from "lucide-vue-next";

defineProps<{ modelValue: boolean }>();
defineEmits<{ "update:modelValue": [value: boolean] }>();
</script>

<template>
  <section class="survey-consent" aria-labelledby="survey-consent-title">
    <h3 id="survey-consent-title" class="input-label survey-consent__title">
      <span class="input-label__icon survey-consent__icon" aria-hidden="true">
        <MessageSquareText :size="17" :stroke-width="2.4" />
      </span>
      <span>Bezoekevaluatie <span class="survey-consent__optional">· vrijwillig</span></span>
    </h3>
    <div class="survey-consent__card">
      <label class="survey-consent__check" for="evaluationConsent">
        <input
          id="evaluationConsent"
          type="checkbox"
          :checked="modelValue"
          aria-describedby="survey-consent-description survey-consent-privacy"
          @change="$emit('update:modelValue', ($event.target as HTMLInputElement).checked)"
        >
        <span>GeoFort mag mij benaderen voor een bezoekevaluatie.</span>
      </label>
      <p id="survey-consent-description">Je ontvangt na jullie bezoek één e-mail. Je keuze heeft geen invloed op je aanvraag.</p>
      <p id="survey-consent-privacy" class="survey-consent__privacy">
        Je antwoorden zijn gekoppeld aan jullie bezoek en alleen voor ons onderwijsteam.
        We vragen geen leerlinggegevens. Afmelden kan via de evaluatiemail of
        <a href="mailto:onderwijs@geofort.nl">onderwijs@geofort.nl</a>.
      </p>
    </div>
  </section>
</template>

<style scoped>
.survey-consent {
  display: grid;
  gap: var(--field-gap);
  min-width: 0;
  margin-top: 1.05rem;
}
.survey-consent__title {
  display: flex;
  align-items: center;
  gap: .42rem;
  margin: 0;
  color: var(--color-main-blue-dark);
  font-size: .95rem;
  font-weight: 800;
  line-height: 1.4;
}
.survey-consent__icon {
  display: inline-flex;
  flex: 0 0 auto;
  align-items: center;
  color: var(--color-main-blue);
}
.survey-consent__optional {
  color: rgba(8, 21, 64, .68);
  font-weight: 500;
}
.survey-consent__card {
  display: grid;
  gap: .65rem;
  min-width: 0;
  padding: 1rem;
  border: 1px solid rgba(8, 21, 64, .16);
  border-radius: calc(var(--field-radius) + .25rem);
  background: rgba(246, 249, 255, .92);
  box-shadow: 0 .45rem 1rem rgba(8, 21, 64, .055), inset 0 1px 0 rgba(255, 255, 255, .9);
}
.survey-consent__check {
  display: flex;
  gap: .65rem;
  align-items: flex-start;
  min-width: 0;
  padding: .72rem .78rem;
  border: 1px solid rgba(38, 57, 111, .12);
  border-radius: var(--field-radius);
  background: rgba(255, 255, 255, .84);
  color: rgba(8, 21, 64, .82);
  font-size: .92rem;
  font-weight: 780;
  line-height: 1.35;
  cursor: pointer;
}
.survey-consent__check input {
  flex: 0 0 auto;
  width: 1.15rem;
  height: 1.15rem;
  margin: .08rem 0 0;
  accent-color: var(--color-main-blue);
}
.survey-consent__check input:focus-visible,
.survey-consent a:focus-visible {
  outline: 2px solid var(--color-main-blue);
  outline-offset: 3px;
}
.survey-consent p {
  margin: 0;
  color: rgba(8, 21, 64, .68);
  font-size: .9rem;
  line-height: 1.5;
}
.survey-consent .survey-consent__privacy { font-size: .85rem; }
.survey-consent a {
  color: var(--color-main-blue);
  text-underline-offset: .15em;
  overflow-wrap: anywhere;
}
</style>

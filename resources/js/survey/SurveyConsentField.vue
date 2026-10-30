<script setup lang="ts">
defineProps<{ modelValue: boolean }>();
defineEmits<{ "update:modelValue": [value: boolean] }>();
</script>

<template>
  <section class="survey-consent" aria-labelledby="survey-consent-title">
    <h3 id="survey-consent-title" class="survey-consent__title">Bezoekevaluatie · vrijwillig</h3>
    <label class="survey-consent__check" for="evaluationConsent">
      <input
        id="evaluationConsent"
        type="checkbox"
        :checked="modelValue"
        aria-describedby="survey-consent-description"
        @change="$emit('update:modelValue', ($event.target as HTMLInputElement).checked)"
      >
      <span>Ja, GeoFort mag mij na ons bezoek één e-mail sturen voor een korte bezoekevaluatie.</span>
    </label>
    <p id="survey-consent-description">Je keuze heeft geen invloed op je aanvraag.</p>
    <details>
      <summary>Wat doen we met je antwoorden?</summary>
      <p>We koppelen de evaluatie aan jullie bezoek en de gevolgde modules. Het onderwijsteam gebruikt de antwoorden intern om het aanbod te verbeteren. De evaluatie is dus niet anoniem. We vragen geen gegevens van leerlingen.</p>
      <p>Je kunt je toestemming altijd intrekken via onderwijs@geofort.nl of de link in de evaluatiemail.</p>
    </details>
  </section>
</template>

<style scoped>
.survey-consent {
  display: grid;
  gap: .75rem;
  min-width: 0;
  margin-top: 1.05rem;
  padding: 1rem;
  border: 1px solid rgba(8, 21, 64, .16);
  border-radius: calc(var(--field-radius) + .25rem);
  background: rgba(246, 249, 255, .92);
  box-shadow: 0 .45rem 1rem rgba(8, 21, 64, .055), inset 0 1px 0 rgba(255, 255, 255, .9);
}
.survey-consent__title {
  margin: 0;
  color: var(--color-main-blue-dark);
  font-size: 1rem;
  font-weight: 900;
  line-height: 1.3;
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
.survey-consent summary:focus-visible {
  outline: 2px solid var(--color-main-blue);
  outline-offset: 3px;
}
.survey-consent p {
  margin: 0;
  color: rgba(8, 21, 64, .68);
  font-size: .9rem;
  line-height: 1.5;
}
.survey-consent summary {
  color: var(--color-main-blue);
  font-size: .9rem;
  cursor: pointer;
}
.survey-consent details p { margin-top: .65rem; }
</style>

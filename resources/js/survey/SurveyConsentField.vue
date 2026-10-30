<script setup lang="ts">
defineProps<{ modelValue: boolean }>();
defineEmits<{ "update:modelValue": [value: boolean] }>();
</script>
<template>
  <div class="survey-consent">
    <label>
      <input type="checkbox" :checked="modelValue" @change="$emit('update:modelValue', ($event.target as HTMLInputElement).checked)">
      <span>Ja, GeoFort mag mij na ons bezoek één e-mail sturen voor een korte bezoekevaluatie.</span>
    </label>
    <p>Vrijwillig. Je keuze heeft geen invloed op je aanvraag. Je kunt je toestemming altijd intrekken via onderwijs@geofort.nl of de link in de evaluatiemail.</p>
    <details><summary>Wat doen we met je antwoorden?</summary><p>We koppelen de evaluatie aan jullie bezoek en de gevolgde modules. Het onderwijsteam gebruikt de antwoorden intern om het aanbod te verbeteren. De evaluatie is dus niet anoniem. We vragen geen gegevens van leerlingen.</p></details>
  </div>
</template>
<style scoped>
.survey-consent label { display: flex; gap: .75rem; align-items: flex-start; cursor: pointer; }
.survey-consent input { flex: 0 0 auto; width: 1.15rem; height: 1.15rem; accent-color: #123467; margin-top: .15rem; }
.survey-consent p { font-size: .9rem; line-height: 1.6; color: #465977; margin-top: .65rem; }
.survey-consent summary { cursor: pointer; color: #123467; margin-top: .7rem; }
</style>
